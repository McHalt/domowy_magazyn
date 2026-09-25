<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ochrona przed CSRF bez sesji: żądania zmieniające dane muszą pochodzić z tej samej strony.
 * Basic Auth nie chroni — przeglądarka dołącza dane logowania także do żądań z obcych stron.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class SameOriginGuard
{
    /** Trasy, które zmieniają dane także przez GET (formularze edycji i link „zdejmij” używają GET). */
    private const MUTATING_GET_ROUTES = ['product_edit', 'product_remove'];

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !$this->needsProtection($request) || !$this->isCrossSite($request)) {
            return;
        }

        $event->setResponse(new Response(
            'Zablokowano żądanie wysłane z innej strony.',
            Response::HTTP_FORBIDDEN,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        ));
    }

    private function needsProtection(Request $request): bool
    {
        // API ma własne hasło (?pwd=), a jego klientem jest aplikacja mobilna, nie przeglądarka.
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return false;
        }

        return !$request->isMethodSafe()
            || in_array($request->attributes->get('_route'), self::MUTATING_GET_ROUTES, true);
    }

    private function isCrossSite(Request $request): bool
    {
        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if ($fetchSite !== null) {
            // same-site też odrzucamy: na tej samej domenie stoją inne strony (cookbook, szpaki, ...).
            return !in_array($fetchSite, ['same-origin', 'none'], true);
        }

        // Starsze przeglądarki bez Sec-Fetch-*: Origin (POST), a dla GET — Referer.
        foreach (['Origin', 'Referer'] as $header) {
            $value = $request->headers->get($header);
            if ($value !== null) {
                return $this->originOf($value) !== $request->getSchemeAndHttpHost();
            }
        }

        return false;
    }

    private function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $parts['scheme'] . '://' . $parts['host'] . $port;
    }
}
