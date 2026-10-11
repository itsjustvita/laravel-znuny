<?php

// Router script for `php -S 0.0.0.0:8099 tests/Integration/docker/capture.php`:
// stores every request Znuny's requester sends.
$directory = __DIR__.'/captured';

if (! is_dir($directory)) {
    mkdir($directory, 0777, true);
}

file_put_contents(
    $directory.'/'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json',
    json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'headers' => function_exists('getallheaders') ? getallheaders() : [],
        'body' => json_decode((string) file_get_contents('php://input'), true),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
);

header('Content-Type: application/json');
echo '{}';
