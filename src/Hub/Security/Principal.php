<?php

namespace App\Hub\Security;

final class Principal
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $sub,
        public readonly string $name,
        public readonly ?string $role,
        public readonly array $scopes,
    ) {}

    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
