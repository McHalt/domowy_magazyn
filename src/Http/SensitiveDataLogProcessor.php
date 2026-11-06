<?php

namespace App\Http;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Wycina z logów sekrety: zapytania /callback (code, state), hasło legacy API (?pwd=), tokeny Bearer i JWT
 * oraz adresy e-mail (zostaje sama domena).
 */
#[AsMonologProcessor]
final class SensitiveDataLogProcessor implements ProcessorInterface
{
    private const PATTERNS = [
        '~(/callback)\?[^#\s"\'<>]*~' => '$1',
        '~([?&]pwd=)[^&#\s"\'<>]*~i' => '$1***',
        '~(Bearer\s+)[A-Za-z0-9._\~+/=-]+~i' => '$1***',
        '~eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*~' => '***jwt***',
        '~[A-Za-z0-9._%+-]+@((?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,})~' => '***@$1',
    ];
    private const SENSITIVE_KEYS = ['authorization', 'client_secret', 'refresh_token', 'access_token', 'id_token', 'code_verifier', 'password', 'pwd'];
    private const MAX_DEPTH = 8;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::redactString($record->message),
            context: self::redactArray($record->context, 0),
            extra: self::redactArray($record->extra, 0),
        );
    }

    public static function redactString(string $value): string
    {
        return preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value) ?? '***';
    }

    private static function redactArray(array $values, int $depth): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)
                ? '***'
                : self::redact($value, $depth + 1);
        }

        return $values;
    }

    private static function redact(mixed $value, int $depth): mixed
    {
        if (is_string($value)) {
            return self::redactString($value);
        }
        if (is_array($value)) {
            return $depth < self::MAX_DEPTH ? self::redactArray($value, $depth) : '[too deep, redacted]';
        }
        if ($value instanceof \Throwable) {
            return self::redactedThrowable($value);
        }
        if ($value instanceof \Stringable) {
            return self::redactString((string) $value);
        }

        return $value;
    }

    private static function redactedThrowable(\Throwable $throwable): array
    {
        $redacted = [
            'class' => $throwable::class,
            'message' => self::redactString($throwable->getMessage()),
            'code' => $throwable->getCode(),
            'file' => $throwable->getFile() . ':' . $throwable->getLine(),
        ];
        if ($throwable->getPrevious() !== null) {
            $redacted['previous'] = self::redactedThrowable($throwable->getPrevious());
        }

        return $redacted;
    }
}
