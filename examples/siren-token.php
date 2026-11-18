<?php
require __DIR__ . '/../vendor/autoload.php';

use Siren\Sdk\Siren;

$siren = new Siren([
    'apiKey'  => getenv('SIREN_API_KEY'),
    'baseUrl' => getenv('SIREN_BASE_URL') ?: Siren::DEFAULT_BASE_URL,
]);

$siren->events->serveBrowserToken(['events' => ['signup-completed']]);
