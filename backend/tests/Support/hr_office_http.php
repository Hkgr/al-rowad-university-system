<?php

use Illuminate\Http\Request;

// Isolated synthetic-only Laravel HTTP router; never a production bootstrap.
$database = getenv('HR_TEST_DATABASE');
if (file_exists(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
    http_response_code(503);
    exit('Refusing cached configuration in the isolated HR HTTP harness.');
}
if (! $database || ! preg_match('/^codex_hr_office_test_[a-z0-9_]+$/', $database)) {
    http_response_code(503);
    exit('Explicit isolated HR test database required.');
}
foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('h', 32)), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => getenv('HR_TEST_PORT') ?: '3307', 'DB_DATABASE' => $database, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => getenv('HR_TEST_PASSWORD') ?: '', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->handleRequest(Request::capture());
