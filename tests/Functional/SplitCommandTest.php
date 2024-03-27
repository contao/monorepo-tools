<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\MonorepoTools\Tests\Functional;

use Ausi\RemoteGit\GitExecutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class SplitCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/'.uniqid('SplitCommandTest', true);
        (new Filesystem())->mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove($this->tmpDir);
    }

    public function testSplitAndComposerJsonCommand(): void
    {
        $gitDirs = [
            'foo' => Path::join($this->tmpDir, 'remote-foo.git'),
            'bar' => Path::join($this->tmpDir, 'remote-bar.git'),
        ];

        $fs = new Filesystem();
        $git = new GitExecutable();

        $fs->dumpFile(
            Path::join($this->tmpDir, 'monorepo-1', 'monorepo.yml'),
            Yaml::dump([
                'monorepo_url' => Path::join($this->tmpDir, 'monorepo-1', '.git'),
                'branch_filter' => '/^main$/',
                'composer' => [
                    'require' => ['from/mono' => '^1.0'],
                    'conflict' => ['conflict-from/mono' => '^2.0'],
                    'require-dev' => ['dev-from/mono' => '^3.0'],
                ],
                'repositories' => [
                    'bundle-foo' => [
                        'url' => $gitDirs['foo'],
                    ],
                    'bundle-bar' => [
                        'url' => $gitDirs['bar'],
                    ],
                ],
            ]),
        );

        $fs->dumpFile(Path::join($this->tmpDir, 'monorepo-1', 'bundle-foo', 'src', 'foo.txt'), 'foo');
        $fs->dumpFile(Path::join($this->tmpDir, 'monorepo-1', 'bundle-bar', 'src', 'bar.txt'), 'bar');

        $fs->dumpFile(
            Path::join($this->tmpDir, 'monorepo-1', 'bundle-foo', 'composer.json'),
            json_encode(
                [
                    'name' => 'foo',
                    'require' => [
                        'from/foo' => '^1.0',
                        'from/both' => '0.1.* || ^1.1',
                    ],
                ],
            ),
        );

        $fs->dumpFile(
            Path::join($this->tmpDir, 'monorepo-1', 'bundle-bar', 'composer.json'),
            json_encode(
                [
                    'name' => 'bar',
                    'require' => [
                        'from/bar' => '^1.2',
                        'from/both' => '^1.2 || ^2.0',
                    ],
                ],
            ),
        );

        $fs->dumpFile(
            Path::join($this->tmpDir, 'monorepo-1', 'composer.json'),
            json_encode([]),
        );

        $monoGit = ['-C', Path::join($this->tmpDir, 'monorepo-1')];

        $git->execute([...$monoGit, 'init', '--initial-branch', 'main']);
        $git->execute([...$monoGit, 'config', 'user.name', 'Mono Repo']);
        $git->execute([...$monoGit, 'config', 'user.email', 'mono@example.com']);
        $git->execute([...$monoGit, 'add', '--all']);
        $git->execute([...$monoGit, 'commit', '-m', 'Initial']);

        $git->execute(['init', '--bare', $gitDirs['foo']]);
        $git->execute(['init', '--bare', $gitDirs['bar']]);

        (new Process(
            [Path::join(__DIR__, '../../bin/monorepo-tools'), 'split', 'main'],
            Path::join($this->tmpDir, 'monorepo-1'),
        ))->mustRun();

        $this->assertSame('foo', $git->execute(['show', 'main:src/foo.txt'], $gitDirs['foo']));
        $this->assertSame('bar', $git->execute(['show', 'main:src/bar.txt'], $gitDirs['bar']));

        $fs->appendToFile(Path::join($this->tmpDir, 'monorepo-1', 'bundle-foo', 'src', 'foo.txt'), "\nadded");
        $fs->appendToFile(Path::join($this->tmpDir, 'monorepo-1', 'bundle-bar', 'src', 'bar.txt'), "\nadded");

        $git->execute([...$monoGit, 'add', '--all']);
        $git->execute([...$monoGit, 'commit', '-m', 'First change']);
        $git->execute([...$monoGit, 'branch', 'should-not-get-split']);

        (new Process(
            [Path::join(__DIR__, '../../bin/monorepo-tools'), 'split'],
            Path::join($this->tmpDir, 'monorepo-1'),
        ))->mustRun();

        $this->assertSame("foo\nadded", $git->execute(['show', 'main:src/foo.txt'], $gitDirs['foo']));
        $this->assertSame("bar\nadded", $git->execute(['show', 'main:src/bar.txt'], $gitDirs['bar']));

        $this->assertSame('main', trim($git->execute(['branch'], $gitDirs['foo']), " \n*"), 'Only configured branches should get split');
        $this->assertSame('main', trim($git->execute(['branch'], $gitDirs['bar']), " \n*"), 'Only configured branches should get split');

        (new Process(
            [Path::join(__DIR__, '../../bin/monorepo-tools'), 'composer-json'],
            Path::join($this->tmpDir, 'monorepo-1'),
        ))->mustRun();

        $this->assertSame(
            [
                'replace' => [
                    'bar' => 'self.version',
                    'foo' => 'self.version',
                ],
                'require' => [
                    'from/bar' => '^1.2',
                    'from/both' => '^1.2',
                    'from/foo' => '^1.0',
                    'from/mono' => '^1.0',
                ],
                'require-dev' => ['dev-from/mono' => '^3.0'],
                'conflict' => ['conflict-from/mono' => '^2.0'],
                'extra' => ['contao-manager-plugin' => []],
            ],
            json_decode(file_get_contents(Path::join($this->tmpDir, 'monorepo-1', 'composer.json')), true),
        );
    }
}
