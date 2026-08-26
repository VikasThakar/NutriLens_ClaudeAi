<?php

use Illuminate\Support\Facades\Route;

/*
| A closure route cannot be serialised, and `php artisan route:cache` — which
| the container runs on boot — aborts on the first one it finds. `Route::view`
| expresses the same thing in a cacheable form.
*/
Route::view('/', 'welcome');
