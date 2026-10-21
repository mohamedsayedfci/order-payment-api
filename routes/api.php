<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

// Bound numeric IDs before PHP coerces route parameters to integers.
Route::pattern('order', '[1-9][0-9]{0,17}');

Route::prefix('v1')->group(function (): void {
    Route::middleware('throttle:authentication')->prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
    });

    Route::middleware(['auth:api', 'throttle:api'])->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::apiResource('orders', OrderController::class);
        Route::get('orders/{order}/payments', [PaymentController::class, 'index']);
        Route::post('orders/{order}/payments', [PaymentController::class, 'store']);
        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->whereUuid('payment');
    });
});
