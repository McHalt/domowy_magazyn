<?php

namespace App\Tests\Api;

use App\Tests\Support\HubTestCase;
use App\Tests\Support\JwtFactory;

final class ProductsApiTest extends HubTestCase
{
    private function createProduct(array $overrides = []): array
    {
        $body = $this->api('POST', '/api/v1/products', $overrides + [
            'ean' => (string) random_int(10_000_000, 99_999_999),
            'name' => 'Penne',
            'producer' => 'Barilla',
            'unit' => 'g',
            'amountPerPackage' => 500,
        ]);
        $this->assertSame(201, $this->responseStatus(), json_encode($body));

        return $body;
    }

    private function createGroup(string $name, ?string $unit = null): array
    {
        $body = $this->api('POST', '/api/v1/groups', ['name' => $name, 'unit' => $unit]);
        $this->assertSame(201, $this->responseStatus(), json_encode($body));

        return $body;
    }

    public function testCreateReturns201WithLocationAndFullProduct(): void
    {
        $group = $this->createGroup('Makaron', 'g');

        $body = $this->api('POST', '/api/v1/products', [
            'ean' => '5900617003441', 'name' => 'Penne', 'producer' => 'Barilla', 'unit' => 'g',
            'amountPerPackage' => 500, 'groupIds' => [$group['id']],
        ]);

        $this->assertSame(201, $this->responseStatus());
        $this->assertSame('/api/v1/products/' . $body['id'], $this->client->getResponse()->headers->get('Location'));
        $this->assertSame('5900617003441', $body['ean']);
        $this->assertSame('Penne', $body['name']);
        $this->assertSame('Barilla', $body['producer']);
        $this->assertSame('g', $body['unit']);
        $this->assertSame(500, $body['amountPerPackage']);
        $this->assertSame(0, $body['quantity']);
        $this->assertSame(0, $body['totalAmount']);
        $this->assertNull($body['lastCostMinor']);
        $this->assertSame([['id' => $group['id'], 'name' => 'Makaron']], $body['groups']);
        $this->assertSame(['packages' => null, 'amount' => null], $body['minStock']);
        $this->assertFalse($body['belowMinimum']);
        $this->assertSame([], $body['items']);
    }

    public function testCreateValidation(): void
    {
        $body = $this->api('POST', '/api/v1/products', ['ean' => '12ab', 'producer' => 5, 'unit' => 'kilo', 'amountPerPackage' => -1, 'groupIds' => [999], 'xyz' => 1]);

        $this->assertSame(422, $this->responseStatus());
        $this->assertSame('validation_failed', $body['code']);
        $fields = array_column($body['errors'], 'message', 'field');
        $this->assertEqualsCanonicalizing(['ean', 'name', 'producer', 'unit', 'amountPerPackage', 'xyz'], array_keys($fields));
        $this->assertSame('Nazwa jest wymagana', $fields['name']);
        $this->assertStringContainsString('1–14 cyfr', $fields['ean']);
    }

    public function testCreateRejectsAmountWithoutUnitAndUnknownGroup(): void
    {
        $body = $this->api('POST', '/api/v1/products', ['ean' => '111', 'name' => 'X', 'amountPerPackage' => 2]);
        $this->assertSame(422, $this->responseStatus());
        $this->assertSame('amountPerPackage', $body['errors'][0]['field']);

        $body = $this->api('POST', '/api/v1/products', ['ean' => '111', 'name' => 'X', 'groupIds' => [12345]]);
        $this->assertSame(422, $this->responseStatus());
        $this->assertSame('groupIds', $body['errors'][0]['field']);
    }

    public function testCreateRejectsUnitIncompatibleWithGroup(): void
    {
        $group = $this->createGroup('Płyny', 'ml');

        $body = $this->api('POST', '/api/v1/products', ['ean' => '222', 'name' => 'Mąka', 'unit' => 'g', 'groupIds' => [$group['id']]]);

        $this->assertSame(422, $this->responseStatus());
        $this->assertSame('unit', $body['errors'][0]['field']);
        $this->assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM products'));
    }

    public function testDuplicateEanGives409WithExistingId(): void
    {
        $first = $this->createProduct(['ean' => '5900617003441']);

        $body = $this->api('POST', '/api/v1/products', ['ean' => '5900617003441', 'name' => 'Inny']);

        $this->assertSame(409, $this->responseStatus());
        $this->assertSame('ean_taken', $body['code']);
        $this->assertSame($first['id'], $body['productId']);
    }

