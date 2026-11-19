<?php

// Router for PHP's built-in web server, used by ReadmeExamplesTest. It plays
// Siren's token route and a custom event's send address on 127.0.0.1, the way
// docs/architecture/apps-event-tokens.md (sections 1 and 2) describes them,
// and writes each request it gets to the file FAKE_SIREN_LOG names.

declare(strict_types=1);

const APP_KEY = 'sk_test_fake_app_key';

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

$log = getenv('FAKE_SIREN_LOG');

if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'path' => $path,
        'authorization' => $authorization,
        'body' => $body,
    ]) . "\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');

if ($path === '/siren/v1/oauth/token') {
    if (($body['grant_type'] ?? null) !== 'client_credentials' || ($body['client_secret'] ?? null) !== APP_KEY) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_client', 'error_description' => 'Siren doesn\'t know this key, or it was revoked.']);

        return true;
    }

    $scope = (string) ($body['scope'] ?? '');

    if ($scope === '') {
        echo json_encode(['access_token' => 'srv.fake-token', 'token_type' => 'Bearer', 'expires_in' => 1200]);

        return true;
    }

    if ($scope === 'browser:signup-completed') {
        echo json_encode(['access_token' => 'brw.fake-token', 'token_type' => 'Bearer', 'expires_in' => 600, 'scope' => $scope]);

        return true;
    }

    http_response_code(400);
    echo json_encode(['error' => 'invalid_scope', 'error_description' => 'This app has no active custom event "' . substr($scope, 8) . '".']);

    return true;
}

if (preg_match('#^/siren/v1/event/([^/]+)$#', $path, $matches) === 1) {
    // A server send carries the server token as a bearer, and a browser send
    // carries the browser token as ?access_token=.
    if ($authorization !== 'Bearer srv.fake-token' && ($_GET['access_token'] ?? null) !== 'brw.fake-token') {
        http_response_code(401);
        echo json_encode(['error' => 'This access token has expired.']);

        return true;
    }

    // Siren reads the collaborator who gets credit from the top of the body
    // (CustomEventIngestion::ingest()).
    if (!isset($body['collaboratorId'])) {
        http_response_code(400);
        echo json_encode(['error' => ['message' => 'collaboratorId is required', 'context' => ['type' => 'VALIDATION_FAILED']]]);

        return true;
    }

    header('X-Siren-OID: 9001');
    echo json_encode(['status' => 'recorded', 'event' => rawurldecode($matches[1])]);

    return true;
}

http_response_code(404);
echo json_encode(['error' => 'Unknown route']);

return true;
