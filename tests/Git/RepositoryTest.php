<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\MonorepoTools\Tests\Git;

use Contao\MonorepoTools\Git\Commit;
use Contao\MonorepoTools\Git\Repository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;

class RepositoryTest extends TestCase
{
    private $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/'.uniqid('RepositoryTest', true);
        (new Filesystem())->mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem())->remove($this->tmpDir);
    }

    public function testInstantiation(): void
    {
        $repository = new Repository($this->tmpDir, new NullOutput());
        $this->assertInstanceOf(Repository::class, $repository);

        $this->expectException(ProcessFailedException::class);
        $repository->getTree('4b825dc642cb6eb9a060e54bf8d69288fbee4904');
    }

    public function testInit(): void
    {
        $repository = new Repository($this->tmpDir, new NullOutput());
        $this->assertSame($repository, $repository->init());

        $this->assertSame(
            '4b825dc642cb6eb9a060e54bf8d69288fbee4904',
            $repository->getTree('4b825dc642cb6eb9a060e54bf8d69288fbee4904')->getHash()
        );
    }

    public function testSetConfig(): void
    {
        $repository = (new Repository($this->tmpDir, new NullOutput()))->init();
        $configRegExp = '/\[gc\]\s*auto\s*=\s*0/';

        $this->assertDoesNotMatchRegularExpression($configRegExp, file_get_contents($this->tmpDir.'/config'));

        $this->assertSame($repository, $repository->setConfig('gc.auto', '0'));

        $this->assertMatchesRegularExpression($configRegExp, file_get_contents($this->tmpDir.'/config'));
    }

    public function testMultiRepositorySetup(): void
    {
        $repository = (new Repository($this->tmpDir.'/repo', new NullOutput()))->init();
        $remoteA = (new Repository($this->tmpDir.'/remoteA', new NullOutput()))->init();
        $remoteB = (new Repository($this->tmpDir.'/remoteB', new NullOutput()))->init();

        $this->assertSame($repository, $repository->setConfig('user.email', 'local@example.com'));
        $this->assertSame($repository, $repository->setConfig('user.name', 'Local User'));

        $this->assertSame($repository, $repository->addRemote('remoteA', $this->tmpDir.'/remoteA'));
        $this->assertSame($repository, $repository->addRemote('remoteB', $this->tmpDir.'/remoteB'));

        $this->assertMatchesRegularExpression('/\[remote "remoteA"\]/', file_get_contents($this->tmpDir.'/repo/config'));
        $this->assertMatchesRegularExpression('/\[remote "remoteB"\]/', file_get_contents($this->tmpDir.'/repo/config'));

        $commit = $repository->getCommit(
            $repository->commitTree('4b825dc642cb6eb9a060e54bf8d69288fbee4904', 'Commit')
        );
        $commitA = new Commit(
            "tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\n"
            ."committer Remote A <a@example.com> 1234567890 +0200\n\nCommit A"
        );
        $commitB = new Commit(
            "tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\n"
            ."committer Remote B <b@example.com> 1234567890 +0200\n\nCommit B"
        );

        $this->assertSame($remoteA, $remoteA->addObject($commitA));
        $this->assertSame($remoteB, $remoteB->addObject($commitB));

        $this->assertSame($repository, $repository->addBranch('main', $commit->getHash()));
        $this->assertSame($remoteA, $remoteA->addBranch('main', $commitA->getHash()));
        $this->assertSame($remoteB, $remoteB->addBranch('main', $commitB->getHash()));

        $this->assertSame($repository, $repository->addTag('1.0.0', $commit->getHash()));
        $this->assertSame($remoteA, $remoteA->addTag('1.0.0', $commitA->getHash()));
        $this->assertSame($remoteB, $remoteB->addTag('1.0.0', $commitB->getHash()));

        $this->assertSame($repository, $repository->fetch('remoteA'));
        $this->assertSame(['main' => $commitA->getHash()], $repository->getRemoteBranches('remoteA'));

        $this->assertSame($repository, $repository->fetchConcurrent(['remoteA', 'remoteB']));
        $this->assertSame(['main' => $commitB->getHash()], $repository->getRemoteBranches('remoteB'));

        $this->assertSame($repository, $repository->fetchTag('1.0.0', 'remoteA', 'remoteA-tag/'));
        $this->assertSame($commitA->getHash(), $repository->getTag('remoteA-tag/1.0.0'));

        $this->assertSame($repository, $repository->fetchTags('remoteB', 'remoteB-tag/'));
        $this->assertSame($commitB->getHash(), $repository->getTag('remoteB-tag/1.0.0'));

        $this->assertSame(['1.0.0' => $commitB->getHash()], $repository->getTags('remoteB-tag/'));
        $this->assertSame(
            [
                '1.0.0' => $commit->getHash(),
                'remoteA-tag/1.0.0' => $commitA->getHash(),
                'remoteB-tag/1.0.0' => $commitB->getHash(),
            ],
            $repository->getTags('')
        );

        $mergeCommit = $repository->getCommit(
            $repository->commitTree(
                '4b825dc642cb6eb9a060e54bf8d69288fbee4904',
                'Commit from local',
                [
                    $commit->getHash(),
                    $commitA->getHash(),
                    $commitB->getHash(),
                ],
                true
            )
        );

        $this->assertSame(
            $commit->getCommitterDate()->format(\DateTime::ISO8601),
            $mergeCommit->getCommitterDate()->format(\DateTime::ISO8601)
        );

        $this->assertSame($repository, $repository->addBranch('main', $mergeCommit->getHash()));
        $this->assertSame($repository, $repository->removeTag('1.0.0')->addTag('1.0.0', $mergeCommit->getHash()));

        $this->assertSame($repository, $repository->pushBranch('main', 'remoteA', 'main'));
        $this->assertSame($repository, $repository->pushTag('1.0.0', 'remoteA', '1.0.0', true));
        $this->assertSame($mergeCommit->getHash(), $remoteA->getTag('1.0.0'));
        $this->assertSame(['main' => $mergeCommit->getHash()], $repository->getRemoteBranches('remoteA'));

        $this->assertSame($repository, $repository->pushBranches([['main', 'remoteB', 'main']]));
        $this->assertSame($repository, $repository->pushTags([['1.0.0', 'remoteB', '1.0.0']], true));
        $this->assertSame($mergeCommit->getHash(), $remoteB->getTag('1.0.0'));
        $this->assertSame(['main' => $mergeCommit->getHash()], $repository->getRemoteBranches('remoteB'));

        $this->assertFileExists($this->tmpDir.'/repo/refs/tags/1.0.0');
        $this->assertSame($repository, $repository->removeTag('1.0.0'));
        $this->assertFileDoesNotExist($this->tmpDir.'/repo/refs/tags/1.0.0');

        $this->assertFileExists($this->tmpDir.'/repo/refs/tags/remoteA-tag/1.0.0');
        $this->assertSame($repository, $repository->removeTags());
        $this->assertFileDoesNotExist($this->tmpDir.'/repo/refs/tags/remoteA-tag/1.0.0');

        $this->assertFileExists($this->tmpDir.'/repo/refs/heads/main');
        $this->assertSame($repository, $repository->removeBranches());
        $this->assertFileDoesNotExist($this->tmpDir.'/repo/refs/heads/main');

        $this->assertMatchesRegularExpression('/\[remote "remoteA"\]/', file_get_contents($this->tmpDir.'/repo/config'));
        $repository->removeRemote('remoteA');
        $this->assertDoesNotMatchRegularExpression('/\[remote "remoteA"\]/', file_get_contents($this->tmpDir.'/repo/config'));
    }
}
