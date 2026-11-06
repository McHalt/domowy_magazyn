<?php

namespace App\Tests\Api;

use App\Tests\Support\HubTestCase;

final class AlertsApiTest extends HubTestCase
{
    private function today(int $plusDays = 0): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->modify(sprintf('%+d days', $plusDays))->format('Y-m-d');
    }

    private function product(string $ean, string $name, array $extra = []): int
    {
        $body = $this->api('POST', '/api/v1/products', ['ean' => $ean, 'name' => $name] + $extra);
        $this->assertSame(201, $this->responseStatus(), json_encode($body));

        return $body['id'];
    }

    public function testExpiringAlertsGroupedByProductAndDateWithinSevenDays(): void
    {
        $milk = $this->product('4001', 'Mleko', ['producer' => 'Łaciate']);
        $rice = $this->product('4002', 'Ryż');
        $far = $this->product('4003', 'Makaron');
        $this->api('POST', "/api/v1/products/$milk/stock", ['quantity' => 2, 'expirationDate' => $this->today(1)]);
        $this->api('POST', "/api/v1/products/$rice/stock", ['expirationDate' => $this->today(-2)]);
        $this->api('POST', "/api/v1/products/$far/stock", ['expirationDate' => $this->today(8)]);
        $this->api('POST', "/api/v1/products/$far/stock");

        $body = $this->api('GET', '/api/v1/alerts');

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame(
            [
                ['type' => 'expiring', 'productId' => $rice, 'name' => 'Ryż', 'producer' => null, 'expirationDate' => $this->today(-2), 'daysLeft' => -2, 'quantity' => 1],
                ['type' => 'expiring', 'productId' => $milk, 'name' => 'Mleko', 'producer' => 'Łaciate', 'expirationDate' => $this->today(1), 'daysLeft' => 1, 'quantity' => 2],
            ],
            $body['items'],
        );
        $this->assertNull($body['nextCursor']);
    }

    public function testStockLowAlertsForProductsAndGroups(): void
    {
        $milk = $this->product('5001', 'Mleko');
        $this->api('PUT', "/api/v1/products/$milk/min-stock", ['packages' => 2, 'amount' => null]);
        $this->api('POST', "/api/v1/products/$milk/stock");

        $group = $this->api('POST', '/api/v1/groups', ['name' => 'Kawa', 'unit' => 'g'])['id'];
        $coffee = $this->product('5002', 'Kawa', ['unit' => 'g', 'amountPerPackage' => 250, 'groupIds' => [$group]]);
        $this->api('POST', "/api/v1/products/$coffee/stock", ['quantity' => 2]);
        $this->api('PUT', "/api/v1/groups/$group/min-stock", ['packages' => null, 'amount' => 1000]);

        $body = $this->api('GET', '/api/v1/alerts?type=stock_low');

        $this->assertSame(
            [
                ['type' => 'stock_low', 'subject' => 'group', 'subjectId' => $group, 'name' => 'Kawa', 'basis' => 'amount', 'current' => 500, 'minimum' => 1000, 'unit' => 'g'],
                ['type' => 'stock_low', 'subject' => 'product', 'subjectId' => $milk, 'name' => 'Mleko', 'basis' => 'packages', 'current' => 1, 'minimum' => 2, 'unit' => null],
            ],
            $body['items'],
        );
    }

    public function testTypeFilterAndPagination(): void
    {
        $milk = $this->product('6001', 'Mleko');
        $this->api('PUT', "/api/v1/products/$milk/min-stock", ['packages' => 1, 'amount' => null]);
        foreach (['6002', '6003', '6004'] as $ean) {
            $id = $this->product($ean, 'P' . $ean);
            $this->api('POST', "/api/v1/products/$id/stock", ['expirationDate' => $this->today(2)]);
        }

        $this->assertCount(1, $this->api('GET', '/api/v1/alerts?type=stock_low')['items']);

        $first = $this->api('GET', '/api/v1/alerts?type=expiring&limit=2');
        $second = $this->api('GET', '/api/v1/alerts?type=expiring&limit=2&cursor=' . $first['nextCursor']);
        $this->assertCount(2, $first['items']);
        $this->assertCount(1, $second['items']);
        $this->assertNull($second['nextCursor']);

        $this->api('GET', '/api/v1/alerts?type=inny');
        $this->assertSame(422, $this->responseStatus());
    }

    public function testStockLookupIgnoresDiacriticsCaseAndPunctuationAndPrefersGroups(): void
    {
        $flour = $this->product('7001', 'Mąka Tortowa');
        $this->api('POST', "/api/v1/products/$flour/stock", ['quantity' => 3]);
        $group = $this->api('POST', '/api/v1/groups', ['name' => 'maka tortowa!'])['id'];
        $this->api('POST', '/api/v1/products', ['ean' => '7002', 'name' => 'Inna', 'groupIds' => [$group]]);

        $body = $this->api('GET', '/api/v1/stocks?name=' . rawurlencode('MĄKA  tortowa'));

        $this->assertSame(200, $this->responseStatus());
        $this->assertSame('group', $body['type']);
        $this->assertSame([['id' => $group, 'name' => 'maka tortowa!', 'quantity' => 0]], $body['matches']);

        $body = $this->api('GET', '/api/v1/stocks?name=' . rawurlencode('Inna'));
        $this->assertSame('product', $body['type']);

        $body = $this->api('GET', '/api/v1/stocks?name=nieistnieje');
        $this->assertSame(200, $this->responseStatus());
        $this->assertSame(['type' => null, 'quantity' => 0, 'matches' => []], $body);
    }

    public function testStockLookupSumsProductsWithTheSameNormalizedName(): void
    {
        foreach (['8001' => 'Mleko 2%', '8002' => 'mleko 2'] as $ean => $name) {
            $id = $this->product((string) $ean, $name);
            $this->api('POST', "/api/v1/products/$id/stock", ['quantity' => 2]);
        }

        $body = $this->api('GET', '/api/v1/stocks?name=Mleko+2');

        $this->assertSame('product', $body['type']);
        $this->assertSame(4, $body['quantity']);
        $this->assertCount(2, $body['matches']);
    }

    public function testStockLookupRequiresName(): void
    {
        foreach (['/api/v1/stocks', '/api/v1/stocks?name=', '/api/v1/stocks?name=' . str_repeat('a', 201)] as $url) {
            $body = $this->api('GET', $url);
            $this->assertSame(422, $this->responseStatus(), $url);
            $this->assertSame('name', $body['errors'][0]['field']);
        }
    }
}
