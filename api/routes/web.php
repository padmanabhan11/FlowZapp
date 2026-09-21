<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// The API has no web UI; the Angular app lives at FRONTEND_URL.
Route::get('/', fn () => redirect()->away((string) config('flowzapp.frontend_url')));
