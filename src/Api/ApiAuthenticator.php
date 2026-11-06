<?php

namespace App\Api;

use App\Hub\Jwt\InvalidTokenException;
use App\Hub\Jwt\TokenValidator;
use App\Hub\Security\MemberRegistry;
use App\Hub\Security\Principal;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/** Uwierzytelnia wywołanie huba wyłącznie tokenem Bearer; sesja web nie ma tu znaczenia. */
final class ApiAuthenticator
{
    public function __construct(
        private readonly TokenValidator $validator,
        private readonly MemberRegistry $members,
        #[Autowire(service: 'monolog.logger.hub')]
        private readonly LoggerInterface $logger,
    ) {}

    public function authenticate(Request $request): Principal
    {
        if (preg_match('/^Bearer ([^\s]+)$/i', (string) $request->headers->get('Authorization'), $matches) !== 1) {
            throw ApiProblem::invalidToken();
        }

        try {
            $token = $this->validator->validateApiToken($matches[1]);
        } catch (InvalidTokenException $e) {
            $this->logger->warning('Odrzucony token API', ['reason' => $e->getMessage()]);

            throw ApiProblem::invalidToken();
        }

        $this->members->touch($token->sub(), $token->string('name'));

        return new Principal(
            $token->sub(),
            MemberRegistry::cleanName($token->string('name')),
            $token->string('role'),
            $token->scopes(),
        );
    }
}
