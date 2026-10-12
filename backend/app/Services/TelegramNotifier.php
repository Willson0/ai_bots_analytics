<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Отправка сообщений ботом (тем же, чей токен в TELEGRAM_BOT_TOKEN).
 * Используется для уведомления менеджера, что сводная статистика готова.
 *
 * Важно: пользователь должен был хотя бы раз написать боту /start,
 * иначе Telegram не разрешит боту писать первым (ошибка 403).
 */
class TelegramNotifier
{
    public static function send(int $chatId, string $text): bool
    {
        $token = (string) (config('services.telegram.bot_token') ?: env('TELEGRAM_BOT_TOKEN', ''));
        if ($token === '' || $chatId <= 0) {
            return false;
        }

        try {
            $res = Http::timeout(10)->asForm()->post(
                "https://api.telegram.org/bot{$token}/sendMessage",
                ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true]
            );
            return $res->successful();
        } catch (Throwable $e) {
            return false;
        }
    }
}
