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
        $splitter = new Splitter(
            \dirname(__DIR__, 2).'/test-repos/mono/.git',
            [
                'calendar-bundle' => [
                    'url' => \dirname(__DIR__, 2).'/test-repos/calendar-bundle/.git',
                    'mapping' => [
                        'ec3ec47e6bbb67ae000f2deb22e33889ba926cc0' => '8a7db350796008706a4652d2ea5b75ead68df78b',
                        '6e2e1d4a139941454b83dfe98b99b9ad8288ffb9' => '839c675d5057c73830f149931b74632f274e1e4d',
                        '442278318f971ce9c5d6c069a31fe4d2ceef6db6' => '35c16cd178f6376c02759800684d9d7af1d7fccf',
                        'f4fc6ea3784a60fa596423fab04db9ab389add50' => 'c044c888cd47fd62d688dac3ec33f01c50dad18d',
                    ],
                ],
                'comments-bundle' => [
                    'url' => \dirname(__DIR__, 2).'/test-repos/comments-bundle/.git',
                    'mapping' => [
                        'af1d827974023d0785b881c3098b2ef12bca1d64' => '46b7772421f0ef6e0363455204813b60bdbbefb9',
                        'b19b68cbbf1dcb84a47087cb77d673f82d382c0b' => '86ba472ed20bf20d85f687b17339eb0d5397f125',
                        '129a5788a44c99972c6ca0b30f9ebea24abb364e' => '29dd1f4c5d3fcb91b7c9349cfb97d9da6a9afbeb',
                        'c2500886dfbb0cf9ea3d78dac740fa15ac893ad4' => 'e539c80fd40b3a49283711774ece3998771e302d',
                    ],
                ],
                'core-bundle' => [
                    'url' => \dirname(__DIR__, 2).'/test-repos/core-bundle/.git',
                    'mapping' => [
                        '2954565ebe566b796330d1ca41a17b0542f31ea7' => 'cd9e78134eeca4bfb17c03664efd5817984b662f',
                        '76068a3804173a524bdc294049e9ac8b31b1bd8a' => '0bc0e9311479da93475419e7ecd90938ef708adb',
                        '72c12287d1c5c43c3784aa716758cee2da141145' => 'ac2fed64436013d2aa39301436c08fde4fe0b61f',
                        '40eaa8153fa2da22e77a4e3f9fb62323c4466879' => '0e6ace663e45bf4e120b164e9db1c8109d9087b7',
                    ],
                ],
                'faq-bundle' => [
                    'url' => \dirname(__DIR__, 2).'/test-repos/faq-bundle/.git',
                    'mapping' => [
                        '7ad0472f21b67e573d5e703c0333d07d2c3d5be0' => 'e950be863a494a32acf00008ee4e679064e4f819',
                        '9991d38fdeb01f8d1a1e06b1046e3c80fed920ce' => '24817b536f0e9a8651f1b4420e602a4097d60ae4',
                        'fde69db6829e45d35f5651fb9a35767b0536d44a' => '76d4774fd83d4f328530287d92758e3922cc34ba',
                        '5d949db452e9b8c94bec84599a92dd75971ab629' => '1bda612914a2c246367ee7a83e73656bb0d22676',
                    ],
                ],
                'installation-bundle' => [
                    'url' => \dirname(__DIR__, 2).'/test-repos/installation-bundle/.git',
                    'mapping' => [
                        '55960bc1755c85720a0bd417fe0c910a21632f5c' => 'f61dad19b521fc821a55f9d0dd92d1062cab1163',
                        'b2e73c544d291f9dc7becd3a9dd011302ccad951' => '17a155bb0412e69b5161b467b100ea202189e63e',
                        '34b4e379aff5742bcc0fb84cdc428bc750f74ac6' => '544c61d42ce831e571fe24e67692b3d447dc5233',
                        '110eb5f0ca97fa5a89cc81d8d6b92e97f2fa0758' => '28a2a56e599d52c9b803b8e1a89015714bf2b21c',
                    ],
                ],
                'listing-bundle' => [
                    'url' => \dirname(__DIR__, 2).'/test-repos/listing-bundle/.git',
                    'mapping' => [
                        '204bd02c1eb0e58e9876f93524830d7d2fe8c480' => '526c543adbaf54208efb905b6ff0d00ad48d1832',
                        '2d02c5291348db56bc56e33c7acc3d0e4f596e3c' => '6e182af3ab990e186e4208ba806dd43cb3b9e530',
                        'bb2ac83c2f3ab8490850c12d1cead034aabbb3fa' => 'bb713b10ef98dcaf52395e2cef8f4b7f682faec5',
                        '18a38903a82e2dfe0faf11b6ca421fd6a63f79fc' => '51c69f1089e0f39fb6bfd7fb71cf2430ef5bde01',
                    ],
                ],
                'manager-bundle' => [
                    'url' => \dirname(__DIR__, 2).'/test-repos/manager-bundle/.git',
                    'mapping' => [
                        'bd616be047a4c383477304e8f5be0ea2c73370c1' => '366932461a69e7e2d2133dc5a474347497431b25',
                        'f5bff6fd4fd4e83195194a7cb0a3d8d309007660' => '16e2647cdb3ad27acf2858380ff71ce5b80e28e8',
                        'b6d78f72d2c819f1163e2c7edf551ca4e7e98006' => '316cf7501d0f57662beea455f9b36533a44d84f2',
                        '414e531a9b47add73f1e16c665b9185bfebd8e84' => '25c0eae5691467728c73dbb936b028a2cce0bf9f',
                        '24a32cfdc5ae68bae180235de0b9c9c8750abe25' => 'a702c8a639addf6341da003359a5e3cd2d7e5be7',
                        '28c19289f6393744480311b5b599f5d08d82359c' => '4bc56a8d0960a51c7119e2eebdb08b968e582da0',
                    ],
                ],
                'news-bundle' => [
                    'url' => \dirname(__DIR__, 2).'/test-repos/news-bundle/.git',
                    'mapping' => [
                        'fa3e2caf75896b08304c190d39c667d8f22c2134' => '9c4bf9b5236ab5084bcb51c1525d6cb1294e183c',
                        'f90a6a9e58d86fa8aab431e922b7fc24a033ae34' => '5f63cd3b7cf64300bd2814be55e1fe0266585a2e',
                        '1d1a33d51a2ff560cfce8905ef078a9f5b306e60' => '1f8105a5e94d9995bde75e95ca69c0cc61ae0896',
                        '80786c5219d341ce2a20389607daeda91104a076' => '851bba0e84cdc5e126ef4f72697c60534a2c8300',
                    ],
                ],
                'newsletter-bundle' => [
                    'url' => \dirname(__DIR__, 2).'/test-repos/newsletter-bundle/.git',
                    'mapping' => [
                        '67e2c8a7f00cf4307d75dba0019ebd94c4e42d55' => 'ad1b57e5ce66857dfb16c8057b86d80e8316fa3d',
                        '959b4ddbe93ea93f89303de7175bbc2be9eb6dbe' => '4cc7cab43e1978b3fe60094bacc0a52f84ae35fe',
                        '19864e68ead2bdf048b2001773eabb569be8cf61' => '1316010dce8b0204091137e23c3ae850f116f5fb',
                        'c307b0654ad1fcce3a5fa25248604d0118465d65' => '5ad35c1360dc582289506553e55f21cd3823c621',
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
            $input->getOption('force-push'),
            $output
        );

        $splitter->split();
    }
}
