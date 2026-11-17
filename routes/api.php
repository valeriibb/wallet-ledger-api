<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CashMovementController;
use App\Http\Controllers\TransferController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');
});

Route::get('/v1/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/v1/accounts', [AccountController::class, 'store'])
    ->middleware('auth:sanctum');
Route::get('/v1/accounts', [AccountController::class, 'index'])
    ->middleware('auth:sanctum');
Route::get('/v1/accounts/{account}/balance', [AccountController::class, 'balance'])
    ->middleware('auth:sanctum');
Route::get('/v1/accounts/{account}/entries', [AccountController::class, 'entries'])
    ->middleware('auth:sanctum');

Route::post('/v1/transfers', [TransferController::class, 'store'])
    ->middleware('auth:sanctum');
Route::post('/v1/deposits', [CashMovementController::class, 'deposit'])
    ->middleware('auth:sanctum');
Route::post('/v1/withdrawals', [CashMovementController::class, 'withdraw'])
    ->middleware('auth:sanctum');
