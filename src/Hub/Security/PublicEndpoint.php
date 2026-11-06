<?php

namespace App\Hub\Security;

/** Akcja dostępna bez logowania (healthcheck, trasy logowania, legacy API z własnym hasłem). */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class PublicEndpoint {}
