<?php

namespace App\Hub\Security;

use App\Hub\HubConfig;
use App\Hub\Jwt\InvalidTokenException;
use App\Hub\Jwt\TokenValidator;
use App\Hub\Oidc\HubUnavailableException;
use App\Hub\Oidc\OidcClient;
use App\Hub\Oidc\OidcException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/** Uwierzytelnienie webu własną sesją; access token jest odświeżany przy pierwszym żądaniu po wygaśnięciu. */
final class WebSessionAuth
{
    public const SESSION_KEY = 'hub.user';
    private const REFRESH_MARGIN = 30;

    public function __construct(
        private readonly HubConfig $config,
        private readonly OidcClient $oidc,
        private readonly TokenValidator $validator,
        private readonly LockFactory $locks,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire(service: 'monolog.logger.hub')]
        private readonly LoggerInterface $logger,
    ) {}

    public function authenticate(Request $request): Principal|Response
    {
        if (!$this->config->isConfigured()) {
            $this->logger->error('Brak konfiguracji huba (HUB_ISSUER, HUB_CLIENT_SECRET, APP_PUBLIC_URL)');

            return $this->page('errors/unavailable.html.twig', Response::HTTP_SERVICE_UNAVAILABLE, [
                'message' => 'Logowanie przez hub nie jest skonfigurowane.',
            ]);
        }

        $session = $request->getSession();
        $user = $session->get(self::SESSION_KEY);
        if (!is_array($user) || !isset($user['sub'], $user['refreshToken'], $user['accessExp'], $user['scopes'])) {
            return $this->redirectToLogin($request);
        }

        if ($user['accessExp'] - self::REFRESH_MARGIN <= time()) {
            $refreshed = $this->refresh($request, $session, $user);
            if ($refreshed instanceof Response) {
                return $refreshed;
            }
            $user = $refreshed;
        }

        return new Principal($user['sub'], (string) ($user['name'] ?? ''), $user['role'] ?? null, $user['scopes']);
    }

    /** @return array<string, mixed>|Response */
    private function refresh(Request $request, SessionInterface $session, array $user): array|Response
    {
        $lock = $this->locks->createLock('refresh-' . hash('sha256', $session->getId()), 30);
        $lock->acquire(true);

        try {
            $tokens = $this->oidc->refresh($user['refreshToken']);
            $access = $this->validator->validateLoginAccessToken($tokens['access_token']);
            if ($access->sub() !== $user['sub']) {
                throw new InvalidTokenException('sub po odświeżeniu inny niż w sesji');
            }
        } catch (HubUnavailableException $e) {
            $this->logger->warning('Hub niedostępny przy odświeżaniu sesji', ['exception' => $e]);

            return $this->page('errors/unavailable.html.twig', Response::HTTP_SERVICE_UNAVAILABLE, [
                'message' => 'Hub nie odpowiada. Spróbuj za chwilę.',
            ]);
        } catch (OidcException $e) {
            $this->logger->info('Odświeżenie sesji odrzucone', ['error' => $e->error]);
            if (!$e->endsSession()) {
                return $this->page('errors/unavailable.html.twig', Response::HTTP_BAD_GATEWAY, [
                    'message' => 'Hub odrzucił odświeżenie sesji. Spróbuj za chwilę.',
                ]);
            }
            $session->invalidate();

            return $this->redirectToLogin($request);
        } catch (InvalidTokenException $e) {
            $this->logger->warning('Niepoprawny token po odświeżeniu', ['reason' => $e->getMessage()]);
            $session->invalidate();

            return $this->redirectToLogin($request);
        } finally {
            $lock->release();
        }

        $scopes = Scopes::ofModule($access->scopes());
        if ($scopes === []) {
            $session->invalidate();

            return new RedirectResponse($this->urls->generate('login_denied'));
        }

        $user['scopes'] = $scopes;
        $user['accessExp'] = $access->exp();
        $user['refreshToken'] = is_string($tokens['refresh_token'] ?? null) ? $tokens['refresh_token'] : $user['refreshToken'];
        $session->set(self::SESSION_KEY, $user);

        return $user;
    }

    private function redirectToLogin(Request $request): RedirectResponse
    {
        $params = [];
        if ($request->isMethod('GET')) {
            $params['return'] = $request->getRequestUri();
        }

        return new RedirectResponse($this->urls->generate('login', $params));
    }

    private function page(string $template, int $status, array $context): Response
    {
        return new Response($this->twig->render($template, $context), $status);
    }
}
