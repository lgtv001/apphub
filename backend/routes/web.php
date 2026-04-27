<?php

use App\Http\Controllers\OAuthController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app/login.html');

Route::get('/auth/{provider}/redirect', [OAuthController::class, 'redirect']);
Route::get('/auth/{provider}/callback', [OAuthController::class, 'callback']);
