<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\Monorepo\Command;

use Contao\Monorepo\Merger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MergeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('merge')
            ->setDescription('Merge multiple repositories into  one monorepo.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $merger = new Merger(
            \dirname(\dirname(__DIR__)).'/test-repos/mono/.git',
            /*[
                'faq-bundle' => \dirname(\dirname(__DIR__)).'/test-repos/faq-bundle/.git',
                'listing-bundle' => \dirname(\dirname(__DIR__)).'/test-repos/listing-bundle/.git',
            ],*/
            [
                'sub-a' => 'git@github.com:ausi/slug-generator.git',
                'sub-b' => 'git@github.com:ausi/slug-generator.git',
            ],
            \dirname(\dirname(__DIR__)).'/merge-cache',
            $output
        );

        $merger->merge();
    }
}