    public function testShowAndNotFound(): void
    {
        $created = $this->createProduct();

        $this->assertSame($created['id'], $this->api('GET', '/api/v1/products/' . $created['id'])['id']);

        $body = $this->api('GET', '/api/v1/products/999999');
        $this->assertSame(404, $this->responseStatus());
        $this->assertSame('not_found', $body['code']);

        $this->api('GET', '/api/v1/products/abc');
        $this->assertSame(404, $this->responseStatus());
    }

    public function testListIsSortedByNameAndPaginatedWithCursor(): void
    {
        foreach (['Żurek', 'ser', 'Ogórki', 'ananas', 'Mleko'] as $i => $name) {
            $this->createProduct(['ean' => (string) (1000 + $i), 'name' => $name, 'producer' => null]);
        }

        $names = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->api('GET', '/api/v1/products?limit=2' . ($cursor ? '&cursor=' . $cursor : ''));
            $this->assertSame(200, $this->responseStatus());
            $this->assertArrayHasKey('nextCursor', $page);
            $names = array_merge($names, array_column($page['items'], 'name'));
            $cursor = $page['nextCursor'];
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame(['ananas', 'Mleko', 'Ogórki', 'ser', 'Żurek'], $names);
        $this->assertSame(3, $pages);
    }

    public function testCursorSurvivesInsertionOfEarlierItem(): void
    {
        foreach (['b', 'c', 'd'] as $i => $name) {
            $this->createProduct(['ean' => (string) (2000 + $i), 'name' => $name]);
        }
        $first = $this->api('GET', '/api/v1/products?limit=1');
        $this->createProduct(['ean' => '2999', 'name' => 'a']);

        $second = $this->api('GET', '/api/v1/products?limit=2&cursor=' . $first['nextCursor']);

        $this->assertSame(['c', 'd'], array_column($second['items'], 'name'));
        $this->assertNull($second['nextCursor']);
    }

    public function testListPaginationParametersAreValidated(): void
    {
        foreach (['limit=0', 'limit=201', 'limit=abc', 'limit=-1', 'cursor=%25%25', 'cursor=bm90LWpzb24'] as $query) {
            $body = $this->api('GET', '/api/v1/products?' . $query);
            $this->assertSame(422, $this->responseStatus(), $query);
            $this->assertSame('validation_failed', $body['code']);
        }

        $this->api('GET', '/api/v1/products?limit=200');
        $this->assertSame(200, $this->responseStatus());
    }

    public function testArrayValuedQueryParametersGive400NotServerError(): void
    {
        foreach (['limit[]=1', 'cursor[]=a', 'q[]=a', 'ean[]=1', 'groupId[]=1', 'inStock[]=true'] as $query) {
            $body = $this->api('GET', '/api/v1/products?' . $query);
            $this->assertSame(400, $this->responseStatus(), $query);
            $this->assertSame('bad_request', $body['code']);
        }
    }

    public function testListFilters(): void
    {
        $group = $this->createGroup('Makaron');
        $penne = $this->createProduct(['ean' => '0123456789012', 'name' => 'Penne', 'producer' => 'Barilla', 'groupIds' => [$group['id']]]);
        $this->createProduct(['ean' => '555', 'name' => 'Mleko', 'producer' => 'Łaciate']);
        $this->api('POST', '/api/v1/products/' . $penne['id'] . '/stock', ['quantity' => 2]);

        $names = fn(string $query) => array_column($this->api('GET', '/api/v1/products?' . $query)['items'], 'name');

        $this->assertSame(['Penne'], $names('q=' . rawurlencode('BARILLA  penne')));
        $this->assertSame(['Mleko'], $names('q=' . rawurlencode('laciate')));
        $this->assertSame(['Penne'], $names('ean=123456789012'), 'zera wiodące są pomijane');
        $this->assertSame(['Penne'], $names('groupId=' . $group['id']));
        $this->assertSame(['Penne'], $names('inStock=true'));
        $this->assertSame(['Mleko'], $names('inStock=false'));
        $this->assertSame([], $names('q=zzz'));

        foreach (['q=%21%21', 'ean=abc', 'groupId=0', 'inStock=tak'] as $invalid) {
            $this->api('GET', '/api/v1/products?' . $invalid);
            $this->assertSame(422, $this->responseStatus(), $invalid);
        }
    }

