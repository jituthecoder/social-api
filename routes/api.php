<?php

use App\Http\Controllers\Api\V1\AIController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\SocialAccountController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    
    // Auth Routes
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    // Public Subscription Plans
    Route::get('/subscription/plans', [SubscriptionController::class, 'plans']);

    // Protected Routes (Sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        
        // Workspaces
        Route::apiResource('workspaces', WorkspaceController::class);
        Route::post('/workspaces/{workspace}/members', [WorkspaceController::class, 'addMember']);
        Route::delete('/workspaces/{workspace}/members/{user}', [WorkspaceController::class, 'removeMember']);

        // Social Accounts & OAuth
        Route::apiResource('social-accounts', SocialAccountController::class)->only(['index', 'show', 'destroy']);
        Route::get('/social/{platform}/connect', [SocialAccountController::class, 'connect']);

        // Posts
        Route::apiResource('posts', PostController::class);
        Route::post('/posts/{post}/publish', [PostController::class, 'publish']);

        // Media
        Route::apiResource('media', MediaController::class)->only(['index', 'store', 'destroy']);

        // AI Services (Rate limited endpoints)
        Route::prefix('ai')->middleware('throttle:30,1')->group(function () {
            Route::post('/generate', [AIController::class, 'generate']);
            Route::post('/rewrite', [AIController::class, 'rewrite']);
            Route::post('/variant', [AIController::class, 'variant']);
            Route::post('/hashtags', [AIController::class, 'hashtags']);
            Route::post('/ideas', [AIController::class, 'ideas']);
        });

        // Analytics
        Route::get('/analytics', [AnalyticsController::class, 'index']);

        // Subscription Usage
        Route::get('/subscription/usage', [SubscriptionController::class, 'usage']);
    });

    // Public OAuth Callback
    Route::get('/social/{platform}/callback', [SocialAccountController::class, 'callback']);
});

/*
|--------------------------------------------------------------------------
| Unversioned Compatibility Wrappers
|--------------------------------------------------------------------------
*/
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});
