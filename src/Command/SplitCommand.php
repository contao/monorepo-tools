<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\Monorepo\Command;

use Contao\Monorepo\Splitter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SplitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('split')
            ->setDescription('Split monorepo into repositories by subfolder.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $splitter = new Splitter(
            'git@github.com:ausi/mono-repo-test.git',
            [
                'calendar-bundle' => 'git@github.com:contao/calendar-bundle.git',
                'comments-bundle' => 'git@github.com:contao/comments-bundle.git',
                'core-bundle' => 'git@github.com:contao/core-bundle.git',
                'faq-bundle' => 'git@github.com:contao/faq-bundle.git',
                'installation-bundle' => 'git@github.com:contao/installation-bundle.git',
                'listing-bundle' => 'git@github.com:contao/listing-bundle.git',
                'manager-bundle' => 'git@github.com:contao/manager-bundle.git',
                'news-bundle' => 'git@github.com:contao/news-bundle.git',
                'newsletter-bundle' => 'git@github.com:contao/newsletter-bundle.git',
            ],
            /*
            'git@github.com:ausi/slug-generator.git',
            [
                'src' => 'x',
                'tests' => 'x',
            ],
            */
            \dirname(\dirname(__DIR__)).'/split-cache',
            $output
        );

        $splitter->split();
    }
}
