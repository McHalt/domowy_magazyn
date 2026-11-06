<?php

namespace App\Controller;

use App\Hub\HubConfig;
use App\Hub\Jwt\InvalidTokenException;
use App\Hub\Jwt\TokenValidator;
use App\Hub\Oidc\HubUnavailableException;
use App\Hub\Oidc\OidcClient;
use App\Hub\Oidc\OidcException;
use App\Hub\Security\MemberRegistry;
use App\Hub\Security\PublicEndpoint;
use App\Hub\Security\Scopes;
use App\Hub\Security\WebSessionAuth;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[PublicEndpoint]
class AuthController extends AbstractController
{
    private const PENDING_KEY = 'oidc.pending';
    private const PENDING_MAX = 5;
    private const PENDING_TTL = 600;

    public function __construct(
        private readonly HubConfig $config,
        private readonly OidcClient $oidc,
        private readonly TokenValidator $validator,
        private readonly MemberRegistry $members,
        #[Autowire(service: 'monolog.logger.hub')]
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/login', name: 'login', methods: ['GET'])]
    public function login(Request $request): Response
    {
        if (!$this->config->isConfigured()) {
            return $this->render('errors/unavailable.html.twig', ['message' => 'Logowanie przez hub nie jest skonfigurowane.'], new Response(status: Response::HTTP_SERVICE_UNAVAILABLE));
        }

        $session = $request->getSession();
        $state = self::token();
        $nonce = self::token();
        $verifier = self::token();

        $pending = array_filter(
            (array) $session->get(self::PENDING_KEY, []),
            fn($entry) => is_array($entry) && $entry['createdAt'] > time() - self::PENDING_TTL,
        );
        $pending[$state] = [
            'nonce' => $nonce,
            'verifier' => $verifier,
            'returnTo' => self::safeReturnPath((string) $request->query->get('return', '')),
            'createdAt' => time(),
        ];
        $session->set(self::PENDING_KEY, array_slice($pending, -self::PENDING_MAX, null, true));

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return new RedirectResponse($this->oidc->authorizationUrl($state, $nonce, $challenge));
    }

    #[Route('/callback', name: 'login_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $session = $request->getSession();
        $state = (string) $request->query->get('state', '');
        $pending = (array) $session->get(self::PENDING_KEY, []);
        $flow = $pending[$state] ?? null;
        unset($pending[$state]);
        $session->set(self::PENDING_KEY, $pending);

        $error = (string) $request->query->get('error', '');
        if ($error === 'access_denied') {
            return $this->denied();
        }

        if (!is_array($flow) || $flow['createdAt'] <= time() - self::PENDING_TTL) {
            $this->logger->warning('Callback z nieznanym albo wygasłym state');

            return $this->failure('Logowanie wygasło albo zostało przerwane.', Response::HTTP_BAD_REQUEST);
        }

        $code = (string) $request->query->get('code', '');
        if ($error !== '' || $code === '') {
            $this->logger->warning('Hub zwrócił błąd logowania', ['error' => $error]);

            return $this->failure('Hub odrzucił logowanie.', Response::HTTP_BAD_GATEWAY);
        }

        try {
            $tokens = $this->oidc->exchangeCode($code, $flow['verifier']);
            $idToken = $this->validator->validateIdToken((string) ($tokens['id_token'] ?? ''), $flow['nonce']);
            $access = $this->validator->validateLoginAccessToken($tokens['access_token']);
            if ($access->sub() !== $idToken->sub()) {
                throw new InvalidTokenException('sub w ID tokenie i access tokenie się różni');
            }
            if (!is_string($tokens['refresh_token'] ?? null) || $tokens['refresh_token'] === '') {
                throw new InvalidTokenException('brak refresh tokenu');
            }
        } catch (HubUnavailableException $e) {
            $this->logger->warning('Hub niedostępny przy logowaniu', ['exception' => $e]);

            return $this->failure('Hub nie odpowiada.', Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (OidcException $e) {
            $this->logger->warning('Hub odrzucił wymianę kodu', ['error' => $e->error]);

            return $this->failure('Hub odrzucił logowanie.', Response::HTTP_BAD_GATEWAY);
        } catch (InvalidTokenException $e) {
            $this->logger->warning('Niepoprawny token przy logowaniu', ['reason' => $e->getMessage()]);

            return $this->failure('Hub zwrócił niepoprawną odpowiedź.', Response::HTTP_BAD_GATEWAY);
        }

        $scopes = Scopes::ofModule($access->scopes());
        if ($scopes === []) {
            $this->revokeQuietly($tokens['refresh_token']);

            return $this->denied();
        }

        $name = MemberRegistry::cleanName($idToken->string('name'));
        $this->members->touch($idToken->sub(), $name);

        $session->migrate(true);
        $session->remove(self::PENDING_KEY);
        $session->set(WebSessionAuth::SESSION_KEY, [
            'sub' => $idToken->sub(),
            'name' => $name,
            'role' => $idToken->string('role'),
            'scopes' => $scopes,
            'accessExp' => $access->exp(),
            'refreshToken' => $tokens['refresh_token'],
        ]);

        return new RedirectResponse($flow['returnTo']);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $session = $request->getSession();
        $user = $session->get(WebSessionAuth::SESSION_KEY);
        if (is_array($user) && is_string($user['refreshToken'] ?? null)) {
            $this->revokeQuietly($user['refreshToken']);
        }
        $session->invalidate();

        return new RedirectResponse($this->generateUrl('logged_out'));
    }

    #[Route('/logged-out', name: 'logged_out', methods: ['GET'])]
    public function loggedOut(): Response
    {
        return $this->render('auth/logged_out.html.twig');
    }

    #[Route('/login/denied', name: 'login_denied', methods: ['GET'])]
    public function deniedPage(): Response
    {
        return $this->denied();
    }

    private function denied(): Response
    {
        return $this->render('auth/denied.html.twig', [], new Response(status: Response::HTTP_FORBIDDEN));
    }

    private function failure(string $message, int $status): Response
    {
        return $this->render('auth/error.html.twig', ['message' => $message], new Response(status: $status));
    }

    private function revokeQuietly(string $refreshToken): void
    {
        try {
            $this->oidc->revoke($refreshToken);
        } catch (\Throwable $e) {
            $this->logger->warning('Nie udało się odwołać refresh tokenu', ['exception' => $e]);
        }
    }

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Tylko ścieżka względna na tym samym hoście: jeden `/`, bez `//` i `\` (open redirect). */
    private static function safeReturnPath(string $path): string
    {
        return preg_match('~^/(?![/\\\\])[^\x00-\x20\x7f]*$~D', $path) === 1 && !str_starts_with($path, '/login') && !str_starts_with($path, '/callback')
            ? $path
            : '/';
    }
}
