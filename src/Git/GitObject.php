<?php

declare(strict_types=1);

/*
 * This file is part of the Contao monorepo tools.
 *
 * (c) Martin Auswöger
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\MonorepoTools\Git;

abstract class GitObject
{
    /**
     * @var string
     */
    private $raw;

    public function __construct(string $rawData)
    {
        $this->raw = $rawData;
    }

    public function getHash(): string
    {
        return sha1(static::getGitType().' '.\strlen($this->raw)."\0".$this->raw);
    }

    public function getGitObjectBytes(): string
    {
        return gzdeflate(static::getGitType().' '.\strlen($this->raw)."\0".$this->raw, -1, ZLIB_ENCODING_DEFLATE);
    }

    abstract protected static function getGitType(): string;

    protected function getRaw(): string
    {
        return $this->raw;
    }
}
