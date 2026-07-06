<?php

use App\Http\Controllers\TelegramController;
use App\Http\Controllers\RaBotController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GoldBotController;

Route::post('/telegram/webhook', [TelegramController::class, 'webhook']);
Route::post('/ra-bot/webhook', [RaBotController::class, 'webhook']);

Route::prefix('bot')->group(function () {
    // Gold Trading Bot Webhook
    Route::post('/gold/webhook', [GoldBotController::class, 'webhook']) ;
    Route::get('/gold/webhook', [GoldBotController::class, 'webhook']) ;
});
