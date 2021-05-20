<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\MonorepoTools\Command;

use Contao\MonorepoTools\Config\MonorepoConfiguration;
use Contao\MonorepoTools\Splitter;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class SplitCommand extends Command
{
    /**
     * @var string
     */
    private $rootDir;

    public function __construct(string $rootDir)
    {
        $this->rootDir = $rootDir;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('split')
            ->addArgument('branch-or-tag', InputArgument::OPTIONAL, 'Which branch or tag should be split, defaults to all branches that match the configured branch filter.')
            ->addOption('cache-dir', null, InputOption::VALUE_REQUIRED, 'Absolute path to cache directory, defaults to .monorepo-split-cache in the project directory.')
            ->addOption('force-push', null, InputOption::VALUE_NONE, 'Force push branches (not tags) to splitted remotes. Dangerous!')
            ->setDescription('Split the monorepo into repositories by subfolder.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = (new Processor())->processConfiguration(
            new MonorepoConfiguration(),
            [
                Yaml::parse(file_get_contents(
                    file_exists($this->rootDir.'/monorepo-split.yml')
                        ? $this->rootDir.'/monorepo-split.yml'
                        : $this->rootDir.'/monorepo.yml'
                )),
            ]
        );

        foreach ($config['repositories'] as $folder => $settings) {
            $config['repositories'][$folder]['url'] = $this->addAuthToken($settings['url']);
        }

        $splitter = new Splitter(
            $this->addAuthToken($config['monorepo_url']),
            $config['branch_filter'],
            $config['repositories'],
            $input->getOption('cache-dir') ?: $this->rootDir.'/.monorepo-split-cache',
            $input->getOption('force-push'),
            $input->getArgument('branch-or-tag'),
            $output
        );

        $splitter->split();

        return 0;
    }

    private function addAuthToken($repoUrl): string
    {
        if (($token = getenv('GITHUB_TOKEN')) && 0 === strncmp($repoUrl, 'https://github.com/', 19)) {
            return 'https://'.$token.'@github.com/'.substr($repoUrl, 19);
        }

        return $repoUrl;
    }
}
