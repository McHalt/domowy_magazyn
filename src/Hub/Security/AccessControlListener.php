<?php

namespace App\Hub\Security;

use App\Api\ApiAuthenticator;
use App\Api\ApiProblem;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Każda akcja musi zadeklarować #[RequireScope] albo #[PublicEndpoint]; akcja bez deklaracji jest odrzucana,
 * żeby nowa trasa nie była przypadkiem otwarta.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: 8)]
final class AccessControlListener
{
    public const API_PREFIX = '/api/v1/';

    public function __construct(
        private readonly ApiAuthenticator $apiAuth,
        private readonly WebSessionAuth $webAuth,
        private readonly Environment $twig,
        #[Autowire(service: 'monolog.logger.hub')]
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $attributes = $event->getAttributes();
        if (isset($attributes[PublicEndpoint::class])) {
            return;
        }

        $required = $attributes[RequireScope::class] ?? [];
        if ($required === []) {
            $this->logger->critical('Akcja bez #[RequireScope] i #[PublicEndpoint]', ['route' => $event->getRequest()->attributes->get('_route')]);
            throw new \LogicException('Akcja bez zadeklarowanego dostępu.');
        }
        $scope = end($required)->scope;

        $request = $event->getRequest();
        $isApi = str_starts_with($request->getPathInfo(), self::API_PREFIX);

        $principal = $isApi ? $this->apiAuth->authenticate($request) : $this->webAuth->authenticate($request);
        if ($principal instanceof Response) {
            $event->setController(static fn() => $principal);

            return;
        }

        CurrentPrincipal::set($request, $principal);

        if ($principal->can($scope)) {
            return;
        }

        if ($isApi) {
            throw ApiProblem::insufficientScope($scope);
        }

        $forbidden = new Response($this->twig->render('errors/forbidden.html.twig'), Response::HTTP_FORBIDDEN);
        $event->setController(static fn() => $forbidden);
    }
}
