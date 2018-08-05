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
            \dirname(__DIR__, 2).'/test-repos/mono/.git',
            [
                'faq-bundle' => [
                    'url' => \dirname(__DIR__, 2).'/test-repos/faq-bundle/.git',
                    'mapping' => [
                        '8ef6a4a749df0590a100b5e1a83a9da507df8410' => 'e950be863a494a32acf00008ee4e679064e4f819',
                        'db9f986daa85b678cdaa8753073c216eba4c4e3d' => '24817b536f0e9a8651f1b4420e602a4097d60ae4',
                        '6ca4f5dc5e75e5ec698818839505597cc83b4c51' => '76d4774fd83d4f328530287d92758e3922cc34ba',
                        'bd7b95e6ab04233acacdfdca2428d120b0c5040a' => '08ad69e1bf9564888e704f0eccd8f71714afd124',
                    ],
                ],
                'listing-bundle' => [
                    'url' => \dirname(__DIR__, 2).'/test-repos/listing-bundle/.git',
                    'mapping' => [
                        '4adc88c8b591d6e055e21c19348b8ef9c4f4530a' => '526c543adbaf54208efb905b6ff0d00ad48d1832',
                        '95c504351403d0bdf8e49ff20db650027c613985' => '6e182af3ab990e186e4208ba806dd43cb3b9e530',
                        '7798c61b2cfdfe4bfa4352e2b749eacebc8349c3' => 'bb713b10ef98dcaf52395e2cef8f4b7f682faec5',
                        'f6d70d160b6f425c35ebed306f70961a5a96aecc' => '31685e7cbe11769a4e946b4867ce70cd5847667e',
                    ],
                ],
            ],
            /*
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
            */
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
