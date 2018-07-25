<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\Monorepo\Git;

class Tree
{
    private $raw;

    private $entries = [];

    public function __construct(string $rawTree)
    {
        $this->raw = $rawTree;

        foreach (explode("\n", $this->raw) as $line) {
            if ($line === '') {
                continue;
            }
            $entry = explode("\t", $line, 2);
            $this->entries[$entry[1]] = substr($entry[0], -40);
        }

    }

    public function getSubtreeHash(string $folderName): ?string
    {
        return $this->entries[$folderName] ?? null;
    }
}
