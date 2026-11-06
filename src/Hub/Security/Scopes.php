<?php

namespace App\Hub\Security;

final class Scopes
{
    public const READ = 'magazyn:items:read';
    public const WRITE = 'magazyn:items:write';
    public const ADMIN = 'magazyn:items:admin';

    private function __construct() {}

    /**
     * @param list<string> $scopes
     * @return list<string> tylko scope-y magazynu
     */
    public static function ofModule(array $scopes): array
    {
        return array_values(array_filter($scopes, fn(string $s) => str_starts_with($s, 'magazyn:')));
    }
}
