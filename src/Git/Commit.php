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

class Commit extends GitObject
{
    private string $tree;

    private array $parents = [];

    public function __construct(string $rawCommit)
    {
        parent::__construct($rawCommit);

        foreach (explode("\n", $this->getRaw()) as $line) {
            if ('' === $line) {
                break;
            }

            if (str_starts_with($line, 'tree ')) {
                $this->tree = substr($line, 5, 40);
            } elseif (str_starts_with($line, 'parent ')) {
                $this->parents[] = substr($line, 7, 40);
            }
        }
    }

    public function getTreeHash(): string
    {
        return $this->tree;
    }

    /**
     * @return array<string>
     */
    public function getParentHashes(): array
    {
        return $this->parents;
    }

    public function getCommitterDate(): \DateTime
    {
        foreach (explode("\n", $this->getRaw()) as $line) {
            if ('' === $line) {
                break;
            }

            if (str_starts_with($line, 'committer ')) {
                $parts = explode(' ', $line);

                $date = \DateTime::createFromFormat(
                    'U',
                    $parts[\count($parts) - 2],
                );

                $date->setTimezone(new \DateTimeZone($parts[\count($parts) - 1]));

                return $date;
            }
        }

        throw new \RuntimeException('Missing committer date.');
    }

    public function getMessage(): string
    {
        $raw = $this->getRaw();
        $messageOffset = strpos($raw, "\n\n");

        if (false === $messageOffset) {
            throw new \RuntimeException('Missing commit message.');
        }

        return substr($raw, $messageOffset + 2);
    }

    public function hasGpgSignature(): bool
    {
        $raw = explode("\n", $this->getRaw());

        foreach ($raw as $line) {
            if ('' === $line) {
                break;
            }

            if (str_starts_with($line, 'gpgsig ')) {
                return true;
            }
        }

        return false;
    }

    public function withMessage(string $message): self
    {
        $raw = $this->withoutGpgSignature()->getRaw();
        $messageOffset = strpos($raw, "\n\n");

        if (false === $messageOffset) {
            throw new \RuntimeException('Missing commit message.');
        }

        return new self(substr($raw, 0, $messageOffset)."\n\n".$message);
    }

    public function withTree(string $hash): self
    {
        $raw = explode("\n", $this->withoutGpgSignature()->getRaw());

        foreach ($raw as $num => $line) {
            if ('' === $line) {
                break;
            }

            if (str_starts_with($line, 'tree ')) {
                $raw[$num] = 'tree '.$hash;
                break;
            }
        }

        return new self(implode("\n", $raw));
    }

    public function withParents(array $hashes): self
    {
        $raw = explode("\n", $this->withoutGpgSignature()->getRaw());

        foreach ($raw as $num => $line) {
            if ('' === $line) {
                break;
            }

            if (str_starts_with($line, 'tree ')) {
                if ([] !== $hashes) {
                    $raw[$num] .= "\nparent ".implode("\nparent ", $hashes);
                }
            } elseif (str_starts_with($line, 'parent ')) {
                unset($raw[$num]);
            }
        }

        return new self(implode("\n", $raw));
    }

    public function withoutGpgSignature(): self
    {
        if (!$this->hasGpgSignature()) {
            return $this;
        }

        $raw = explode("\n", $this->getRaw());
        $signatureStartFound = false;

        foreach ($raw as $num => $line) {
            if ('' === $line) {
                break;
            }

            if ($signatureStartFound && ' ' === $line[0]) {
                unset($raw[$num]);
                continue;
            }

            $signatureStartFound = false;

            if (str_starts_with($line, 'gpgsig ')) {
                $signatureStartFound = true;
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
