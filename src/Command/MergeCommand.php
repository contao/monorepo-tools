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

use Contao\MonorepoTools\Merger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MergeCommand extends Command
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
            ->setName('merge')
            ->setDescription('Merge multiple repositories into  one monorepo.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $merger = new Merger(
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
            [],
            $this->rootDir.'/.monorepo-merge-cache',
            $output,
        );

        $merger->merge();

        return 0;
    }
}
