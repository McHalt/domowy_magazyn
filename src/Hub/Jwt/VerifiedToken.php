<?php

namespace App\Hub\Jwt;

final class VerifiedToken
{
    /** @param array<string, mixed> $claims */
    public function __construct(public readonly array $claims) {}

    public function sub(): string
    {
        return $this->claims['sub'];
    }

    public function exp(): int
    {
        return $this->claims['exp'];
    }

    public function string(string $claim): ?string
    {
        $value = $this->claims[$claim] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        $scope = $this->claims['scope'] ?? '';

        return is_string($scope) ? array_values(array_filter(explode(' ', $scope), fn(string $s) => $s !== '')) : [];
    }
}
