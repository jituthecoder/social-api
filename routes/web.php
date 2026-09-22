<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\OAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'Social W3Lead API',
        'status' => 'operational',
        'version' => 'v1',
    ]);
});

/*
|--------------------------------------------------------------------------
| OAuth Callbacks (Browser Redirect)
|--------------------------------------------------------------------------
|
| Social platforms redirect the user's browser here after authorization.
| The OAuthController exchanges the code for tokens and redirects to the
| dashboard with the connection result.
|
*/
Route::get('/oauth/{platform}/callback', [OAuthController::class, 'callback']);

/*
|--------------------------------------------------------------------------
| Web Route Fallbacks for unprefixed requests (e.g. /auth/login or /v1/...)
|--------------------------------------------------------------------------
*/
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
    });
});



