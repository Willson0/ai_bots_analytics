<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Признак сервиса бота: 'tg' (Telegram) или 'max' (MAX).
     * Структура БД у ботов одинаковая — различаем только площадку.
     */
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->string('platform', 10)->default('tg')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
