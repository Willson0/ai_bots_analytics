<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник человекочитаемых названий кнопок: callback_data -> name.
 * Заполняется и правится ВРУЧНУЮ через SQL. В интерфейсе статистики
 * показывается только name; сырой callback_data пользователю не виден.
 *
 * Пример наполнения:
 *   INSERT INTO button_labels (callback_data, name) VALUES
 *     ('accept_terms', 'Соглашение с политикой конфиденциальности'),
 *     ('trend_generation_menu-0', 'Меню генерации трендов');
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('button_labels')) {
            return;
        }
        Schema::create('button_labels', function (Blueprint $table) {
            $table->string('callback_data', 200)->primary();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('button_labels');
    }
};
