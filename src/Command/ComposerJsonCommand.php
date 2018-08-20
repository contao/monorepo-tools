<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\MonorepoTools\Command;

use Contao\MonorepoTools\Config\MonorepoConfiguration;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class ComposerJsonCommand extends Command
{
    private $rootDir;
    private $config;
    private $composerFilePaths;
    private $replacedPackages;

    public function __construct(string $rootDir)
    {
        $this->rootDir = $rootDir;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('composer-json')
            ->setDescription('Merge all composer.json files into the root composer.json and update the branch-alias.')
            ->addOption(
                'validate',
                null,
                null,
                'Validate if the composer.json files are already up to date.'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $this->config = (new Processor())->processConfiguration(
            new MonorepoConfiguration(),
            [Yaml::parse(file_get_contents(
                file_exists($this->rootDir.'/monorepo-split.yml')
                    ? $this->rootDir.'/monorepo-split.yml'
                    : $this->rootDir.'/monorepo.yml'
            ))]
        );

        $rootJson = $this->getCombinedJson();
        $splitJsons = $this->updateBranchAlias();

        if ($input->getOption('validate')) {
            $jsonPaths = $this->validateJsons($rootJson, $splitJsons);
            foreach($jsonPaths as $path) {
                $output->writeln('Validated '.$path);
            }
        }
        else {
            $jsonPaths = $this->updateJsons($rootJson, $splitJsons);
            foreach($jsonPaths as $path) {
                $output->writeln('Updated '.$path);
            }
        }
    }

    public function validateJsons(string $rootJson, array $splitJsons): array
    {
        $jsonsByPath = array_combine(
            array_map(function($folder) {
                return $this->rootDir.'/'.$folder.'/composer.json';
            }, array_keys($splitJsons)),
            array_values($splitJsons)
        );
        $jsonsByPath[$this->rootDir.'/composer.json'] = $rootJson;

        $valid = [];
        $invalid = [];

        foreach ($jsonsByPath as $path => $json) {
            if (json_decode(file_get_contents($path)) == json_decode($json)) {
                $valid[] = $path;
            }
            else {
                $invalid[] = $path;
            }
        }

        if (\count($invalid)) {
            throw new RuntimeException(sprintf(
                "composer.json not up to date:\n%s",
                implode("\n", $invalid)
            ));
        }

        return $valid;
    }

    private function updateJsons(string $rootJson, array $splitJsons): array
    {
        $jsonsByPath = array_combine(
            array_map(function($folder) {
                return $this->rootDir.'/'.$folder.'/composer.json';
            }, array_keys($splitJsons)),
            array_values($splitJsons)
        );
        $jsonsByPath[$this->rootDir.'/composer.json'] = $rootJson;

        foreach ($jsonsByPath as $path => $json) {
            if (!file_put_contents($path, $json)) {
                throw new RuntimeException(
                    sprintf('Unable to write to %s', $path)
                );
            }
        }

        return array_keys($jsonsByPath);
    }

    private function getCombinedJson(): string
    {
        $rootJson = json_decode(file_get_contents($this->rootDir.'/composer.json'), true);

        $this->replacedPackages = [];
        if (file_exists($this->rootDir.'/vendor/composer/installed.json')) {
            $installedJson = json_decode(file_get_contents($this->rootDir.'/vendor/composer/installed.json'), true);
            foreach ($installedJson as $package) {
                if (isset($package['replace'])) {
                    $this->replacedPackages[$package['name']] = $package['replace'];
                }
            }
        }

        $jsons = array_map(function($folder) {
            $path = $this->rootDir.'/'.$folder.'/composer.json';
            if (!file_exists($path)) {
                throw new \InvalidArgumentException(
                    sprintf('File %s doesn’t exist.', $path)
                );
            }
            return json_decode(file_get_contents($path), true);
        }, array_combine(
            array_keys($this->config['repositories']),
            array_keys($this->config['repositories'])
        ));

        $rootJson['replace'] = array_combine(
            array_map(function($json) {
                return $json['name'];
            }, $jsons),
            array_map(function() {
                return 'self.version';
            }, $jsons)
        );

        $rootJson['require'] = $this->combineDependecies(array_merge(
            array_map(function($json) {
                return $json['require'] ?? [];
            }, $jsons),
            [$this->config['composer']['require'] ?? []]
        ), array_keys($rootJson['replace']));

        $rootJson['require-dev'] = $this->combineDependecies(array_merge(
            array_map(function($json) {
                return $json['require-dev'] ?? [];
            }, $jsons),
            [$this->config['composer']['require-dev'] ?? []]
        ), array_keys($rootJson['replace']));

        foreach ($rootJson['require'] as $packageName => $versionConstraint) {
            if (isset($rootJson['require-dev'][$packageName])) {
                $rootJson['require'][$packageName] = $this->combineConstraints([
                    $rootJson['require-dev'][$packageName],
                    $versionConstraint,
                ]);
                unset($rootJson['require-dev'][$packageName]);
            }
        }

        $rootJson['conflict'] = $this->combineDependecies(array_map(function($json) {
            return $json['conflict'] ?? [];
        }, $jsons), array_keys($rootJson['replace']));

        $rootJson['bin'] = $this->combineBins(array_map(function($json) {
            return $json['bin'] ?? [];
        }, $jsons));

        $rootJson['extra']['contao-manager-plugin'] = $this->combineManagerPlugins($jsons);

        $rootJson['autoload'] = $this->combineAutoload(array_map(function($json) {
            return $json['autoload'] ?? [];
        }, $jsons), $rootJson['autoload'] ?? null);

        $rootJson['autoload-dev'] = $this->combineAutoload(array_map(function($json) {
            return $json['autoload-dev'] ?? [];
        }, $jsons), $rootJson['autoload-dev'] ?? null);

        return json_encode($rootJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    private function combineDependecies(array $requireArrays, array $ignorePackages = []): array
    {
        $requires = [];
        foreach ($requireArrays as $require) {
            foreach ($require as $packageName => $versionConstraint) {
                if (\in_array($packageName, $ignorePackages, true)) {
                    continue;
                }
                $requires[$packageName][] = $versionConstraint;
            }
        }

        // Unset replaced packages (e.g. symfony/http-cache is replaced by symfony/symfony)
        foreach ($requires as $packageName => $constraints) {
            if (!isset($this->replacedPackages[$packageName])) {
                continue;
            }
            foreach (array_keys($this->replacedPackages[$packageName]) as $replacedPackageName) {
                unset($requires[$replacedPackageName]);
            }
        }

        foreach ($requires as $packageName => $constraints) {
            $requires[$packageName] = $this->combineConstraints($constraints);
        }

        uksort($requires, function($a, $b) {

            if ($a === 'php') {
                return -1;
            }
            if ($b === 'php') {
                return 1;
            }

            if (
                (strncmp($a, 'ext-', 4) === 0 && strncmp($b, 'ext-', 4) !== 0)
                || (strncmp($a, 'lib-', 4) === 0 && strncmp($b, 'lib-', 4) !== 0)
            ) {
                return -1;
            }
            if (
                (strncmp($a, 'ext-', 4) !== 0 && strncmp($b, 'ext-', 4) === 0)
                || (strncmp($a, 'lib-', 4) !== 0 && strncmp($b, 'lib-', 4) === 0)
            ) {
                return 1;
            }

            return strcmp($a, $b);
        });

        return $requires;
    }

    private function combineConstraints(array $constraints): string
    {
        $constraints = array_unique($constraints);
        $caretVersions = [];

        foreach ($constraints as $key => $constraint) {
            if (preg_match('/^\^[0-9\.]+$/', $constraint)) {
                $caretVersions[] = substr($constraint, 1);
                unset($constraints[$key]);
            }
        }

        if (\count($caretVersions)) {
            usort($caretVersions, 'version_compare');
            array_unshift($constraints, '^'.array_values(\array_slice($caretVersions, -1))[0]);
        }

        return implode(' ', $constraints);
    }

    private function combineBins(array $binaryPaths): array
    {
        $returnPaths = [];
        foreach ($binaryPaths as $folder => $paths) {
            foreach ($paths as $path) {
                $returnPaths[] = $folder.'/'.$path;
            }
        }

        return $returnPaths;
    }

    private function combineManagerPlugins(array $jsons): array
    {
        return call_user_func_array('array_merge', array_map(function($json): array {
            if (!isset($json['extra']['contao-manager-plugin'])) {
                return [];
            }
            if (\is_string($json['extra']['contao-manager-plugin'])) {
                return [$json['name'] => $json['extra']['contao-manager-plugin']];
            }
            return $json['extra']['contao-manager-plugin'];
        }, $jsons));
    }

    private function combineAutoload(array $autoloadConfigs, array $currentAutoload = null): array
    {
        $returnAutoload = \is_array($currentAutoload)
            ? array_combine(
                array_keys($currentAutoload),
                array_fill(0, \count($currentAutoload), [])
            )
            : [
                'psr-4' => [],
                'classmap' => [],
                'exclude-from-classmap' => [],
                'files' => [],
            ]
        ;

        foreach ($autoloadConfigs as $folder => $autoload) {
            if (isset($autoload['psr-4'])) {
                foreach ($autoload['psr-4'] as $namespace => $path) {
                    $returnAutoload['psr-4'][$namespace] = $folder.'/'.$path;
                }
            }
            if (isset($autoload['classmap'])) {
                foreach ($autoload['classmap'] as $path) {
                    $returnAutoload['classmap'][] = $folder.'/'.$path;
                }
            }
            if (isset($autoload['exclude-from-classmap'])) {
                foreach ($autoload['exclude-from-classmap'] as $path) {
                    $returnAutoload['exclude-from-classmap'][] = $folder.'/'.$path;
                }
            }
            if (isset($autoload['files'])) {
                foreach ($autoload['files'] as $path) {
                    $returnAutoload['files'][] = $folder.'/'.$path;
                }
            }
        }

        foreach($returnAutoload as &$autoloadArray) {
            if (array_keys($autoloadArray) === range(0, \count($autoloadArray) - 1)) {
                sort($autoloadArray);
            }
            else {
                ksort($autoloadArray);
            }
        }

        return array_filter($returnAutoload);
    }

    private function updateBranchAlias(): array
    {
        $rootJson = json_decode(file_get_contents($this->rootDir.'/composer.json'), true);

        $jsons = array_map(function($folder) {
            $path = $this->rootDir.'/'.$folder.'/composer.json';
            if (!file_exists($path)) {
                throw new \InvalidArgumentException(
                    sprintf('File %s doesn’t exist.', $path)
                );
            }
            return json_decode(file_get_contents($path), true);
        }, array_combine(
            array_keys($this->config['repositories']),
            array_keys($this->config['repositories'])
        ));

        $jsons = array_map(function($json) use($rootJson) {
            if (isset($rootJson['extra']['branch-alias'])) {
                $json['extra']['branch-alias'] = $rootJson['extra']['branch-alias'];
            }
            elseif (isset($json['extra']['branch-alias'])) {
                unset($json['extra']['branch-alias']);
            }
            return $json;
        }, $jsons);

        return array_map(function($json) {
            return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        }, $jsons);
    }
}
