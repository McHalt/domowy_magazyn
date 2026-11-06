<?php

namespace App\Tests\Hub\Jwt;

use App\Hub\Jwt\InvalidTokenException;
use App\Hub\Jwt\TokenValidator;
use App\Tests\Support\HubTestCase;
use App\Tests\Support\JwtFactory;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;

final class TokenValidatorTest extends HubTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->autoJwks = false;
    }

    private function validator(): TokenValidator
    {
        return static::getContainer()->get(TokenValidator::class);
    }

    private function assertRejected(callable $validate, string $reasonFragment): void
    {
        try {
            $validate();
            $this->fail('Token powinien zostać odrzucony');
        } catch (InvalidTokenException $e) {
            $this->assertStringContainsString($reasonFragment, $e->getMessage());
        }
    }

    public function testValidApiToken(): void
    {
        $this->queueJwks();

        $token = $this->validator()->validateApiToken(JwtFactory::apiToken());

        $this->assertSame(JwtFactory::SUB, $token->sub());
        $this->assertSame('Ala Żółć', $token->string('name'));
        $this->assertSame(['magazyn:items:read', 'magazyn:items:write', 'magazyn:items:admin'], $token->scopes());
    }

    #[DataProvider('invalidApiClaims')]
    public function testApiTokenWithWrongClaimIsRejected(array $overrides, string $reason): void
    {
        $this->queueJwks();

        $this->assertRejected(fn() => $this->validator()->validateApiToken(JwtFactory::apiToken($overrides)), $reason);
    }

    public static function invalidApiClaims(): iterable
    {
        yield 'aud z logowania' => [['aud' => 'module-magazyn'], 'aud'];
        yield 'aud innego modułu' => [['aud' => 'urn:home-hub:module:shopping'], 'aud'];
        yield 'iss' => [['iss' => 'https://obcy.example.org'], 'iss'];
        yield 'iss z ukośnikiem' => [['iss' => 'https://hub.test/'], 'iss'];
        yield 'wygasły' => [['exp' => time() - 120, 'iat' => time() - 180], 'podpis lub czas'];
        yield 'bez exp' => [['exp' => null], 'exp'];
        yield 'sub nie jest UUID' => [['sub' => 'ala'], 'sub'];
        yield 'bez sub' => [['sub' => null], 'sub'];
        yield 'scope nie jest ciągiem' => [['scope' => ['magazyn:items:read']], 'scope'];
        yield 'nbf z przyszłości' => [['nbf' => time() + 300], 'podpis lub czas'];
    }

    public function testExpiredWithinClockToleranceIsAccepted(): void
    {
        $this->queueJwks();

        $token = $this->validator()->validateApiToken(JwtFactory::apiToken(['exp' => time() - 20, 'iat' => time() - 80]));

        $this->assertSame(JwtFactory::SUB, $token->sub());
    }

    public function testAudienceAsArrayContainingExpectedValueIsAccepted(): void
    {
        $this->queueJwks();

        $token = $this->validator()->validateApiToken(JwtFactory::apiToken(['aud' => ['x', 'urn:home-hub:module:magazyn']]));

        $this->assertSame(JwtFactory::SUB, $token->sub());
    }

    public function testIdTokenPassedAsApiTokenIsRejected(): void
    {
        $this->queueJwks();

        $this->assertRejected(fn() => $this->validator()->validateApiToken(JwtFactory::idToken('n-1')), 'typ');
    }

    public function testTypIsCaseInsensitiveButMustBeAtJwt(): void
    {
        $this->queueJwks();
        $this->assertSame(JwtFactory::SUB, $this->validator()->validateApiToken(JwtFactory::apiToken(header: ['typ' => 'AT+JWT']))->sub());

        $this->assertRejected(fn() => $this->validator()->validateApiToken(JwtFactory::apiToken(header: ['typ' => 'JWT'])), 'typ');
    }

    public function testNoneAlgorithmIsRejected(): void
    {
        $this->queueJwks();
        $b64 = fn(array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        $token = $b64(['alg' => 'none', 'typ' => 'at+jwt', 'kid' => 'k1']) . '.'
            . $b64(['iss' => JwtFactory::ISSUER, 'aud' => 'urn:home-hub:module:magazyn', 'sub' => JwtFactory::SUB, 'exp' => time() + 60]) . '.';

        $this->assertRejected(fn() => $this->validator()->validateApiToken($token), 'RS256');
    }

    public function testHmacSignedWithPublicKeyIsRejected(): void
    {
        $this->queueJwks();
        $jwk = JwtFactory::jwks()['keys'][0];
        $token = JWT::encode([
            'iss' => JwtFactory::ISSUER, 'aud' => 'urn:home-hub:module:magazyn', 'sub' => JwtFactory::SUB, 'exp' => time() + 60,
        ], str_repeat($jwk['n'], 2), 'HS256', 'k1', ['typ' => 'at+jwt']);

        $this->assertRejected(fn() => $this->validator()->validateApiToken($token), 'RS256');
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $this->queueJwks();
        [$header, , $signature] = explode('.', JwtFactory::apiToken());
        $payload = rtrim(strtr(base64_encode(json_encode([
            'iss' => JwtFactory::ISSUER, 'aud' => 'urn:home-hub:module:magazyn', 'sub' => JwtFactory::SUB,
            'scope' => 'magazyn:items:admin', 'exp' => time() + 60,
        ])), '+/', '-_'), '=');

        $this->assertRejected(fn() => $this->validator()->validateApiToken("$header.$payload.$signature"), 'podpis');
    }

    public function testTokenSignedByAnotherKeyUnderKnownKidIsRejected(): void
    {
        $this->queueJwks();

        $this->assertRejected(fn() => $this->validator()->validateApiToken(JwtFactory::apiToken(kid: 'k2')), 'kid');
    }

    #[DataProvider('malformedTokens')]
    public function testMalformedTokenIsRejected(string $token): void
    {
        $this->assertRejected(fn() => $this->validator()->validateApiToken($token), '');
    }

    public static function malformedTokens(): iterable
    {
        yield 'pusty' => [''];
        yield 'dwa segmenty' => ['a.b'];
        yield 'cztery segmenty' => ['a.b.c.d'];
        yield 'nagłówek nie jest JSON' => ['abc.def.ghi'];
        yield 'za długi' => [str_repeat('a', 9000) . '.b.c'];
    }

    public function testUnknownKidTriggersOneJwksRefetch(): void
    {
        $this->queueJwks(['k1']);
        $this->queueJwks(['k1', 'k2']);

        $this->assertSame(JwtFactory::SUB, $this->validator()->validateApiToken(JwtFactory::apiToken())->sub());
        $this->assertCount(1, $this->hubCalls);

        $this->assertSame(JwtFactory::SUB, $this->validator()->validateApiToken(JwtFactory::apiToken(kid: 'k2'))->sub());
        $this->assertCount(2, $this->hubCalls);
        $this->assertSame('/.well-known/jwks.json', $this->hubCalls[1]['request']->getUri()->getPath());
    }

    public function testUnknownKidRefetchIsRateLimited(): void
    {
        $this->queueJwks(['k1']);
        $this->queueJwks(['k1']);

        $this->validator()->validateApiToken(JwtFactory::apiToken());
        foreach (['x1', 'x2', 'x3'] as $kid) {
            $this->assertRejected(fn() => $this->validator()->validateApiToken(JwtFactory::apiToken(kid: $kid)), 'kid');
        }

        $this->assertCount(2, $this->hubCalls, 'jedno pobranie startowe i jedno przy pierwszym nieznanym kid');
    }

    public function testHubDownWithNoCachedKeysRejectsTokens(): void
    {
        $this->hub->append(new \GuzzleHttp\Psr7\Response(503));

        $this->assertRejected(fn() => $this->validator()->validateApiToken(JwtFactory::apiToken()), 'kid');
    }

    public function testNonRsaKeysInJwksAreIgnored(): void
    {
        $jwks = JwtFactory::jwks();
        array_unshift($jwks['keys'], ['kty' => 'oct', 'kid' => 'sym', 'k' => 'c2VjcmV0'], ['kty' => 'EC', 'kid' => 'ec', 'crv' => 'P-256', 'x' => 'a', 'y' => 'b']);
        $this->queueJson(200, $jwks);

        $this->assertSame(JwtFactory::SUB, $this->validator()->validateApiToken(JwtFactory::apiToken())->sub());
    }

    public function testIdTokenNonce(): void
    {
        $this->queueJwks();
        $this->assertSame(JwtFactory::SUB, $this->validator()->validateIdToken(JwtFactory::idToken('nonce-1'), 'nonce-1')->sub());

        $this->assertRejected(fn() => $this->validator()->validateIdToken(JwtFactory::idToken('nonce-1'), 'nonce-2'), 'nonce');
        $this->assertRejected(fn() => $this->validator()->validateIdToken(JwtFactory::idToken('x', ['nonce' => null]), 'x'), 'nonce');
        $this->assertRejected(fn() => $this->validator()->validateIdToken(JwtFactory::idToken('nonce-1'), ''), 'nonce');
    }

    public function testIdTokenAudienceMustBeClientId(): void
    {
        $this->queueJwks();

        $this->assertRejected(fn() => $this->validator()->validateIdToken(JwtFactory::idToken('n', ['aud' => 'module-inny']), 'n'), 'aud');
    }

    public function testLoginAccessTokenRequiresClientAudienceAndAtJwt(): void
    {
        $this->queueJwks();
        $this->assertSame(JwtFactory::SUB, $this->validator()->validateLoginAccessToken(JwtFactory::loginAccessToken())->sub());

        $this->assertRejected(fn() => $this->validator()->validateLoginAccessToken(JwtFactory::apiToken()), 'aud');
        $this->assertRejected(fn() => $this->validator()->validateLoginAccessToken(JwtFactory::idToken('n')), 'typ');
    }
}
