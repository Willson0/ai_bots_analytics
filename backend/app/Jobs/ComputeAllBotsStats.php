<?php

namespace App\Jobs;

use App\Models\AllBotsReport;
use App\Services\AllBotsAnalyticsService;
use App\Services\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Фоновый расчёт сводной статистики по всем ботам.
 * Результат кладётся в таблицу all_bots_reports, по готовности менеджеру
 * уходит уведомление в Telegram.
 */
class ComputeAllBotsStats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Расчёт по многим БД может быть долгим. */
    public int $timeout = 900;
    public int $tries = 1;

    /**
     * @param int[] $botIds
     */
    public function __construct(
        public array $botIds,
        public int $telegramId,
        public string $cacheKey,
    ) {
    }

    public function handle(): void
    {
        $data = (new AllBotsAnalyticsService($this->botIds))->compute();

        AllBotsReport::updateOrCreate(
            ['cache_key' => $this->cacheKey],
            [
                'status'       => 'ready',
                'payload'      => $data,
                'message'      => null,
                'generated_at' => now(),
            ]
        );

        TelegramNotifier::send(
            $this->telegramId,
            "📊 Сводная статистика по всем ботам готова.\nОткройте приложение и выберите «Все боты»."
        );
    }

    public function failed(Throwable $e): void
    {
        AllBotsReport::updateOrCreate(
            ['cache_key' => $this->cacheKey],
            ['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 500)]
        );

        TelegramNotifier::send(
            $this->telegramId,
            "⚠️ Не удалось собрать сводную статистику. Попробуйте запустить ещё раз позже."
        );
    }
}
