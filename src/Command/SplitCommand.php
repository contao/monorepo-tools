<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\MonorepoTools\Command;

use Contao\MonorepoTools\Config\SplitConfiguration;
use Contao\MonorepoTools\Splitter;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class SplitCommand extends Command
{
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
            ->setDescription('Split monorepo into repositories by subfolder.')
            ->addArgument(
                'branch',
                InputArgument::OPTIONAL,
                'Which branch should be split, defaults to all branches.'
            )
            ->addOption(
                'force-push',
                null,
                null,
                'Force push branches and tags to splitted remotes. Dangerous!'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $config = (new Processor())->processConfiguration(
            new SplitConfiguration(),
            [Yaml::parse(file_get_contents($this->rootDir.'/monorepo-split.yml'))]
        );

        $splitter = new Splitter(
            $config['monorepo_url'],
            $config['repositories'],
            $this->rootDir.'/.monorepo-split-cache',
            $input->getOption('force-push'),
            $input->getArgument('branch'),
            $output
        );

        $splitter->split();
    }
}
