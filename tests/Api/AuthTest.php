<?php

namespace App\Tests\Api;

use App\Tests\Support\HubTestCase;
use App\Tests\Support\JwtFactory;

final class AuthTest extends HubTestCase
{
    public function testMissingTokenGives401WithBearerChallenge(): void
    {
        $body = $this->api('GET', '/api/v1/products', token: false);

        $this->assertSame(401, $this->responseStatus());
        $response = $this->client->getResponse();
        $this->assertSame('Bearer error="invalid_token"', $response->headers->get('WWW-Authenticate'));
        $this->assertStringStartsWith('application/problem+json', $response->headers->get('Content-Type'));
        $this->assertSame('invalid_token', $body['code']);
        $this->assertSame(401, $body['status']);
        $this->assertArrayHasKey('correlationId', $body);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function testNonBearerAuthorizationIsRejected(): void
    {
        $this->api('GET', '/api/v1/products', token: false, server: ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('user:pass')]);

        $this->assertSame(401, $this->responseStatus());
    }

    public function testTokenFromLoginAudienceIsRejected(): void
    {
        $body = $this->api('GET', '/api/v1/products', token: JwtFactory::apiToken(['aud' => 'module-magazyn']));

        $this->assertSame(401, $this->responseStatus());
        $this->assertSame('invalid_token', $body['code']);
    }

    public function testLoginAccessTokenIsRejected(): void
    {
        $this->api('GET', '/api/v1/products', token: JwtFactory::loginAccessToken());

        $this->assertSame(401, $this->responseStatus());
    }

    public function testIdTokenIsRejected(): void
    {
        $this->api('GET', '/api/v1/products', token: JwtFactory::idToken('n'));

        $this->assertSame(401, $this->responseStatus());
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->api('GET', '/api/v1/products', token: JwtFactory::apiToken(['iat' => time() - 200, 'exp' => time() - 140]));

        $this->assertSame(401, $this->responseStatus());
    }

    public function testTokenSignedWithUnknownKeyIsRejected(): void
    {
        $this->api('GET', '/api/v1/products', token: JwtFactory::apiToken(kid: 'obcy'));

        $this->assertSame(401, $this->responseStatus());
    }

    public function testMissingScopeGives403WithRequiredScope(): void
    {
        $readOnly = JwtFactory::apiToken(['scope' => 'magazyn:items:read']);

        $this->api('GET', '/api/v1/products', token: $readOnly);
        $this->assertSame(200, $this->responseStatus());

        $body = $this->api('POST', '/api/v1/products', ['ean' => '5900617003441', 'name' => 'Mleko'], $readOnly);
        $this->assertSame(403, $this->responseStatus());
        $this->assertSame('insufficient_scope', $body['code']);
        $this->assertSame('magazyn:items:write', $body['requiredScope']);

        $body = $this->api('POST', '/api/v1/groups', ['name' => 'Kawa'], JwtFactory::apiToken(['scope' => 'magazyn:items:read magazyn:items:write']));
        $this->assertSame(403, $this->responseStatus());
        $this->assertSame('magazyn:items:admin', $body['requiredScope']);
    }

    public function testTokenWithoutModuleScopesCannotReadAnything(): void
    {
        $body = $this->api('GET', '/api/v1/products', token: JwtFactory::apiToken(['scope' => 'shopping:list:read']));

        $this->assertSame(403, $this->responseStatus());
        $this->assertSame('insufficient_scope', $body['code']);
    }

    public function testScopeMatchingIsExact(): void
    {
        $this->api('GET', '/api/v1/products', token: JwtFactory::apiToken(['scope' => 'magazyn:items:read2 xmagazyn:items:read']));

        $this->assertSame(403, $this->responseStatus());
    }

    public function testUnknownPathIsProblemJsonNot404Page(): void
    {
        $body = $this->api('GET', '/api/v1/nic', token: false);

        $this->assertSame(404, $this->responseStatus());
        $this->assertSame('not_found', $body['code']);
        $this->assertStringStartsWith('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testMethodNotAllowedKeepsProblemFormat(): void
    {
        $body = $this->api('DELETE', '/api/v1/products', token: false);

        $this->assertSame(405, $this->responseStatus());
        $this->assertSame('method_not_allowed', $body['code']);
        $this->assertNotEmpty($this->client->getResponse()->headers->get('Allow'));
    }

    public function testCorrelationIdIsEchoedOrGenerated(): void
    {
        $this->api('GET', '/api/v1/products', token: false, server: ['HTTP_X_CORRELATION_ID' => 'abc-123']);
        $this->assertSame('abc-123', $this->client->getResponse()->headers->get('X-Correlation-Id'));

        $body = $this->api('GET', '/api/v1/products', token: false, server: ['HTTP_X_CORRELATION_ID' => 'zły id ze spacją']);
        $this->assertNotSame('zły id ze spacją', $this->client->getResponse()->headers->get('X-Correlation-Id'));
        $this->assertSame($this->client->getResponse()->headers->get('X-Correlation-Id'), $body['correlationId']);
    }

    public function testMemberIsCreatedJustInTimeAndNameKeptWithPolishCharacters(): void
    {
        $this->api('GET', '/api/v1/products');
        $this->assertSame([JwtFactory::SUB => 'Ala Żółć'], $this->db()->fetchAllKeyValue('SELECT id, name FROM members'));

        $this->api('GET', '/api/v1/products', token: JwtFactory::apiToken(['name' => 'Alicja Gęślą']));
        $this->assertSame([JwtFactory::SUB => 'Alicja Gęślą'], $this->db()->fetchAllKeyValue('SELECT id, name FROM members'));
    }

    public function testRejectedTokenDoesNotCreateMember(): void
    {
        $this->api('GET', '/api/v1/products', token: JwtFactory::apiToken(['aud' => 'x']));

        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM members'));
    }

    public function testMalformedBodiesGive400(): void
    {
        $this->api('POST', '/api/v1/groups', null, null, ['CONTENT_TYPE' => 'application/json']);
        $this->assertSame(400, $this->responseStatus());

        $this->client->request('POST', '/api/v1/groups', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . JwtFactory::apiToken()], '{nie json');
        $this->assertSame(400, $this->responseStatus());

        $this->client->request('POST', '/api/v1/groups', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . JwtFactory::apiToken()], '["a"]');
        $this->assertSame(400, $this->responseStatus());

        $this->client->request('POST', '/api/v1/groups', [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_AUTHORIZATION' => 'Bearer ' . JwtFactory::apiToken()], '{"name":"x"}');
        $this->assertSame(400, $this->responseStatus());
        $this->assertSame('bad_request', json_decode($this->client->getResponse()->getContent(), true)['code']);
    }
}
