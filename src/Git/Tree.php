<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\MonorepoTools\Git;

class Tree extends GitObject
{
    private array $entries = [];

    public function __construct(string $rawTree)
    {
        parent::__construct($rawTree);

        for (
            $offset = 0, $length = \strlen($rawTree);
            $offset < $length;
            $offset = $nextNull + 21
        ) {
            $nextSpace = strpos($rawTree, ' ', $offset);
            $nextNull = strpos($rawTree, "\0", $offset);

            if (false === $nextSpace || false === $nextNull || $nextSpace > $nextNull || $nextNull + 20 >= $length) {
                throw new \RuntimeException('Invalid tree object.');
            }

            $name = substr($rawTree, $nextSpace + 1, $nextNull - $nextSpace - 1);
            $hash = bin2hex(substr($rawTree, $nextNull + 1, 20));
            $this->entries[$name] = $hash;
        }
    }

    /**
     * @param array<static> $trees
     */
    public static function createFromTrees(array $trees): self
    {
        return new self(implode('', array_map(
            static fn (self $tree) => $tree->getRaw(),
            $trees,
        )));
    }

    public function getSubtreeHash(string $folderName): string|null
    {
        return $this->entries[$folderName] ?? null;
    }

    protected static function getGitType(): string
    {
        return 'tree';
    }
}
