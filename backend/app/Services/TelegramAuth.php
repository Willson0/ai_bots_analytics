<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Проверка подписи Telegram WebApp initData и извлечение telegram id.
 * Алгоритм совпадает с тем, что использует бот (rawurldecode → sort → HMAC).
 */
class TelegramAuth
{
    public static function resolveTelegramId(Request $request): ?int
    {
        $initData = (string) $request->header('X-Telegram-Init-Data', '');
        if ($initData !== '') {
            $id = self::verify($initData, (string) env('TELEGRAM_BOT_TOKEN', ''));
            if ($id !== null) {
                return $id;
            }
        }

        // Для локальной отладки вне Telegram (только при APP_DEBUG).
        if (config('app.debug')) {
            $dbg = $request->header('X-Debug-Telegram-Id');
            if ($dbg !== null && $dbg !== '') {
                return (int) $dbg;
            }
        }

        return null;
    }

    private static function verify(string $initData, string $botToken): ?int
    {
        if ($botToken === '') {
            return null;
        }

        $parts = explode('&', rawurldecode($initData));
        $hash = '';
        $kept = [];
        foreach ($parts as $p) {
            if (str_starts_with($p, 'hash=')) {
                $hash = substr($p, 5);
            } else {
                $kept[] = $p;
            }
        }
        if ($hash === '') {
            return null;
        }

        sort($kept);
        $dataCheck = implode("\n", $kept);
        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calc = bin2hex(hash_hmac('sha256', $dataCheck, $secret, true));

        if (!hash_equals($calc, $hash)) {
            return null;
        }

        foreach ($kept as $p) {
            if (str_starts_with($p, 'user=')) {
                $user = json_decode(substr($p, 5), true);
                return isset($user['id']) ? (int) $user['id'] : null;
            }
        }
        return null;
    }
}
