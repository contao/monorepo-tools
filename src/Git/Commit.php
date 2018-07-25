<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\Monorepo\Git;

class Commit
{
    private $raw;

    private $tree;

    private $parents = [];

    public function __construct(string $rawCommit)
    {
        $this->raw = $rawCommit;

        foreach (explode("\n", $this->raw) as $line) {
            if ($line === '') {
                break;
            }
            if (strncmp($line, 'tree ', 5) === 0) {
                $this->tree = substr($line, 5, 40);
            }
            elseif (strncmp($line, 'parent ', 7) === 0) {
                $this->parents[] = substr($line, 7, 40);
            }
        }
    }

    public function getHash(): string
    {
        return sha1('commit '.strlen($this->raw)."\0".$this->raw);
    }

    public function getTreeHash(): string
    {
        return $this->tree;
    }

    public function getParentHashes(): array
    {
        return $this->parents;
    }

    public function getGitObjectBytes(): string
    {
        return gzdeflate('commit '.strlen($this->raw)."\0".$this->raw, -1, ZLIB_ENCODING_DEFLATE);
    }

    public function withNewTreeAndParents(string $tree, array $parents): self
    {
        $raw = explode("\n", $this->raw);
        foreach ($raw as $num => $line) {
            if ($line === '') {
                break;
            }
            if (strncmp($line, 'tree ', 5) === 0) {
                $raw[$num] = 'tree '.$tree;
                if (count($parents)) {
                    $raw[$num] .= "\nparent ".implode("\nparent ", $parents);
                }
            }
            elseif (strncmp($line, 'parent ', 7) === 0) {
                unset($raw[$num]);
            }
        }

        return new self(implode("\n", $raw));
    }
}
