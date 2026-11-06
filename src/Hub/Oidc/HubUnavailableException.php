<?php

namespace App\Hub\Oidc;

/** Błąd sieci, przekroczenie czasu albo 5xx huba: sesja domownika zostaje nienaruszona. */
final class HubUnavailableException extends \RuntimeException {}
