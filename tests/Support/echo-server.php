<?php

// Router for PHP's built-in web server, used by StreamHttpClientTest. It
// answers on 127.0.0.1 only and echoes what it received.

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (preg_match('#/status/(\d{3})(/|$)#', $path, $matches) === 1) {
    http_response_code((int) $matches[1]);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'status ' . $matches[1]]);

    return true;
}

if (str_ends_with($path, '/redirect')) {
    header('Location: /siren/v1/echo', true, 302);

    return true;
}

$headers = [];

foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}

if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}

http_response_code(200);
header('Content-Type: application/json');
header('X-Siren-OID: 404040');
header('X-Repeated: one');
header('X-Repeated: two', false);

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $path,
    'query' => $_SERVER['QUERY_STRING'] ?? '',
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
]);

return true;
