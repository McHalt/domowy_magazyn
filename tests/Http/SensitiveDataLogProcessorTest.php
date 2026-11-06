<?php

namespace App\Tests\Http;

use App\Http\SensitiveDataLogProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class SensitiveDataLogProcessorTest extends TestCase
{
    private function process(string $message, array $context = [], array $extra = []): LogRecord
    {
        return (new SensitiveDataLogProcessor())(new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, $message, $context, $extra));
    }

    public function testCallbackQueryIsStripped(): void
    {
        $record = $this->process('GET /callback?code=SEKRET&state=ABC zakończone');

        $this->assertSame('GET /callback zakończone', $record->message);
    }

    public function testLegacyApiPasswordIsMasked(): void
    {
        $this->assertSame(
            'GET /api/index.php?p=Main&pwd=***&x=1',
            $this->process('GET /api/index.php?p=Main&pwd=tajne-haslo&x=1')->message,
        );
    }

    public function testBearerTokensAndJwtsAreMasked(): void
    {
        $jwt = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxMjMifQ.c2lnbmF0dXJl';

        $record = $this->process("Authorization: Bearer $jwt", ['header' => "Bearer $jwt", 'raw' => $jwt]);

        $this->assertSame('Authorization: Bearer ***', $record->message);
        $this->assertSame('Bearer ***', $record->context['header']);
        $this->assertSame('***jwt***', $record->context['raw']);
    }

    public function testSensitiveContextKeysAreMaskedAtAnyDepth(): void
    {
        $record = $this->process('x', ['refresh_token' => 'rt', 'nested' => ['client_secret' => 's', 'ok' => 'widoczne']], ['Authorization' => 'Basic abc']);

        $this->assertSame('***', $record->context['refresh_token']);
        $this->assertSame('***', $record->context['nested']['client_secret']);
        $this->assertSame('widoczne', $record->context['nested']['ok']);
        $this->assertSame('***', $record->extra['Authorization']);
    }

    public function testEmailKeepsOnlyDomain(): void
    {
        $this->assertSame('Logowanie ***@example.org', $this->process('Logowanie ala@example.org')->message);
    }

    public function testExceptionMessagesAreRedacted(): void
    {
        $record = $this->process('x', ['exception' => new \RuntimeException('GET /callback?code=SEKRET')]);

        $this->assertSame('GET /callback', $record->context['exception']['message']);
    }

    public function testPlainMessagesAreUntouched(): void
    {
        $this->assertSame('Pobranie JWKS nie powiodło się', $this->process('Pobranie JWKS nie powiodło się')->message);
    }
}
