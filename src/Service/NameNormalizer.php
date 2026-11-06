<?php

namespace App\Service;

class NameNormalizer
{
    private readonly \Transliterator $transliterator;

    public function __construct()
    {
        $this->transliterator = \Transliterator::create('Any-Latin; Latin-ASCII; Lower')
            ?? throw new \RuntimeException('ext-intl Transliterator is unavailable');
    }

    /** Małe litery, bez znaków diakrytycznych (dowolny alfabet), tylko litery i cyfry */
    public function normalize(string $name): string
    {
        $ascii = $this->transliterator->transliterate($name);

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $ascii === false ? $name : $ascii) ?? '';
    }
}
