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
            \dirname(__DIR__, 2).'/test-repos/mono/.git',
            /*
            [
                'faq-bundle' => \dirname(__DIR__, 2).'/test-repos/faq-bundle/.git',
                'listing-bundle' => \dirname(__DIR__, 2).'/test-repos/listing-bundle/.git',
            ],
            */
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
            [
                // Calendar
                'f814d9cb143fe1ea33ab0969df7c294c3d809530',
                'ed3c42febaf2bd3d5b402e85c9488a3a667d8a61',
                // Comments
                '2dc22646960c53a5ccf49747da94fed7bce3aeff',
                '94c695e77e0d896093a0a9cdc5a6f40961d2d710',
                // Core
                '6ae21d689a10f038553725baa5faa15a5cdc35b7',
                '83998f6f9a3fedb835911348427706de5157a37c',
                // Faq
                'a543b68804067593e911f72c82ad5f6147c386f5',
                '728306aa0899a217f9d4c914de0febcafdbaad5f',
                // Listing
                '581b17b6a6eed3fb5165150f9209212c925e868e',
                // News
                '48715f3ba9a2f4f92992c0cfcae15533b488e0ed',
                '49c24af583ca059138af303663d462a335968929',
                // Newsletter
                '179eaa9a8e502becf11848f7f169603005079d1d',
                'b2bfd3e87e9a24c6a4826170ce07a6065e0a4cd4',
            ],
            \dirname(__DIR__, 2).'/merge-cache',
            $output
        );

        $merger->merge();
    }
}
