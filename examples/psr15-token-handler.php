<?php
require __DIR__ . '/../vendor/autoload.php';

use Siren\Sdk\Siren;

$siren = new Siren(); // the app's key and Siren's address, from SIREN_API_KEY and SIREN_BASE_URL

$handler = $siren->events->browserTokenHandler(['events' => ['signup-completed']]);

// Slim 4:   $app->map(['GET', 'POST'], '/siren/token', $handler);
// Mezzio:   $app->route('/siren/token', $handler, ['GET', 'POST']);
