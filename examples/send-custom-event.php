<?php
require __DIR__ . '/../vendor/autoload.php';

use Siren\Sdk\Siren;

$siren = new Siren(); // the app's key and Siren's address, from SIREN_API_KEY and SIREN_BASE_URL

$result = $siren->events->ingest('signup-completed', [
    'collaboratorId' => 12,
    'plan'           => 'pro',
]);

echo $result->getStatus(), "\n";
