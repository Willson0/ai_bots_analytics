<?php

use App\Http\Controllers\ManagerController;
use App\Http\Controllers\StatisticsController;
use App\Http\Middleware\ResolveManager;
use Illuminate\Support\Facades\Route;

// Всё под авторизацией по Telegram initData (ResolveManager).
Route::middleware(ResolveManager::class)->group(function () {
    // Текущий пользователь и его права
    Route::get('/me', [ManagerController::class, 'me']);

    // Списки для селекторов (уже скоупятся по правам)
    Route::get('/bots', [StatisticsController::class, 'bots']);
    Route::get('/statistics/filters', [StatisticsController::class, 'filters']);

    // Основная статистика по боту
    Route::get('/statistics', [StatisticsController::class, 'get']);

    // Управление менеджерами (внутри — проверка на админа)
    Route::get('/managers', [ManagerController::class, 'index']);
    Route::post('/managers', [ManagerController::class, 'store']);
    Route::match(['put', 'patch'], '/managers/{id}', [ManagerController::class, 'update']);
    Route::delete('/managers/{id}', [ManagerController::class, 'destroy']);
});
