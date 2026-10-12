<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('all_bots_reports', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key')->unique();   // подпись набора ботов (md5 от отсортированных id)
            $table->string('status')->default('queued'); // queued | ready | error
            $table->longText('payload')->nullable();  // JSON готового отчёта
            $table->string('message', 500)->nullable(); // текст ошибки, если status = error
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('all_bots_reports');
    }
};
