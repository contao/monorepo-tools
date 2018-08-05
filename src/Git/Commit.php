<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\Monorepo\Git;

class Commit extends GitObject
{
    private $tree;

    private $parents = [];

    public function __construct(string $rawCommit)
    {
        parent::__construct($rawCommit);

        foreach (explode("\n", $this->getRaw()) as $line) {
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

    public function getTreeHash(): string
    {
        return $this->tree;
    }

    public function getParentHashes(): array
    {
        return $this->parents;
    }

    public function getCommitterDate(): \DateTime
    {
        foreach (explode("\n", $this->getRaw()) as $line) {
            if ($line === '') {
                break;
            }
            if (strncmp($line, 'committer ', 10) === 0) {
                $parts = explode(' ', $line);
                return \DateTime::createFromFormat(
                    'U',
                    $parts[\count($parts) - 2],
                    new \DateTimeZone($parts[\count($parts) - 1])
                );
            }
        }

        throw new \RuntimeException('Missing committer date.');
    }

    public function withNewTreeAndParents(string $tree, array $parents): self
    {
        $raw = explode("\n", $this->getRaw());
        foreach ($raw as $num => $line) {
            if ($line === '') {
                break;
            }
            if (strncmp($line, 'tree ', 5) === 0) {
                $raw[$num] = 'tree '.$tree;
                if (\count($parents)) {
                    $raw[$num] .= "\nparent ".implode("\nparent ", $parents);
                }
            }
            elseif (strncmp($line, 'parent ', 7) === 0) {
                unset($raw[$num]);
            }
        }

        return new self(implode("\n", $raw));
    }

    protected static function getGitType(): string
    {
        return 'commit';
    }
}