    public function testPatchUpdatesOnlyGivenFieldsAndClearsWithNull(): void
    {
        $group = $this->createGroup('Makaron', 'g');
        $created = $this->createProduct(['groupIds' => [$group['id']]]);

        $body = $this->api('PATCH', '/api/v1/products/' . $created['id'], ['producer' => null, 'amountPerPackage' => 400]);

        $this->assertSame(200, $this->responseStatus());
        $this->assertNull($body['producer']);
        $this->assertSame(400, $body['amountPerPackage']);
        $this->assertSame('Penne', $body['name']);
        $this->assertSame('g', $body['unit']);
        $this->assertCount(1, $body['groups']);

        $body = $this->api('PATCH', '/api/v1/products/' . $created['id'], ['groupIds' => []]);
        $this->assertSame([], $body['groups']);
    }

    public function testPatchValidation(): void
    {
        $created = $this->createProduct();

        $this->api('PATCH', '/api/v1/products/' . $created['id'], []);
        $this->assertSame(422, $this->responseStatus());

        $body = $this->api('PATCH', '/api/v1/products/' . $created['id'], ['name' => '', 'unit' => 'x', 'ean' => '1']);
        $this->assertSame(422, $this->responseStatus());
        $this->assertEqualsCanonicalizing(['name', 'unit', 'ean'], array_column($body['errors'], 'field'));

        $body = $this->api('PATCH', '/api/v1/products/' . $created['id'], ['unit' => null, 'amountPerPackage' => 3]);
        $this->assertSame(422, $this->responseStatus());
        $this->assertSame('amountPerPackage', $body['errors'][0]['field']);

        $this->api('PATCH', '/api/v1/products/999999', ['name' => 'x']);
        $this->assertSame(404, $this->responseStatus());
    }

    public function testChangingUnitToIncompatibleDimensionDropsAmountMinimum(): void
    {
        $created = $this->createProduct();
        $this->api('PUT', '/api/v1/products/' . $created['id'] . '/min-stock', ['packages' => null, 'amount' => 1000]);
        $this->assertSame(200, $this->responseStatus());

        $body = $this->api('PATCH', '/api/v1/products/' . $created['id'], ['unit' => 'ml']);

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame(['packages' => null, 'amount' => null], $body['minStock']);
    }

