<?php

namespace App\Controller;

use App\Hub\Security\PublicEndpoint;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[PublicEndpoint]
final class HealthController
{
    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function __invoke(Connection $connection, LoggerInterface $logger): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            $logger->error('Healthcheck: baza niedostępna', ['exception' => $e]);

            return $this->respond('unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->respond('ok', Response::HTTP_OK);
    }

    private function respond(string $status, int $code): JsonResponse
    {
        $response = new JsonResponse(['status' => $status], $code);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
