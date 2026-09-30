<?php

use App\Http\Controllers\StatisticsController;
use Illuminate\Support\Facades\Route;

// Списки для селекторов фронтенда
Route::get('/bots', [StatisticsController::class, 'bots']);
Route::get('/statistics/filters', [StatisticsController::class, 'filters']);

// Основная статистика по боту
Route::get('/statistics', [StatisticsController::class, 'get']);
