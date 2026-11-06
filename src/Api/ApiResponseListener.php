<?php

namespace App\Api;

use App\Hub\Security\AccessControlListener;
use App\Http\CorrelationId;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/** Pod /api/v1 błędy zawsze są problem+json, a odpowiedzi nie trafiają do cache. */
final class ApiResponseListener
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.hub')]
        private readonly LoggerInterface $logger,
        #[Autowire('%env(APP_PUBLIC_URL)%')]
        private readonly string $publicUrl,
    ) {}

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isApi($request)) {
            return;
        }

        $throwable = $event->getThrowable();
        $headers = [];

        if ($throwable instanceof ApiProblem) {
            $status = $throwable->status;
            $body = $this->body($request, $status, $throwable->problemCode, $throwable->title, $throwable->errors, $throwable->extra);
            $headers = $throwable->headers;
        } elseif ($throwable instanceof NotFoundHttpException) {
            $status = Response::HTTP_NOT_FOUND;
            $body = $this->body($request, $status, 'not_found', 'Nie znaleziono');
        } elseif ($throwable instanceof MethodNotAllowedHttpException) {
            $status = Response::HTTP_METHOD_NOT_ALLOWED;
            $body = $this->body($request, $status, 'method_not_allowed', 'Niedozwolona metoda');
            $headers = $throwable->getHeaders();
        } elseif ($throwable instanceof RequestExceptionInterface || ($throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() < 500)) {
            $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : Response::HTTP_BAD_REQUEST;
            $body = $this->body($request, $status, 'bad_request', 'Niepoprawne żądanie');
        } else {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            $body = $this->body($request, $status, 'internal_error', 'Błąd serwera');
            $this->logger->error('Błąd serwera w API', ['exception' => $throwable, 'path' => $request->getPathInfo()]);
        }

        $event->setResponse(new Response(
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/problem+json'] + $headers,
        ));
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $this->isApi($event->getRequest())) {
            $event->getResponse()->headers->set('Cache-Control', 'no-store');
            $event->getResponse()->headers->set('X-Content-Type-Options', 'nosniff');
        }
    }

    private function isApi(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), AccessControlListener::API_PREFIX);
    }

    /** @return array<string, mixed> */
    private function body(Request $request, int $status, string $code, string $title, array $errors = [], array $extra = []): array
    {
        $body = [
            'type' => rtrim($this->publicUrl, '/') . '/errors/' . $code,
            'title' => $title,
            'status' => $status,
            'code' => $code,
        ] + $extra;
        if ($errors !== []) {
            $body['errors'] = $errors;
        }
        $body['correlationId'] = CorrelationId::of($request);

        return $body;
    }
}
