<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

final class CorrelationId
{
    public const HEADER = 'X-Correlation-Id';
    private const ATTRIBUTE = '_correlation_id';
    private const VALID = '/^[A-Za-z0-9._-]{1,64}$/';

    public function __construct(private readonly RequestStack $requestStack) {}

    public static function assign(Request $request): string
    {
        $incoming = $request->headers->get(self::HEADER);
        $id = $incoming !== null && preg_match(self::VALID, $incoming) === 1 ? $incoming : Uuid::v4()->toRfc4122();
        $request->attributes->set(self::ATTRIBUTE, $id);

        return $id;
    }

    public static function of(Request $request): string
    {
        $id = $request->attributes->get(self::ATTRIBUTE);

        return is_string($id) ? $id : self::assign($request);
    }

    public function current(): ?string
    {
        $id = $this->requestStack->getMainRequest()?->attributes->get(self::ATTRIBUTE);

        return is_string($id) ? $id : null;
    }
}
