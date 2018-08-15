<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\MonorepoTools\Git;

abstract class GitObject
{
    private $raw;

    public function __construct(string $rawData)
    {
        $this->raw = $rawData;
    }

    abstract protected static function getGitType(): string;

    public function getHash(): string
    {
        return sha1(static::getGitType().' '.strlen($this->raw)."\0".$this->raw);
    }

    public function getGitObjectBytes(): string
    {
        return gzdeflate(static::getGitType().' '.strlen($this->raw)."\0".$this->raw, -1, ZLIB_ENCODING_DEFLATE);
    }

    protected function getRaw(): string
    {
        return $this->raw;
    }
}
