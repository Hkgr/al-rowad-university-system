<?php

use Illuminate\Support\Facades\Route;

/*
| Laravel Breeze Blade auth (routes/auth.php) is intentionally not registered.
| GET /login must be served by the React SPA fallback below, not auth.login.
| API authentication remains POST /api/login (Bearer / Sanctum).
*/

Route::get('/{any?}', function () {
    $index = public_path('index.html');

    if (! is_file($index)) {
        abort(500, 'Frontend index.html not found at: ' . $index);
    }

    return response()->file($index, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
})->where('any', '^(?!api).*$');
