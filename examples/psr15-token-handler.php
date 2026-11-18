<?php
require __DIR__ . '/../vendor/autoload.php';

use Siren\Sdk\Siren;

$siren = new Siren([
    'apiKey'  => getenv('SIREN_API_KEY'),
    'baseUrl' => getenv('SIREN_BASE_URL') ?: Siren::DEFAULT_BASE_URL,
]);

$handler = $siren->events->browserTokenHandler(['events' => ['signup-completed']]);

// Slim 4:   $app->map(['GET', 'POST'], '/siren/token', $handler);
// Mezzio:   $app->route('/siren/token', $handler, ['GET', 'POST']);
