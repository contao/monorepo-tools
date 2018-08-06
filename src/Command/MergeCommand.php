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
                /*
                // Listing
                'dea5b1bf4913dfc67961b2352936fcf8abbf88fb',
                'e4fe9c298575eefdef2b4a4db00618293be1186c',
                '3c0f4c8996fed49048c3eb75c4515eb7c21dc5f6',
                'd064268aceb6ff66c053c0045911466879b755fe',
                // Faq
                'cef918e98ef0c42afa8745e9b3901b5c9b276169',
                '238104fcd5af740883096914c5e12afcbc81c107',
                '72417c996b78f139d35488a2589e91ce049a868e',
                '332b5d7e98c406814a7b734c766c29bd5d4f4fba',
                'ce9f2b63c397f82079faa2c473fc923555ad0f90',
                */
            ],
            \dirname(__DIR__, 2).'/merge-cache',
            $output
        );

        $merger->merge();
    }
}
