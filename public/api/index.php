<?php
// Punkt wejścia dla wstecznej kompatybilności API: /api/index.php?p=...
//
// Problem: gdy nginx serwuje ten plik, SCRIPT_NAME = /api/index.php
// Symfony wykrywa baseUrl = '/api/index.php', a pathInfo = '/' → matchuje MainController.
//
// Rozwiązanie: ustawiamy SCRIPT_NAME na wartość której basename != 'index.php',
// wtedy Symfony ustawia baseUrl = '' i pathInfo = '/api/index.php' → matchuje ApiController.

$_SERVER['SCRIPT_NAME'] = '/entry';
$_SERVER['PHP_SELF']    = '/entry';

use App\Kernel;

require_once dirname(__DIR__, 2).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
