<?php

namespace App\Hub\Jwt;

/** Przyczyna trafia do logu, nigdy do odpowiedzi. */
final class InvalidTokenException extends \RuntimeException {}
