<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Менеджеры и их права доступа (в основной БД аналитики).
     * Супер-админ захардкожен в коде (ManagerAccess), в таблице не нужен.
     *
     * permissions (json):
     *   {
     *     "bots": "all" | [botId, ...],
     *     "contragents": { "<botId>": "all" | [cpId, ...] },
     *     "sections": ["gen","ai","shop","btn"],
     *     "general": true|false
     *   }
     */
    public function up(): void
    {
        Schema::create('managers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->unique();
            $table->string('name')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->json('permissions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managers');
    }
};
