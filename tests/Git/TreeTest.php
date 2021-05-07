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

use Contao\MonorepoTools\Git\GitObject;
use Contao\MonorepoTools\Git\Tree;
use PHPUnit\Framework\TestCase;

class TreeTest extends TestCase
{
    public function testInstantiation(): void
    {
        $tree = new Tree('');
        $this->assertInstanceOf(GitObject::class, $tree);
    }

    public function testGetHash(): void
    {
        $tree = new Tree('');
        $this->assertSame('4b825dc642cb6eb9a060e54bf8d69288fbee4904', $tree->getHash());

        $tree = new Tree("100644 foo\0".hex2bin('4b825dc642cb6eb9a060e54bf8d69288fbee4904'));
        $this->assertSame('04d900f201d971b8413abc9cb3ca2bce63cf88e0', $tree->getHash());
    }

    public function testGetSubtreeHash(): void
    {
        $randomTree = random_bytes(20);

        $tree = new Tree(
            "40000 foo\0".hex2bin('4b825dc642cb6eb9a060e54bf8d69288fbee4904')
            ."40000 bar\0".$randomTree
        );

        $this->assertSame('4b825dc642cb6eb9a060e54bf8d69288fbee4904', $tree->getSubtreeHash('foo'));
        $this->assertSame(bin2hex($randomTree), $tree->getSubtreeHash('bar'));
    }

    public function testCreateFromTrees(): void
    {
        $tree = Tree::createFromTrees([
            new Tree("40000 foo\0".hex2bin('4b825dc642cb6eb9a060e54bf8d69288fbee4904')),
            new Tree("40000 bar\0".hex2bin('4b825dc642cb6eb9a060e54bf8d69288fbee4904')),
        ]);

        $this->assertSame('4b825dc642cb6eb9a060e54bf8d69288fbee4904', $tree->getSubtreeHash('foo'));
        $this->assertSame('4b825dc642cb6eb9a060e54bf8d69288fbee4904', $tree->getSubtreeHash('bar'));
    }

    /**
     * @dataProvider getInvalidTrees
     */
    public function testInvalidTrees(string $rawTree): void
    {
        $this->expectExceptionMessage('Invalid tree object.');

        new Tree($rawTree);
    }

    public function getInvalidTrees()
    {
        yield [' '];
        yield ['invalid'];
        yield ["123 foo\0".'0123456789012345678'];
        yield ["123foo\0".'01234567890123456789'];
        yield ['123 foo'.'01234567890123456789'];
    }
}
