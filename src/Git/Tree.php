<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\Monorepo\Git;

class Tree extends GitObject
{
    private $entries = [];

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
            if ($nextSpace === false || $nextNull === false || $nextSpace > $nextNull) {
                throw new \RuntimeException('Invalid tree object.');
            }

            $name = substr($rawTree, $nextSpace + 1, $nextNull - $nextSpace - 1);
            $hash = bin2hex(substr($rawTree, $nextNull + 1, 20));
            $this->entries[$name] = $hash;
        }
    }

    /**
     * @param static[] $trees
     *
     * @return static
     */
    public static function createFromTrees(array $trees): self
    {
        return new static(implode('', array_map(
            function(self $tree) {
                return $tree->getRaw();
            },
            $trees
        )));
    }

    public function getSubtreeHash(string $folderName): ?string
    {
        return $this->entries[$folderName] ?? null;
    }

    protected static function getGitType(): string
    {
        return 'tree';
    }
}
