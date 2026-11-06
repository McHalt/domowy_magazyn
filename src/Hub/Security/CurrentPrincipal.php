<?php

namespace App\Hub\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CurrentPrincipal
{
    public const ATTRIBUTE = '_principal';

    public function __construct(private readonly RequestStack $requestStack) {}

    public static function set(Request $request, Principal $principal): void
    {
        $request->attributes->set(self::ATTRIBUTE, $principal);
    }

    public function get(): ?Principal
    {
        $principal = $this->requestStack->getMainRequest()?->attributes->get(self::ATTRIBUTE);

        return $principal instanceof Principal ? $principal : null;
    }

    /** `sub` domownika do zapisu autora zmiany; NULL poza zalogowanym kontekstem (legacy API, konsola). */
    public function memberId(): ?string
    {
        return $this->get()?->sub;
    }
}
