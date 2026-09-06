<?php

$body = file_get_contents('php://input');
$method = $_SERVER['REQUEST_METHOD'] ?? '';

header('Content-Type: application/json');
header('X-Request-Id: local-integration-test');
header('X-Seen-Method: ' . $method);
header('X-Request-Body-Length: ' . strlen(is_string($body) ? $body : ''));

echo json_encode([
    'method' => $method,
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
    'body' => $body,
], JSON_THROW_ON_ERROR);
