<?php

namespace App\Tests\Hub\Events;

use App\Hub\Events\HmacSigner;
use PHPUnit\Framework\TestCase;

final class HmacSignerTest extends TestCase
{
    public function testSignatureMatchesVectorFromHubBrief(): void
    {
        $body = '{"id":"evt-0001","type":"dummy.item_added","occurredAt":"2026-10-30T10:30:00Z","payload":{"title":"Mleko","message":"Anna dodała: Mleko"}}';

        $signature = HmacSigner::sign('hJ0tY7qk2N3vXw9Lc4bRz8sPmA1eGf6uDi5oKj0nTlU', '1793349000', $body);

        $this->assertSame('55a890f679c1178d92c6a56fe3b36a4471c7e2c825ca26d19811ca1d1534e097', $signature);
    }

    public function testKeyIsUsedAsTextWithoutBase64Decoding(): void
    {
        $key = 'hJ0tY7qk2N3vXw9Lc4bRz8sPmA1eGf6uDi5oKj0nTlU';

        $this->assertNotSame(
            HmacSigner::sign($key, '1', 'x'),
            HmacSigner::sign((string) base64_decode($key), '1', 'x'),
        );
    }

    public function testTimestampAndBodyBothChangeSignature(): void
    {
        $this->assertNotSame(HmacSigner::sign('k', '1', 'a'), HmacSigner::sign('k', '2', 'a'));
        $this->assertNotSame(HmacSigner::sign('k', '1', 'a'), HmacSigner::sign('k', '1', 'b'));
    }
}
