<?php

namespace App\Hub\Oidc;

/** Odpowiedź huba odrzucająca żądanie (`error` z RFC 6749) albo niepoprawna odpowiedź. */
final class OidcException extends \RuntimeException
{
    public function __construct(public readonly string $error, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $error);
    }

    /** `invalid_grant` i `invalid_client` przy odświeżeniu oznaczają koniec sesji magazynu. */
    public function endsSession(): bool
    {
        return in_array($this->error, ['invalid_grant', 'invalid_client'], true);
    }
}
