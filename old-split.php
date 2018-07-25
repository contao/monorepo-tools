<?php

require __DIR__.'/vendor/autoload.php';

use Contao\Monorepo\Git\Tree;
use Contao\Monorepo\Git\Commit;
use Contao\Monorepo\Splitter;

if (file_exists(__DIR__.'/old-cache.data')) {
    $GLOBALS['cache'] = unserialize(file_get_contents(__DIR__.'/old-cache.data'));
}
else {
    $GLOBALS['cache'] = [
        'commits' => [],
        'trees' => [],
    ];
}

$GLOBALS['newCommits'] = [];

$splitter = new Splitter(
'git@github.com:ausi/mono-repo-test.git', [
    'calendar-bundle' => 'git@github.com:contao/calendar-bundle.git',
    'comments-bundle' => 'git@github.com:contao/comments-bundle.git',
    'core-bundle' => 'git@github.com:contao/core-bundle.git',
    'faq-bundle' => 'git@github.com:contao/faq-bundle.git',
    'installation-bundle' => 'git@github.com:contao/installation-bundle.git',
    'listing-bundle' => 'git@github.com:contao/listing-bundle.git',
    'manager-bundle' => 'git@github.com:contao/manager-bundle.git',
    'news-bundle' => 'git@github.com:contao/news-bundle.git',
    'newsletter-bundle' => 'git@github.com:contao/newsletter-bundle.git',
]
/*
'git@github.com:ausi/slug-generator.git', [
    'src' => 'x',
    'tests' => 'x',
]
*/
);

$splitter->splitRepos();

file_put_contents(__DIR__.'/old-cache.data', serialize($GLOBALS['cache']));
