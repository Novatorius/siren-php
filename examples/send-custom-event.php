<?php
require __DIR__ . '/../vendor/autoload.php';

use Siren\Sdk\Siren;

$siren = new Siren([
    'apiKey'  => getenv('SIREN_API_KEY'), // the app's key
    'baseUrl' => getenv('SIREN_BASE_URL') ?: Siren::DEFAULT_BASE_URL,
]);

$result = $siren->events->ingest('signup-completed', [
    'collaboratorId' => 12,
    'plan'           => 'pro',
]);

echo $result->getStatus(), "\n";
