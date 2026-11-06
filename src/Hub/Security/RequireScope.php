<?php

namespace App\Hub\Security;

/** Scope wymagany przez akcję kontrolera; akcja bez tego atrybutu i bez #[PublicEndpoint] jest odrzucana. */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class RequireScope
{
    public function __construct(public readonly string $scope) {}
}
