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
use Contao\MonorepoTools\Git\GitObject;
use Contao\MonorepoTools\Git\Tree;
use PHPUnit\Framework\TestCase;

class CommitTest extends TestCase
{
    public function testInstantiation(): void
    {
        $this->assertInstanceOf(GitObject::class, $this->createEmptyCommit());
    }

    public function testGetHash(): void
    {
        $this->assertSame('8d7ff291d28b7f1109200d31f87a6f98fe7df90e', $this->createEmptyCommit()->getHash());
    }

    public function testGetTreeHash(): void
    {
        $this->assertSame('4b825dc642cb6eb9a060e54bf8d69288fbee4904', $this->createEmptyCommit()->getTreeHash());

        $treeHash = (new Tree("40000 foo\0".hex2bin('4b825dc642cb6eb9a060e54bf8d69288fbee4904')))->getHash();
        $this->assertSame($treeHash, $this->createEmptyCommit()->withTree($treeHash)->getTreeHash());
    }

    public function testGetParentHashes(): void
    {
        $commit = $this->createEmptyCommit();
        $this->assertSame([], $commit->getParentHashes());

        $parents = ['8d7ff291d28b7f1109200d31f87a6f98fe7df90e'];
        $commit = $commit->withParents($parents);
        $this->assertSame($parents, $commit->getParentHashes());

        $parents = ['8d7ff291d28b7f1109200d31f87a6f98fe7df90e', 'fe9315db201c025ebb2b7f464d9ebe3c4932320c'];
        $commit = $commit->withParents($parents);
        $this->assertSame($parents, $commit->getParentHashes());
    }

    public function testGetCommitterDate(): void
    {
        $commit = new Commit(
            "tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\n"
            ."committer John Doe <mail@example.com> 1532535229 +0200\n\n"
        );

        $this->assertSame(1532535229, $commit->getCommitterDate()->getTimestamp());
        $this->assertSame('2018-07-25T18:13:49+0200', $commit->getCommitterDate()->format(\DateTime::ISO8601));

        $this->expectException(\RuntimeException::class);
        $this->createEmptyCommit()->getCommitterDate();
    }

    public function testGetMessage(): void
    {
        $this->assertSame('', $this->createEmptyCommit()->getMessage());

        $message = "foo\nbar\n\nbaz";
        $commit = $this->createEmptyCommit()->withMessage($message);
        $this->assertSame($message, $commit->getMessage());
    }

    public function testHasGpgSignature(): void
    {
        $this->assertFalse($this->createEmptyCommit()->hasGpgSignature());

        $commit = new Commit(
            "tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\n"
            ."gpgsig -----BEGIN PGP SIGNATURE-----\n"
            ." ...\n"
            ." -----END PGP SIGNATURE-----\n\n"
        );

        $this->assertTrue($commit->hasGpgSignature());

        $this->assertFalse($commit->withoutGpgSignature()->hasGpgSignature());

        // Modifying the commit should remove the GPG signature
        $this->assertFalse($commit->withParents([])->hasGpgSignature());
        $this->assertFalse($commit->withMessage('test')->hasGpgSignature());
        $this->assertFalse($commit->withTree('57b5c483a5557508e419cd27c037af60217cb2ba')->hasGpgSignature());
    }

    public function testGetGitObjectBytes(): void
    {
        $this->assertSame(
            "commit 47\0tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\n\n",
            gzuncompress($this->createEmptyCommit()->getGitObjectBytes())
        );
    }

    private function createEmptyCommit(): Commit
    {
        return new Commit("tree 4b825dc642cb6eb9a060e54bf8d69288fbee4904\n\n");
    }
}
