<?php

declare(strict_types=1);

/*
 * This file is part of the Contao monorepo tools.
 *
 * (c) Martin Auswöger
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\MonorepoTools\Command;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Contao\MonorepoTools\Config\MonorepoConfiguration;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

class ComposerJsonCommand extends Command
{
    /**
     * @var string
     */
    private $rootDir;

    /**
     * @var ConfigurationInterface
     */
    private $config;

    /**
     * @var array
     */
    private $replacedPackages = [];

    public function __construct(string $rootDir)
    {
        $this->rootDir = $rootDir;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('composer-json')
            ->addOption(
                'validate',
                null,
                null,
                'Validate that the composer.json files are up to date'
            )
            ->setDescription('Merge all composer.json files into the root composer.json file and update the branch alias')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->config = (new Processor())->processConfiguration(
            new MonorepoConfiguration(),
            [
                Yaml::parse(file_get_contents(
                    file_exists($this->rootDir.'/monorepo-split.yml')
                        ? $this->rootDir.'/monorepo-split.yml'
                        : $this->rootDir.'/monorepo.yml'
                )),
            ]
        );

        $io = new SymfonyStyle($input, $output);
        $rootJson = $this->getCombinedJson();
        $splitJsons = $this->updateBranchAlias();

        if ($input->getOption('validate')) {
            $invalid = $this->validateJsons($rootJson, $splitJsons);

            if (0 === \count($invalid)) {
                $io->success('All composer.json files are up to date.');

                return 0;
            }

            $files = array_map(
                function ($path) {
                    return str_replace($this->rootDir.'/', '', $path);
                },
                array_keys($invalid)
            );

            $io->error('The following files are not up to date: '.implode(',', $files));

            foreach ($invalid as $path => $newJson) {
                $a = explode("\n", file_get_contents($path));
                $b = explode("\n", $newJson);
                $io->writeln($path);
                $io->writeln((new \Diff($a, $b))->render(new \Diff_Renderer_Text_Unified()));
            }

            return 1;
        }

        $updated = $this->updateJsons($rootJson, $splitJsons);

        if (0 === \count($updated)) {
            $io->success('All composer.json files are up to date.');

            return 0;
        }

        $files = array_map(
            function ($path) {
                return str_replace($this->rootDir.'/', '', $path);
            },
            array_keys($updated)
        );

        $io->success('The following files have been updated: '.implode(',', $files));

        return 0;
    }

    /**
     * @return array<string,array>
     */
    private function validateJsons(string $rootJson, array $splitJsons): array
    {
        $jsonsByPath = array_combine(
            array_map(
                function ($folder) {
                    return $this->rootDir.'/'.$folder.'/composer.json';
                },
                array_keys($splitJsons)
            ),
            array_values($splitJsons)
        );

        $jsonsByPath[$this->rootDir.'/composer.json'] = $rootJson;
        $invalid = [];

        foreach ($jsonsByPath as $path => $json) {
            if (json_decode(file_get_contents($path)) != json_decode($json)) {
                $invalid[$path] = $json;
            }
        }

        return $invalid;
    }

    /**
     * @return array<string,string>
     */
    private function updateJsons(string $rootJson, array $splitJsons): array
    {
        $invalid = $this->validateJsons($rootJson, $splitJsons);

        foreach ($invalid as $path => $json) {
            if (!file_put_contents($path, $json)) {
                throw new RuntimeException(sprintf('Unable to write to %s', $path));
            }
        }

        return $invalid;
    }

    private function getCombinedJson(): string
    {
        $rootJson = json_decode(file_get_contents($this->rootDir.'/composer.json'), true);

        if (file_exists($this->rootDir.'/vendor/composer/installed.json')) {
            $installedJson = json_decode(file_get_contents($this->rootDir.'/vendor/composer/installed.json'), true);

            // Composer v2
            if (isset($installedJson['packages'])) {
                $installedJson = $installedJson['packages'];
            }

            foreach ($installedJson as $package) {
                if (isset($package['replace'])) {
                    $this->replacedPackages[$package['name']] = $package['replace'];
                }
            }
        }

        $jsons = array_map(
            function ($folder) {
                $path = $this->rootDir.'/'.$folder.'/composer.json';

                if (!file_exists($path)) {
                    throw new \InvalidArgumentException(sprintf('File %s doesn’t exist.', $path));
                }

                return json_decode(file_get_contents($path), true);
            },
            array_combine(array_keys($this->config['repositories']), array_keys($this->config['repositories']))
        );

        $rootJson['replace'] = array_combine(
            array_map(
                static function ($json) {
                    return $json['name'];
                },
                $jsons
            ),
            array_map(
                static function () {
                    return 'self.version';
                },
                $jsons
            )
        );

        ksort($rootJson['replace']);

        $rootJson['require'] = $this->combineDependecies(
            array_merge(
                array_map(
                    static function ($json) {
                        return $json['require'] ?? [];
                    },
                    $jsons
                ),
                [$this->config['composer']['require'] ?? []]
            ),
            array_keys($rootJson['replace'])
        );

        $rootJson['require-dev'] = $this->combineDependecies(
            array_merge(
                array_map(
                    static function ($json) {
                        return $json['require-dev'] ?? [];
                    },
                    $jsons
                ),
                [$this->config['composer']['require-dev'] ?? []]
            ),
            array_keys($rootJson['replace'])
        );

        foreach ($rootJson['require'] as $packageName => $versionConstraint) {
            if (isset($rootJson['require-dev'][$packageName])) {
                $rootJson['require'][$packageName] = $this->combineConstraints(
                    [
                        $rootJson['require-dev'][$packageName],
                        $versionConstraint,
                    ],
                    $packageName
                );

                unset($rootJson['require-dev'][$packageName]);
            }
        }

        $rootJson['conflict'] = $this->combineDependecies(
            array_merge(
                array_map(
                    static function ($json) {
                        return $json['conflict'] ?? [];
                    },
                    $jsons
                ),
                [$this->config['composer']['conflict'] ?? []]
            ),
            array_keys($rootJson['replace'])
        );

        $rootJson['bin'] = $this->combineBins(
            array_map(
                static function ($json) {
                    return $json['bin'] ?? [];
                },
                $jsons
            )
        );

        $rootJson['extra']['contao-manager-plugin'] = $this->combineManagerPlugins($jsons);

        $rootJson['autoload'] = $this->combineAutoload(
            array_merge(
                array_map(
                    static function ($json) {
                        return $json['autoload'] ?? [];
                    },
                    $jsons
                ),
                ['' => $this->config['composer']['autoload'] ?? []]
            ),
            $rootJson['autoload'] ?? null
        );

        $rootJson['autoload-dev'] = $this->combineAutoload(
            array_merge(
                array_map(
                    static function ($json) {
                        return $json['autoload-dev'] ?? [];
                    },
                    $jsons
                ),
                ['' => $this->config['composer']['autoload-dev'] ?? []]
            ),
            $rootJson['autoload-dev'] ?? null
        );

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

        // Combine replaced packages (e.g. symfony/symfony)
        foreach ($requires as $packageName => $constraints) {
            if (!isset($this->replacedPackages[$packageName])) {
                continue;
            }

            foreach ($this->replacedPackages[$packageName] as $replacedPackageName => $versionConstraint) {
                if (isset($requires[$replacedPackageName])) {
                    $requires[$packageName] = array_merge($requires[$packageName], $requires[$replacedPackageName]);
                    unset($requires[$replacedPackageName]);
                }
            }
        }

        foreach ($requires as $packageName => $constraints) {
            $requires[$packageName] = $this->combineConstraints($constraints, $packageName);
        }

        uksort($requires, static function ($a, $b) {
            if ('php' === $a) {
                return -1;
            }

            if ('php' === $b) {
                return 1;
            }

            if (
                (0 === strncmp($a, 'ext-', 4) && 0 !== strncmp($b, 'ext-', 4))
                || (0 === strncmp($a, 'lib-', 4) && 0 !== strncmp($b, 'lib-', 4))
            ) {
                return -1;
            }

            if (
                (0 !== strncmp($a, 'ext-', 4) && 0 === strncmp($b, 'ext-', 4))
                || (0 !== strncmp($a, 'lib-', 4) && 0 === strncmp($b, 'lib-', 4))
            ) {
                return 1;
            }

            return strcmp($a, $b);
        });

        return $requires;
    }

    private function combineConstraints(array $constraints, string $name): string
    {
        $constraints = array_unique($constraints);

        return array_reduce($constraints, static function ($a, $b) use ($name) {
            if (null === $a) {
                return $b;
            }

            $versionParser = new VersionParser();
            $constraintA = $versionParser->parseConstraints($a);
            $constraintB = $versionParser->parseConstraints($b);

            if (!$constraintA->matches($constraintB)) {
                throw new RuntimeException(sprintf(
                    'Unable to combine version constraint "%s" with "%s" for %s.',
                    $a,
                    $b,
                    $name
                ));
            }

            $aParts = preg_split('/\s*\|\|?\s*/', trim($a));
            $bParts = preg_split('/\s*\|\|?\s*/', trim($b));

            foreach ($aParts as $aKey => $aPart) {
                if (!$versionParser->parseConstraints($aPart)->matches($constraintB)) {
                    unset($aParts[$aKey]);
                }
            }

            foreach ($bParts as $bKey => $bPart) {
                if (!$versionParser->parseConstraints($bPart)->matches($constraintA)) {
                    unset($bParts[$bKey]);
                }
            }

            foreach ($aParts as $aKey => $aPart) {
                foreach ($bParts as $bKey => $bPart) {
                    if (!$versionParser->parseConstraints($aPart)->matches($versionParser->parseConstraints($bPart))) {
                        continue;
                    }

                    if ($aPart === $bPart) {
                        unset($aParts[$aKey]);
                        continue 2;
                    }

                    if (preg_match('/^\^[0-9\.]+$/', $aPart) && preg_match('/^\^[0-9\.]+$/', $bPart)) {
                        if (Comparator::greaterThan(substr($aPart, 1), substr($bPart, 1))) {
                            unset($bParts[$bKey]);
                            continue;
                        }

                        unset($aParts[$aKey]);
                        continue 2;
                    }

                    if (preg_match('/^\^[0-9\.]+$/', $aPart) && preg_match('/^[0-9\.]+\.\*$/', $bPart)) {
                        unset($aParts[$aKey]);
                        continue 2;
                    }

                    if (preg_match('/^[0-9\.]+\.\*$/', $aPart) && preg_match('/^\^[0-9\.]+$/', $bPart)) {
                        unset($bParts[$bKey]);
                        continue;
                    }

                    throw new RuntimeException(sprintf(
                        'Constraint like "%s" with "%s" for %s are currently not supported.',
                        $a,
                        $b,
                        $name
                    ));
                }
            }

            return trim(implode(' || ', $aParts).' || '.implode(' || ', $bParts), ' |');
        });
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
        $plugins = array_merge(...array_map(
            static function ($json): array {
                if (!isset($json['extra']['contao-manager-plugin'])) {
                    return [];
                }

                if (\is_string($json['extra']['contao-manager-plugin'])) {
                    return [$json['name'] => $json['extra']['contao-manager-plugin']];
                }

                return $json['extra']['contao-manager-plugin'];
            },
            array_values($jsons)
        ));

        ksort($plugins);

        return $plugins;
    }

    private function combineAutoload(array $autoloadConfigs, array $currentAutoload = null): array
    {
        $returnAutoload = \is_array($currentAutoload)
            ? array_combine(array_keys($currentAutoload), array_fill(0, \count($currentAutoload), []))
            : [
                'psr-4' => [],
                'classmap' => [],
                'exclude-from-classmap' => [],
                'files' => [],
            ]
        ;

        foreach ($autoloadConfigs as $folder => $autoload) {
            if ($folder) {
                $folder .= '/';
            }

            if (isset($autoload['psr-4'])) {
                foreach ($autoload['psr-4'] as $namespace => $path) {
                    $returnAutoload['psr-4'][$namespace] = $folder.$path;
                }
            }

            if (isset($autoload['classmap'])) {
                foreach ($autoload['classmap'] as $path) {
                    $returnAutoload['classmap'][] = $folder.$path;
                }
            }

            if (isset($autoload['exclude-from-classmap'])) {
                foreach ($autoload['exclude-from-classmap'] as $path) {
                    $returnAutoload['exclude-from-classmap'][] = $folder.$path;
                }
            }

            if (isset($autoload['files'])) {
                foreach ($autoload['files'] as $path) {
                    $returnAutoload['files'][] = $folder.$path;
                }
            }
        }

        foreach ($returnAutoload as &$autoloadArray) {
            if (array_keys($autoloadArray) === range(0, \count($autoloadArray) - 1)) {
                sort($autoloadArray);
            } else {
                ksort($autoloadArray);
            }
        }

        return array_filter($returnAutoload);
    }

    private function updateBranchAlias(): array
    {
        $rootJson = json_decode(file_get_contents($this->rootDir.'/composer.json'), true);

        $jsons = array_map(
            function ($folder) {
                $path = $this->rootDir.'/'.$folder.'/composer.json';

                if (!file_exists($path)) {
                    throw new \InvalidArgumentException(sprintf('File %s doesn’t exist.', $path));
                }

                return json_decode(file_get_contents($path), true);
            },
            array_combine(array_keys($this->config['repositories']), array_keys($this->config['repositories']))
        );

        $jsons = array_map(
            static function ($json) use ($rootJson) {
                if (isset($rootJson['extra']['branch-alias'])) {
                    $json['extra']['branch-alias'] = $rootJson['extra']['branch-alias'];
                } elseif (isset($json['extra']['branch-alias'])) {
                    unset($json['extra']['branch-alias']);
                }

                return $json;
            },
            $jsons
        );

        return array_map(
            static function ($json) {
                return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
            },
            $jsons
        );
    }
}
