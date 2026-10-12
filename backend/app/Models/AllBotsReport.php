<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Готовый (или считающийся) отчёт сводной статистики по всем ботам.
 * Хранится в управляющей БД, чтобы worker-контейнер и web-контейнер
 * видели один и тот же результат.
 */
class AllBotsReport extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload'      => 'array',
        'generated_at' => 'datetime',
    ];
}
