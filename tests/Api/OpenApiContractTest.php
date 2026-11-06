<?php

namespace App\Tests\Api;

use App\Tests\Support\HubTestCase;
use App\Tests\Support\JwtFactory;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/** Odpowiedzi API muszą zgadzać się z docs/hub/openapi.yaml: kształt, kod statusu i typ treści. */
final class OpenApiContractTest extends HubTestCase
{
    private object $spec;
    private Validator $validator;
    /** @var array<string, true> */
    private array $covered = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->spec = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/hub/openapi.yaml', Yaml::PARSE_OBJECT_FOR_MAP);
        $this->validator = new Validator();
    }

    private function resolve(object $node): object
    {
        while (isset($node->{'$ref'})) {
            $node = $this->pointer($node->{'$ref'});
        }

        return $node;
    }

    private function pointer(string $ref): object
    {
        $node = $this->spec;
        foreach (explode('/', ltrim($ref, '#/')) as $part) {
            $node = $node->{$part};
        }

        return $node;
    }

    private function assertValid(mixed $data, object $schema, string $where): void
    {
        $root = (object) ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'components' => $this->spec->components];
        foreach ((array) $schema as $key => $value) {
            $root->{$key} = $value;
        }

        $result = $this->validator->validate($data, $root);
        if (!$result->isValid()) {
            $this->fail($where . ': ' . json_encode((new \Opis\JsonSchema\Errors\ErrorFormatter())->format($result->error()), JSON_UNESCAPED_UNICODE) . ' dla ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        $this->addToAssertionCount(1);
    }

    /**
     * Wykonuje wywołanie i sprawdza odpowiedź względem specyfikacji operacji `$method $template`.
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $template, string $path, ?array $json = null, string|false|null $token = null, int $expectedStatus = 200): array
    {
        $body = $this->api($method, '/api/v1' . $path, $json, $token);
        $this->assertSame($expectedStatus, $this->responseStatus(), "$method $path: " . json_encode($body));
        $this->conform($method, $template);

        return $body;
    }

    /** Ostatnia odpowiedź musi być zadeklarowana w specyfikacji operacji (status, typ treści, schemat). */
    private function conform(string $method, string $template): void
    {
        $status = $this->responseStatus();
        $operation = $this->spec->paths->{$template}->{strtolower($method)} ?? null;
        $this->assertNotNull($operation, "$method $template nie jest w specyfikacji");
        $declared = $operation->responses->{(string) $status} ?? null;
        $this->assertNotNull($declared, "$method $template: status $status nie jest zadeklarowany w specyfikacji");

        $response = $this->resolve($declared);
        $contentType = strtolower(explode(';', $this->client->getResponse()->headers->get('Content-Type'))[0]);
        $this->assertTrue(isset($response->content->{$contentType}), "$method $template $status: typ treści $contentType niezadeklarowany");
        $this->assertValid(json_decode($this->client->getResponse()->getContent()), $response->content->{$contentType}->schema, "$method $template $status");

        $this->covered["$method $template $status"] = true;
    }

    public function testSpecExamplesMatchTheirSchemas(): void
    {
        $checked = 0;
        foreach ($this->spec->paths as $template => $item) {
            foreach (['get', 'post', 'put', 'patch'] as $method) {
                $operation = $item->{$method} ?? null;
                if ($operation === null) {
                    continue;
                }
                $contents = [];
                if (isset($operation->requestBody)) {
                    $contents["$method $template request"] = $operation->requestBody->content->{'application/json'};
                }
                foreach ($operation->responses as $status => $response) {
                    foreach ($this->resolve($response)->content ?? [] as $type => $content) {
                        $contents["$method $template $status $type"] = $content;
                    }
                }
                foreach ($contents as $where => $content) {
                    if (isset($content->example)) {
                        $this->assertValid($content->example, $content->schema, "przykład $where");
                        $checked++;
                    }
                }
            }
        }
        foreach ($this->spec->components->schemas as $name => $schema) {
            if (isset($schema->example)) {
                $this->assertValid($schema->example, (object) ['$ref' => "#/components/schemas/$name"], "przykład schematu $name");
                $checked++;
            }
        }

        $this->assertGreaterThan(15, $checked);
    }

    public function testEveryOperationAndDeclaredNon500StatusIsExercisedAndMatchesSpec(): void
    {
        $readOnly = JwtFactory::apiToken(['scope' => 'magazyn:items:read']);
        $writeOnly = JwtFactory::apiToken(['scope' => 'magazyn:items:read magazyn:items:write']);

        // Grupy
        $group = $this->call('POST', '/groups', '/groups', ['name' => 'Makaron', 'unit' => 'g'], null, 201);
        $this->call('POST', '/groups', '/groups', ['name' => ''], null, 422);
        $this->call('POST', '/groups', '/groups', null, JwtFactory::apiToken(['scope' => 'magazyn:items:read']), 403);
        $this->call('POST', '/groups', '/groups', ['name' => 'x'], false, 401);
        $this->call('POST', '/groups', '/groups', null, null, 400);

        // Produkty
        $created = $this->call('POST', '/products', '/products', [
            'ean' => '5900617003441', 'name' => 'Penne', 'producer' => 'Barilla', 'unit' => 'g', 'amountPerPackage' => 500, 'groupIds' => [$group['id']],
        ], null, 201);
        $id = $created['id'];
        $this->call('POST', '/products', '/products', ['ean' => '5900617003441', 'name' => 'Inny'], null, 409);
        $this->call('POST', '/products', '/products', ['ean' => 'x'], null, 422);
        $this->call('POST', '/products', '/products', ['ean' => '1', 'name' => 'x'], $readOnly, 403);

        $this->call('GET', '/products', '/products', null, null, 200);
        $this->call('GET', '/products', '/products?limit=0', null, null, 422);
        $this->call('GET', '/products', '/products', null, false, 401);
        $this->call('GET', '/products/{productId}', "/products/$id", null, null, 200);
        $this->call('GET', '/products/{productId}', '/products/999999', null, null, 404);

        $this->call('PATCH', '/products/{productId}', "/products/$id", ['producer' => null], null, 200);
        $this->call('PATCH', '/products/{productId}', "/products/$id", [], null, 422);
        $this->call('PATCH', '/products/{productId}', '/products/999999', ['name' => 'x'], null, 404);

        // Zapas
        $added = $this->call('POST', '/products/{productId}/stock', "/products/$id/stock", ['quantity' => 3, 'costMinor' => 649, 'expirationDate' => '2027-03-01'], null, 201);
        $this->assertCount(3, $added['items']);
        $this->call('POST', '/products/{productId}/stock', "/products/$id/stock", ['quantity' => 0], null, 422);
        $this->call('POST', '/products/{productId}/stock', '/products/999999/stock', null, null, 404);
        $this->call('POST', '/products/{productId}/consume', "/products/$id/consume", ['quantity' => 1], $writeOnly, 200);
        $this->call('POST', '/products/{productId}/consume', "/products/$id/consume", ['quantity' => 50], null, 409);
        $this->call('POST', '/products/{productId}/consume', "/products/$id/consume", ['quantity' => 0], null, 422);

        // Stany minimalne
        $this->call('PUT', '/products/{productId}/min-stock', "/products/$id/min-stock", ['packages' => 5, 'amount' => null], null, 200);
        $this->call('PUT', '/products/{productId}/min-stock', "/products/$id/min-stock", ['packages' => 5], null, 422);
        $this->call('PUT', '/products/{productId}/min-stock', "/products/$id/min-stock", ['packages' => 5, 'amount' => null], $writeOnly, 403);
        $this->call('PUT', '/products/{productId}/min-stock', '/products/999999/min-stock', ['packages' => 1, 'amount' => null], null, 404);

        // Grupy c.d.
        $this->call('GET', '/groups', '/groups', null, null, 200);
        $this->call('GET', '/groups', '/groups?limit=201', null, null, 422);
        $this->call('GET', '/groups/{groupId}', "/groups/{$group['id']}", null, null, 200);
        $this->call('GET', '/groups/{groupId}', '/groups/999999', null, null, 404);
        $this->call('PATCH', '/groups/{groupId}', "/groups/{$group['id']}", ['unit' => 'kg'], null, 200);
        $this->call('PATCH', '/groups/{groupId}', "/groups/{$group['id']}", ['unit' => 'ml'], null, 422);
        $this->call('PATCH', '/groups/{groupId}', '/groups/999999', ['name' => 'x'], null, 404);
        $this->call('PUT', '/groups/{groupId}/min-stock', "/groups/{$group['id']}/min-stock", ['packages' => null, 'amount' => 2], null, 200);
        $this->call('PUT', '/groups/{groupId}/min-stock', "/groups/{$group['id']}/min-stock", ['packages' => 'a', 'amount' => null], null, 422);

        // Alerty i wyszukiwanie
        $alerts = $this->call('GET', '/alerts', '/alerts', null, null, 200);
        $this->assertNotEmpty($alerts['items']);
        $this->call('GET', '/alerts', '/alerts?type=zly', null, null, 422);
        $this->call('GET', '/stocks', '/stocks?name=Penne', null, null, 200);
        $this->call('GET', '/stocks', '/stocks', null, null, 422);
        $this->call('POST', '/products/{productId}/consume', '/products/999999/consume', ['quantity' => 1], null, 404);
        $this->call('PUT', '/groups/{groupId}/min-stock', '/groups/999999/min-stock', ['packages' => 1, 'amount' => null], null, 404);

        $this->exerciseCommonErrors($id, $group['id']);
        $this->assertDeclaredStatusesCovered();
    }

    /** 401, 403 i 400 są wspólne dla operacji, więc wywołujemy je po kolei dla każdej operacji ze specyfikacji. */
    private function exerciseCommonErrors(int $productId, int $groupId): void
    {
        foreach ($this->spec->paths as $template => $item) {
            foreach (['get', 'post', 'put', 'patch'] as $method) {
                $operation = $item->{$method} ?? null;
                if ($operation === null) {
                    continue;
                }
                $verb = strtoupper($method);
                $fake = '/api/v1' . str_replace(['{productId}', '{groupId}'], '999999', $template);
                $real = '/api/v1' . str_replace(['{productId}', '{groupId}'], [(string) $productId, (string) $groupId], $template);

                if (isset($operation->responses->{'401'})) {
                    $this->api($method === 'get' ? 'GET' : $verb, $fake, null, false);
                    $this->assertSame(401, $this->responseStatus(), "$verb $template");
                    $this->conform($verb, $template);
                }
                if (isset($operation->responses->{'403'})) {
                    $this->api($verb, $fake, null, JwtFactory::apiToken(['scope' => 'shopping:list:read']));
                    $this->assertSame(403, $this->responseStatus(), "$verb $template");
                    $this->conform($verb, $template);
                }
                if (isset($operation->responses->{'400'})) {
                    $this->client->request($verb, $real, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . JwtFactory::apiToken()], '{nie json');
                    $this->assertSame(400, $this->responseStatus(), "$verb $template");
                    $this->conform($verb, $template);
                }
            }
        }
    }

    private function assertDeclaredStatusesCovered(): void
    {
        $missing = [];
        foreach ($this->spec->paths as $template => $item) {
            foreach (['get', 'post', 'put', 'patch'] as $method) {
                foreach ($item->{$method}->responses ?? [] as $status => $_) {
                    if ($status !== '500' && !isset($this->covered[strtoupper($method) . " $template $status"])) {
                        $missing[] = strtoupper($method) . " $template $status";
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Zadeklarowane odpowiedzi, których test nie wywołał');
    }
}
