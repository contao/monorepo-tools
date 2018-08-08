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

    public function getMessage(): string
    {
        $raw = $this->getRaw();
        $messageOffset = strpos($raw, "\n\n");

        if ($messageOffset === false) {
            throw new \RuntimeException('Missing commit message.');
        }

        return substr($raw, $messageOffset + 2);
    }

    public function withMessage(string $message): self
    {
        $raw = $this->getRaw();
        $messageOffset = strpos($raw, "\n\n");

        if ($messageOffset === false) {
            throw new \RuntimeException('Missing commit message.');
        }

        return new self(
            substr($raw, 0, $messageOffset)
            ."\n\n"
            .$message
        );
    }

    public function withTree(string $hash): self
    {
        $raw = explode("\n", $this->getRaw());
        foreach ($raw as $num => $line) {
            if ($line === '') {
                break;
            }
            if (strncmp($line, 'tree ', 5) === 0) {
                $raw[$num] = 'tree ' . $hash;
                break;
            }
        }

        return new self(implode("\n", $raw));
    }

    public function withParents(array $hashes): self
    {
        $raw = explode("\n", $this->getRaw());
        foreach ($raw as $num => $line) {
            if ($line === '') {
                break;
            }
            if (strncmp($line, 'tree ', 5) === 0) {
                if (\count($hashes)) {
                    $raw[$num] .= "\nparent ".implode("\nparent ", $hashes);
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