    public function testAddStockCreatesItemsWithAuthorAndCost(): void
    {
        $created = $this->createProduct();

        $body = $this->api('POST', '/api/v1/products/' . $created['id'] . '/stock', ['quantity' => 3, 'costMinor' => 649, 'expirationDate' => '2027-03-01']);

        $this->assertSame(201, $this->responseStatus());
        $this->assertCount(3, $body['items']);
        $this->assertSame(649, $body['items'][0]['costMinor']);
        $this->assertSame('2027-03-01', $body['items'][0]['expirationDate']);
        $this->assertSame(['id' => JwtFactory::SUB, 'name' => 'Ala Żółć'], $body['items'][0]['addedBy']);
        $this->assertSame(3, $body['product']['quantity']);
        $this->assertSame(1500, $body['product']['totalAmount']);
        $this->assertSame(649, $body['product']['lastCostMinor']);
        $this->assertCount(3, $body['product']['items']);
        $this->assertSame(3, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM products_history WHERE added_by = ?', [JwtFactory::SUB]));
    }

    public function testAddStockDefaultsAndValidation(): void
    {
        $created = $this->createProduct();

        $body = $this->api('POST', '/api/v1/products/' . $created['id'] . '/stock');
        $this->assertSame(201, $this->responseStatus());
        $this->assertCount(1, $body['items']);
        $this->assertSame(0, $body['items'][0]['costMinor']);
        $this->assertNull($body['items'][0]['expirationDate']);

        foreach ([['quantity' => 0], ['quantity' => 101], ['quantity' => '2'], ['quantity' => 1.5], ['costMinor' => -1], ['expirationDate' => '2027-02-30'], ['expirationDate' => '01.03.2027'], ['x' => 1]] as $invalid) {
            $this->api('POST', '/api/v1/products/' . $created['id'] . '/stock', $invalid);
            $this->assertSame(422, $this->responseStatus(), json_encode($invalid));
        }

        $this->api('POST', '/api/v1/products/999999/stock', ['quantity' => 1]);
        $this->assertSame(404, $this->responseStatus());
    }

    public function testConsumeTakesNearestExpirationFirstAndDatelessLast(): void
    {
        $created = $this->createProduct();
        $path = '/api/v1/products/' . $created['id'];
        $this->api('POST', $path . '/stock', ['expirationDate' => '2027-06-01']);
        $this->api('POST', $path . '/stock');
        $this->api('POST', $path . '/stock', ['expirationDate' => '2027-01-01']);

        $first = $this->api('POST', $path . '/consume', ['quantity' => 2]);

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame(['2027-01-01', '2027-06-01'], array_column($first['items'], 'expirationDate'));
        $this->assertSame(1, $first['product']['quantity']);
        $this->assertNull($first['product']['items'][0]['expirationDate']);
        $this->assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM products_history WHERE active = 0 AND removed_by = ? AND removed_at IS NOT NULL', [JwtFactory::SUB]));
    }

    public function testConsumeByExpirationDateAndInsufficientStock(): void
    {
        $created = $this->createProduct();
        $path = '/api/v1/products/' . $created['id'];
        $this->api('POST', $path . '/stock', ['quantity' => 2, 'expirationDate' => '2027-06-01']);
        $this->api('POST', $path . '/stock', ['quantity' => 1]);

        $body = $this->api('POST', $path . '/consume', ['quantity' => 3, 'expirationDate' => '2027-06-01']);
        $this->assertSame(409, $this->responseStatus());
        $this->assertSame('insufficient_stock', $body['code']);
        $this->assertSame(2, $body['available']);
        $this->assertSame(3, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM products_history WHERE active = 1'), 'nic nie zostało zdjęte');

        $body = $this->api('POST', $path . '/consume', ['expirationDate' => null]);
        $this->assertSame(200, $this->responseStatus());
        $this->assertNull($body['items'][0]['expirationDate']);
        $this->assertSame(2, $body['product']['quantity']);

        $this->api('POST', $path . '/consume', ['quantity' => 5]);
        $this->assertSame(409, $this->responseStatus());
    }

    public function testMinStockRequiresBothFieldsAndAdminScope(): void
    {
        $created = $this->createProduct();
        $path = '/api/v1/products/' . $created['id'] . '/min-stock';

        $this->api('PUT', $path, ['packages' => 2]);
        $this->assertSame(422, $this->responseStatus());

        $this->api('PUT', $path, ['packages' => 2, 'amount' => null], JwtFactory::apiToken(['scope' => 'magazyn:items:read magazyn:items:write']));
        $this->assertSame(403, $this->responseStatus());

        $body = $this->api('PUT', $path, ['packages' => 2, 'amount' => 1500]);
        $this->assertSame(200, $this->responseStatus());
        $this->assertSame(['packages' => 2, 'amount' => 1500], $body['minStock']);
        $this->assertTrue($body['belowMinimum']);

        $body = $this->api('PUT', $path, ['packages' => null, 'amount' => null]);
        $this->assertSame(['packages' => null, 'amount' => null], $body['minStock']);
        $this->assertFalse($body['belowMinimum']);
    }

    public function testMinAmountNeedsUnit(): void
    {
        $created = $this->createProduct(['unit' => null, 'amountPerPackage' => null]);

        $body = $this->api('PUT', '/api/v1/products/' . $created['id'] . '/min-stock', ['packages' => null, 'amount' => 100]);

        $this->assertSame(422, $this->responseStatus());
        $this->assertSame('amount', $body['errors'][0]['field']);
    }

    public function testLegacyHtmlEntitiesInNamesAreReturnedDecoded(): void
    {
        $created = $this->createProduct(['name' => 'Ben &amp; Jerry&#039;s']);
        $this->db()->executeStatement("UPDATE products_to_features SET value = 'Ben &amp; Jerry&#039;s' WHERE products_id = ? AND features_id = (SELECT id FROM features WHERE name = 'name')", [$created['id']]);

        $this->assertSame("Ben & Jerry's", $this->api('GET', '/api/v1/products/' . $created['id'])['name']);
    }

    public function testPolishCharactersRoundTrip(): void
    {
        $created = $this->createProduct(['name' => 'Żółta gęś', 'producer' => 'Łódzka Spółdzielnia']);

        $body = $this->api('GET', '/api/v1/products/' . $created['id']);

        $this->assertSame('Żółta gęś', $body['name']);
        $this->assertSame('Łódzka Spółdzielnia', $body['producer']);
        $this->assertStringContainsString('Żółta gęś', $this->client->getResponse()->getContent(), 'UTF-8 nie jest zamieniany na \\u');
    }
}
