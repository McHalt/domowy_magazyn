<?php

namespace App\Tests\Web;

use App\Hub\Security\WebSessionAuth;
use App\Tests\Support\HubTestCase;
use App\Tests\Support\JwtFactory;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class AuthFlowTest extends HubTestCase
{
    private function startSession(array $user = []): SessionInterface
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set(WebSessionAuth::SESSION_KEY, $user + [
            'sub' => JwtFactory::SUB,
            'name' => 'Ala Żółć',
            'role' => 'adult',
            'scopes' => ['magazyn:items:read', 'magazyn:items:write', 'magazyn:items:admin'],
            'accessExp' => time() + 300,
            'refreshToken' => 'rt-1',
        ]);
        $session->save();
        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $session;
    }

    /** Stan sesji widziany przez kolejne żądanie. */
    private function sessionUser(): mixed
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->setId($this->client->getCookieJar()->get($session->getName())?->getValue() ?? '');
        $session->start();

        return $session->get(WebSessionAuth::SESSION_KEY);
    }

    /** @return array{state: string, nonce: string, verifier: string} */
    private function beginLogin(string $query = ''): array
    {
        $this->client->request('GET', '/login' . $query);
        $this->assertSame(302, $this->responseStatus());
        parse_str(parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $params);
        $pending = $this->client->getRequest()->getSession()->get('oidc.pending')[$params['state']];

        return ['state' => $params['state'], 'nonce' => $params['nonce'], 'verifier' => $pending['verifier']];
    }

    private function queueTokenResponse(string $nonce, array $access = [], array $extra = []): void
    {
        $this->queueJson(200, array_merge([
            'token_type' => 'Bearer',
            'access_token' => JwtFactory::loginAccessToken($access),
            'id_token' => JwtFactory::idToken($nonce),
            'refresh_token' => 'rt-new',
        ], $extra));
    }

    /** Formularz ostatniego żądania POST do huba (JWKS pobierany po nim to GET). */
    private function lastForm(): array
    {
        foreach (array_reverse($this->hubCalls) as $call) {
            if ($call['request']->getMethod() === 'POST') {
                parse_str((string) $call['request']->getBody(), $form);

                return $form;
            }
        }

        return [];
    }

    public function testProtectedPageRedirectsToLoginRememberingPath(): void
    {
        $this->client->request('GET', '/products?x=1');

        $this->assertSame(302, $this->responseStatus());
        $location = $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith('/login?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('/products?x=1', $query['return']);
        $this->assertCount(0, $this->hubCalls);
    }

    public function testUnauthenticatedPostRedirectsToLoginWithoutReturn(): void
    {
        $this->client->request('POST', '/products/1/min-stock');

        $this->assertSame('/login', $this->client->getResponse()->headers->get('Location'));
    }

    public function testLoginRedirectsToHubWithPkceStateAndNonce(): void
    {
        $flow = $this->beginLogin();

        $location = $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith('https://hub.test/oauth/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('module-magazyn', $query['client_id']);
        $this->assertSame('http://localhost/callback', $query['redirect_uri']);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $flow['verifier'], true)), '+/', '-_'), '='), $query['code_challenge']);
        $this->assertGreaterThanOrEqual(32, strlen($flow['state']));
        $this->assertNotSame($flow['state'], $flow['nonce']);
        $this->assertNotSame($flow['nonce'], $flow['verifier']);
        $this->assertCount(0, $this->hubCalls, 'przekierowanie nie woła huba');
    }

    public function testSuccessfulCallbackCreatesSessionMemberAndReturnsToOriginalPage(): void
    {
        $flow = $this->beginLogin('?return=' . rawurlencode('/groups'));
        $this->queueTokenResponse($flow['nonce'], ['scope' => 'openid magazyn:items:read magazyn:items:write other:x:read']);

        $this->client->request('GET', '/callback?code=abc&state=' . $flow['state']);

        $this->assertSame(302, $this->responseStatus());
        $this->assertSame('/groups', $this->client->getResponse()->headers->get('Location'));

        $request = $this->hubCalls[0]['request'];
        $this->assertSame('https://hub.test/oauth/token', (string) $request->getUri());
        $this->assertSame('Basic ' . base64_encode('module-magazyn:test-client-secret'), $request->getHeaderLine('Authorization'));
        $form = $this->lastForm();
        $this->assertSame('authorization_code', $form['grant_type']);
        $this->assertSame('abc', $form['code']);
        $this->assertSame('http://localhost/callback', $form['redirect_uri']);
        $this->assertSame($flow['verifier'], $form['code_verifier']);

        $user = $this->sessionUser();
        $this->assertSame(JwtFactory::SUB, $user['sub']);
        $this->assertSame('Ala Żółć', $user['name']);
        $this->assertSame(['magazyn:items:read', 'magazyn:items:write'], $user['scopes'], 'tylko scope-y magazynu');
        $this->assertSame('rt-new', $user['refreshToken']);
        $this->assertArrayNotHasKey('accessToken', $user);
        $this->assertSame([JwtFactory::SUB => 'Ala Żółć'], $this->db()->fetchAllKeyValue('SELECT id, name FROM members'));

        $this->client->request('GET', '/groups');
        $this->assertSame(200, $this->responseStatus());
        $this->assertStringContainsString('Ala Żółć', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('Wyloguj', $this->client->getResponse()->getContent());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeReturnPaths')]
    public function testReturnPathIsRestrictedToLocalPaths(string $return): void
    {
        $flow = $this->beginLogin('?return=' . rawurlencode($return));
        $this->queueTokenResponse($flow['nonce']);

        $this->client->request('GET', '/callback?code=abc&state=' . $flow['state']);

        $this->assertSame('/', $this->client->getResponse()->headers->get('Location'));
    }

    public static function unsafeReturnPaths(): iterable
    {
        yield ['//evil.example.org'];
        yield ['https://evil.example.org'];
        yield ['/\\evil.example.org'];
        yield ['evil'];
        yield ['/login'];
        yield ["/ok\r\nSet-Cookie: x=1"];
    }

    public function testCallbackWithUnknownStateIsRejectedWithoutCallingHub(): void
    {
        $this->beginLogin();

        $this->client->request('GET', '/callback?code=abc&state=nieznany');

        $this->assertSame(400, $this->responseStatus());
        $this->assertStringContainsString('Zaloguj ponownie', $this->client->getResponse()->getContent());
        $this->assertCount(0, $this->hubCalls);
        $this->assertNull($this->sessionUser());
    }

    public function testCallbackStateIsSingleUse(): void
    {
        $flow = $this->beginLogin();
        $this->queueTokenResponse($flow['nonce']);
        $this->client->request('GET', '/callback?code=abc&state=' . $flow['state']);
        $this->assertSame(302, $this->responseStatus());

        $this->client->request('GET', '/callback?code=abc&state=' . $flow['state']);

        $this->assertSame(400, $this->responseStatus());
    }

    public function testAccessDeniedShowsNoAccessPageWithoutLoginLoop(): void
    {
        $flow = $this->beginLogin();

        $this->client->request('GET', '/callback?error=access_denied&state=' . $flow['state']);

        $this->assertSame(403, $this->responseStatus());
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Brak dostępu', $content);
        $this->assertStringNotContainsString('http-equiv="refresh"', $content);
        $this->assertCount(0, $this->hubCalls);
    }

    public function testHubErrorInCallbackShowsGenericMessageWithoutDetails(): void
    {
        $flow = $this->beginLogin();

        $this->client->request('GET', '/callback?error=server_error&error_description=SECRET-DETAIL&state=' . $flow['state']);

        $this->assertSame(502, $this->responseStatus());
        $this->assertStringNotContainsString('SECRET-DETAIL', $this->client->getResponse()->getContent());
    }

    public function testTokenExchangeRejectedByHubShowsError(): void
    {
        $flow = $this->beginLogin();
        $this->queueJson(400, ['error' => 'invalid_grant', 'error_description' => 'SECRET-DETAIL']);

        $this->client->request('GET', '/callback?code=zly&state=' . $flow['state']);

        $this->assertSame(502, $this->responseStatus());
        $this->assertStringNotContainsString('SECRET-DETAIL', $this->client->getResponse()->getContent());
        $this->assertNull($this->sessionUser());
    }

    public function testHubUnavailableDuringCallbackGives503(): void
    {
        $flow = $this->beginLogin();
        $this->hub->append(new \GuzzleHttp\Psr7\Response(503));

        $this->client->request('GET', '/callback?code=abc&state=' . $flow['state']);

        $this->assertSame(503, $this->responseStatus());
        $this->assertStringContainsString('Zaloguj ponownie', $this->client->getResponse()->getContent());
    }

    public function testIdTokenWithWrongNonceIsRejected(): void
    {
        $flow = $this->beginLogin();
        $this->queueTokenResponse('inny-nonce');

        $this->client->request('GET', '/callback?code=abc&state=' . $flow['state']);

        $this->assertSame(502, $this->responseStatus());
        $this->assertNull($this->sessionUser());
    }

    public function testMissingRefreshTokenIsRejected(): void
    {
        $flow = $this->beginLogin();
        $this->queueTokenResponse($flow['nonce'], extra: ['refresh_token' => null]);

        $this->client->request('GET', '/callback?code=abc&state=' . $flow['state']);

        $this->assertSame(502, $this->responseStatus());
    }

    public function testAccessTokenOfAnotherUserThanIdTokenIsRejected(): void
    {
        $flow = $this->beginLogin();
        $this->queueTokenResponse($flow['nonce'], ['sub' => '11111111-2222-4333-8444-555555555555']);

        $this->client->request('GET', '/callback?code=abc&state=' . $flow['state']);

        $this->assertSame(502, $this->responseStatus());
    }

    public function testAccessTokenWithApiAudienceIsRejectedInLogin(): void
    {
        $flow = $this->beginLogin();
        $this->queueJson(200, [
            'access_token' => JwtFactory::apiToken(), 'id_token' => JwtFactory::idToken($flow['nonce']), 'refresh_token' => 'rt-new',
        ]);

        $this->client->request('GET', '/callback?code=abc&state=' . $flow['state']);

        $this->assertSame(502, $this->responseStatus());
    }

    public function testLoginWithoutAnyModuleScopeShowsNoAccessAndRevokesRefreshToken(): void
    {
        $flow = $this->beginLogin();
        $this->queueTokenResponse($flow['nonce'], ['scope' => 'openid shopping:list:read']);
        $this->hub->append(new \GuzzleHttp\Psr7\Response(200));

        $this->client->request('GET', '/callback?code=abc&state=' . $flow['state']);

        $this->assertSame(403, $this->responseStatus());
        $this->assertNull($this->sessionUser());
        $this->assertSame('rt-new', $this->lastForm()['token']);
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM members'));
    }

    public function testSessionWithValidAccessTokenDoesNotCallHub(): void
    {
        $this->startSession();

        $this->client->request('GET', '/products');

        $this->assertSame(200, $this->responseStatus());
        $this->assertCount(0, $this->hubCalls);
    }

    public function testExpiredAccessTokenIsRefreshedWithRotationAndScopesAreUpdated(): void
    {
        $this->startSession(['accessExp' => time() - 5]);
        $this->queueJson(200, [
            'access_token' => JwtFactory::loginAccessToken(['scope' => 'magazyn:items:read']),
            'refresh_token' => 'rt-2',
            'token_type' => 'Bearer',
        ]);

        $this->client->request('GET', '/products');

        $this->assertSame(200, $this->responseStatus());
        $form = $this->lastForm();
        $this->assertSame('refresh_token', $form['grant_type']);
        $this->assertSame('rt-1', $form['refresh_token']);
        $user = $this->sessionUser();
        $this->assertSame('rt-2', $user['refreshToken'], 'nowy refresh token zastępuje stary');
        $this->assertSame(['magazyn:items:read'], $user['scopes']);
        $this->assertGreaterThan(time() + 300, $user['accessExp']);

        $this->client->request('GET', '/products');
        $this->assertCount(2, $this->hubCalls, 'kolejne żądanie nie odświeża ponownie (token + JWKS tylko raz)');
    }

    public function testRefreshInsideSafetyMarginIsDone(): void
    {
        $this->startSession(['accessExp' => time() + 10]);
        $this->queueJson(200, ['access_token' => JwtFactory::loginAccessToken(), 'refresh_token' => 'rt-2']);

        $this->client->request('GET', '/products');

        $this->assertSame('rt-2', $this->sessionUser()['refreshToken']);
    }

    public function testRefreshStillWorksWhenHubDoesNotRotateRefreshToken(): void
    {
        $this->startSession(['accessExp' => time() - 5]);
        $this->queueJson(200, ['access_token' => JwtFactory::loginAccessToken()]);

        $this->client->request('GET', '/products');

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame('rt-1', $this->sessionUser()['refreshToken']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sessionEndingErrors')]
    public function testInvalidGrantOrClientEndsSessionAndSendsToLogin(string $error): void
    {
        $this->startSession(['accessExp' => time() - 5]);
        $this->queueJson(400, ['error' => $error]);

        $this->client->request('GET', '/groups');

        $this->assertSame(302, $this->responseStatus());
        parse_str(parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('/groups', $query['return']);
        $this->assertNull($this->sessionUser());

        $calls = count($this->hubCalls);
        $this->client->request('GET', '/groups');
        $this->assertCount($calls, $this->hubCalls, 'zakończona sesja nie woła już huba');
    }

    public static function sessionEndingErrors(): iterable
    {
        yield ['invalid_grant'];
        yield ['invalid_client'];
    }

    public function testHubOutageDuringRefreshKeepsSession(): void
    {
        $this->startSession(['accessExp' => time() - 5]);
        $this->hub->append(new \GuzzleHttp\Psr7\Response(503));

        $this->client->request('GET', '/products');

        $this->assertSame(503, $this->responseStatus());
        $this->assertStringContainsString('Hub nie odpowiada', $this->client->getResponse()->getContent());
        $this->assertSame('rt-1', $this->sessionUser()['refreshToken']);

        $this->queueJson(200, ['access_token' => JwtFactory::loginAccessToken(), 'refresh_token' => 'rt-2']);
        $this->client->request('GET', '/products');
        $this->assertSame(200, $this->responseStatus());
    }

    public function testOtherRefreshErrorsKeepSession(): void
    {
        $this->startSession(['accessExp' => time() - 5]);
        $this->queueJson(400, ['error' => 'invalid_request']);

        $this->client->request('GET', '/products');

        $this->assertSame(502, $this->responseStatus());
        $this->assertSame('rt-1', $this->sessionUser()['refreshToken']);
    }

    public function testRefreshWithoutModuleScopesEndsSessionWithNoAccessPage(): void
    {
        $this->startSession(['accessExp' => time() - 5]);
        $this->queueJson(200, ['access_token' => JwtFactory::loginAccessToken(['scope' => 'shopping:list:read']), 'refresh_token' => 'rt-2']);

        $this->client->request('GET', '/products');

        $this->assertSame(302, $this->responseStatus());
        $this->assertSame('/login/denied', $this->client->getResponse()->headers->get('Location'));
        $this->assertNull($this->sessionUser());
    }

    public function testRefreshedTokenOfAnotherUserEndsSession(): void
    {
        $this->startSession(['accessExp' => time() - 5]);
        $this->queueJson(200, ['access_token' => JwtFactory::loginAccessToken(['sub' => '11111111-2222-4333-8444-555555555555']), 'refresh_token' => 'rt-2']);

        $this->client->request('GET', '/products');

        $this->assertSame(302, $this->responseStatus());
        $this->assertNull($this->sessionUser());
    }

    public function testRefreshedTokenWithWrongAudienceEndsSession(): void
    {
        $this->startSession(['accessExp' => time() - 5]);
        $this->queueJson(200, ['access_token' => JwtFactory::apiToken(), 'refresh_token' => 'rt-2']);

        $this->client->request('GET', '/products');

        $this->assertSame(302, $this->responseStatus());
        $this->assertNull($this->sessionUser());
    }

    public function testScopesDecideAccessNotRole(): void
    {
        $this->startSession(['role' => 'admin', 'scopes' => ['magazyn:items:read']]);

        $this->client->request('GET', '/products');
        $this->assertSame(200, $this->responseStatus());

        $this->client->request('GET', '/products/edit');
        $this->assertSame(403, $this->responseStatus());
        $this->assertStringContainsString('Brak uprawnień', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/products/remove');
        $this->assertSame(403, $this->responseStatus());

        $this->client->request('POST', '/groups/1/min-stock');
        $this->assertSame(403, $this->responseStatus());
    }

    public function testWriteScopeAllowsEditButNotMinStockOrGroups(): void
    {
        $this->startSession(['scopes' => ['magazyn:items:read', 'magazyn:items:write']]);

        $this->client->request('GET', '/products/edit');
        $this->assertSame(200, $this->responseStatus());

        foreach ([['POST', '/products/1/min-stock'], ['POST', '/groups/1/min-stock'], ['POST', '/groups/1/unit'], ['GET', '/groups/add']] as [$method, $path]) {
            $this->client->request($method, $path);
            $this->assertSame(403, $this->responseStatus(), "$method $path");
        }
    }

    public function testWebAddedStockRecordsAuthorOfTheLoggedInMember(): void
    {
        $this->startSession();
        $this->db()->executeStatement("INSERT INTO members (id, name, created_at, updated_at) VALUES (?, 'Ala Żółć', NOW(), NOW())", [JwtFactory::SUB]);

        $this->client->request('POST', '/products/edit', ['ean' => '5900617003441', 'qty' => '2', 'cost' => '6,49', 'feature_name' => 'Mleko']);
        $this->assertSame(302, $this->responseStatus());

        $this->assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM products_history WHERE added_by = ?', [JwtFactory::SUB]));

        $this->client->request('GET', '/products/remove?ean=5900617003441&date=');
        $this->assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM products_history WHERE removed_by = ?', [JwtFactory::SUB]));
    }

    public function testLogoutRevokesRefreshTokenEndsSessionAndKeepsHubSession(): void
    {
        $this->startSession();
        $this->hub->append(new \GuzzleHttp\Psr7\Response(200));

        $this->client->request('POST', '/logout');

        $this->assertSame(302, $this->responseStatus());
        $this->assertSame('/logged-out', $this->client->getResponse()->headers->get('Location'));
        $request = $this->hubCalls[0]['request'];
        $this->assertSame('https://hub.test/oauth/revoke', (string) $request->getUri());
        $this->assertSame('rt-1', $this->lastForm()['token']);
        $this->assertSame('refresh_token', $this->lastForm()['token_type_hint']);
        $this->assertNull($this->sessionUser());

        $this->client->request('GET', '/logged-out');
        $this->assertSame(200, $this->responseStatus());
        $this->assertStringContainsString('Sesja w hubie', $this->client->getResponse()->getContent());
    }

    public function testLogoutSucceedsEvenWhenRevocationFails(): void
    {
        $this->startSession();
        $this->hub->append(new \GuzzleHttp\Psr7\Response(500));

        $this->client->request('POST', '/logout');

        $this->assertSame('/logged-out', $this->client->getResponse()->headers->get('Location'));
        $this->assertNull($this->sessionUser());
    }

    public function testLogoutRequiresSameOriginLikeOtherStateChangingRequests(): void
    {
        $this->startSession();

        $this->client->request('POST', '/logout', server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        $this->assertSame(403, $this->responseStatus());
        $this->assertCount(0, $this->hubCalls);
        $this->assertNotNull($this->sessionUser());
    }

    public function testWebFailsClosedWithoutHubConfiguration(): void
    {
        static::ensureKernelShutdown();
        $_SERVER['HUB_CLIENT_SECRET'] = $_ENV['HUB_CLIENT_SECRET'] = '';
        try {
            $this->client = static::createClient();
            $this->client->request('GET', '/products');
            $this->assertSame(503, $this->responseStatus());
            $this->assertStringContainsString('nie jest skonfigurowane', $this->client->getResponse()->getContent());

            $this->client->request('GET', '/login');
            $this->assertSame(503, $this->responseStatus());
        } finally {
            $_SERVER['HUB_CLIENT_SECRET'] = $_ENV['HUB_CLIENT_SECRET'] = 'test-client-secret';
        }
    }

    public function testLegacyApiAndHealthcheckStayPublic(): void
    {
        $this->client->request('GET', '/healthz');
        $this->assertSame(200, $this->responseStatus());
        $this->assertSame('{"status":"ok"}', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/api/index.php?p=Main&pwd=test-pwd');
        $this->assertSame(200, $this->responseStatus());
        $this->assertSame(['outdated' => 'none', 'shortExpirationDates' => 'none'], json_decode($this->client->getResponse()->getContent(), true));

        $this->client->request('GET', '/api/index.php?p=Main&pwd=zle');
        $this->assertSame('', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/api/index.php?p=find_stocks&pwd=test-pwd&name=nic');
        $this->assertSame(['type' => null, 'qty' => 0, 'matches' => []], json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testWebSessionCookieDoesNotAuthenticateApi(): void
    {
        $this->startSession();

        $body = $this->api('GET', '/api/v1/products', token: false);

        $this->assertSame(401, $this->responseStatus());
        $this->assertSame('invalid_token', $body['code']);
    }

    public function testApiTokenDoesNotAuthenticateWeb(): void
    {
        $this->client->request('GET', '/products', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . JwtFactory::apiToken()]);

        $this->assertSame(302, $this->responseStatus());
        $this->assertStringStartsWith('/login', $this->client->getResponse()->headers->get('Location'));
    }
}
