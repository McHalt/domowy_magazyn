<?php

namespace App\Tests\Api;

use App\Tests\Support\HubTestCase;
use App\Tests\Support\JwtFactory;

final class GroupsApiTest extends HubTestCase
{
    private function product(string $ean, string $name, string $unit, int|float $perPackage, array $groupIds = []): array
    {
        $body = $this->api('POST', '/api/v1/products', [
            'ean' => $ean, 'name' => $name, 'unit' => $unit, 'amountPerPackage' => $perPackage, 'groupIds' => $groupIds,
        ]);
        $this->assertSame(201, $this->responseStatus(), json_encode($body));

        return $body;
    }

    public function testCreateRequiresAdminScope(): void
    {
        $this->api('POST', '/api/v1/groups', ['name' => 'Kawa'], JwtFactory::apiToken(['scope' => 'magazyn:items:read magazyn:items:write']));
        $this->assertSame(403, $this->responseStatus());

        $body = $this->api('POST', '/api/v1/groups', ['name' => 'Kawa', 'unit' => 'g']);

        $this->assertSame(201, $this->responseStatus());
        $this->assertSame('/api/v1/groups/' . $body['id'], $this->client->getResponse()->headers->get('Location'));
        $this->assertSame('Kawa', $body['name']);
        $this->assertSame('g', $body['unit']);
        $this->assertSame([], $body['products']);
        $this->assertSame(0, $body['totalAmount']);
    }

    public function testCreateValidation(): void
    {
        $body = $this->api('POST', '/api/v1/groups', ['name' => '  ', 'unit' => 'cal']);

        $this->assertSame(422, $this->responseStatus());
        $this->assertEqualsCanonicalizing(['name', 'unit'], array_column($body['errors'], 'field'));

        $this->api('POST', '/api/v1/groups', ['name' => str_repeat('x', 101)]);
        $this->assertSame(422, $this->responseStatus());
    }

    public function testGroupAggregatesQuantityAndAmountOfCountedProducts(): void
    {
        $group = $this->api('POST', '/api/v1/groups', ['name' => 'Makaron', 'unit' => 'kg'])['id'];
        $penne = $this->product('1001', 'Penne', 'g', 500, [$group]);
        $swiderki = $this->product('1002', 'Świderki', 'kg', 1, [$group]);
        $bez = $this->product('1003', 'Nitki', 'g', 250, [$group]);
        $this->api('POST', '/api/v1/products/' . $penne['id'] . '/stock', ['quantity' => 4]);
        $this->api('POST', '/api/v1/products/' . $swiderki['id'] . '/stock', ['quantity' => 2]);
        $this->api('PATCH', '/api/v1/products/' . $bez['id'], ['amountPerPackage' => null]);

        $body = $this->api('GET', '/api/v1/groups/' . $group);

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame(6, $body['quantity']);
        $this->assertSame(4, $body['totalAmount'], '4 × 500 g + 2 × 1 kg = 4 kg');
        $this->assertSame(3, $body['productCount']);
        $this->assertSame(['Nitki', 'Penne', 'Świderki'], array_column($body['products'], 'name'));
        $this->assertSame([false, true, true], array_column($body['products'], 'countedInAmount'));
    }

    public function testListIsSortedAndPaginated(): void
    {
        foreach (['Żywność', 'Ryż', 'mąka', 'Cukier'] as $name) {
            $this->api('POST', '/api/v1/groups', ['name' => $name]);
        }

        $first = $this->api('GET', '/api/v1/groups?limit=3');
        $second = $this->api('GET', '/api/v1/groups?limit=3&cursor=' . $first['nextCursor']);

        $this->assertSame(['Cukier', 'mąka', 'Ryż'], array_column($first['items'], 'name'));
        $this->assertSame(['Żywność'], array_column($second['items'], 'name'));
        $this->assertNull($second['nextCursor']);
        $this->assertArrayNotHasKey('products', $first['items'][0]);
    }

    public function testUnitChangeIsCheckedAgainstProducts(): void
    {
        $group = $this->api('POST', '/api/v1/groups', ['name' => 'Makaron', 'unit' => 'g'])['id'];
        $this->product('2001', 'Penne', 'g', 500, [$group]);

        $body = $this->api('PATCH', '/api/v1/groups/' . $group, ['unit' => 'ml']);
        $this->assertSame(422, $this->responseStatus());
        $this->assertSame('unit', $body['errors'][0]['field']);

        $body = $this->api('PATCH', '/api/v1/groups/' . $group, ['unit' => 'kg', 'name' => 'Makarony']);
        $this->assertSame(200, $this->responseStatus());
        $this->assertSame('kg', $body['unit']);
        $this->assertSame('Makarony', $body['name']);
    }

    public function testPatchValidationAndNotFound(): void
    {
        $group = $this->api('POST', '/api/v1/groups', ['name' => 'Kawa'])['id'];

        $this->api('PATCH', '/api/v1/groups/' . $group, []);
        $this->assertSame(422, $this->responseStatus());

        $this->api('PATCH', '/api/v1/groups/999999', ['name' => 'x']);
        $this->assertSame(404, $this->responseStatus());

        $this->api('GET', '/api/v1/groups/999999');
        $this->assertSame(404, $this->responseStatus());
    }

    public function testGroupMinStockInUnitOfGroupAndBelowMinimum(): void
    {
        $group = $this->api('POST', '/api/v1/groups', ['name' => 'Kawa', 'unit' => 'kg'])['id'];
        $kawa = $this->product('3001', 'Kawa ziarnista', 'g', 500, [$group]);
        $this->api('POST', '/api/v1/products/' . $kawa['id'] . '/stock', ['quantity' => 2]);

        $body = $this->api('PUT', '/api/v1/groups/' . $group . '/min-stock', ['packages' => null, 'amount' => 1.5]);

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame(['packages' => null, 'amount' => 1.5], $body['minStock']);
        $this->assertSame(1, $body['totalAmount']);
        $this->assertTrue($body['belowMinimum']);

        $this->api('PUT', '/api/v1/groups/' . $group . '/min-stock', ['packages' => null, 'amount' => 0.5]);
        $this->assertFalse($this->api('GET', '/api/v1/groups/' . $group)['belowMinimum']);
    }

    public function testGroupMinAmountNeedsGroupUnit(): void
    {
        $group = $this->api('POST', '/api/v1/groups', ['name' => 'Kawa'])['id'];

        $body = $this->api('PUT', '/api/v1/groups/' . $group . '/min-stock', ['packages' => null, 'amount' => 1]);

        $this->assertSame(422, $this->responseStatus());
        $this->assertSame('amount', $body['errors'][0]['field']);
    }
}
