<?php

namespace App\Services;

use App\Models\Bots;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Динамическое подключение к БД конкретного бота.
 *
 * Данные подключения берутся из модели Bots
 * (db_host, bd_login, bd_password, bd_name).
 */
class BotDatabase
{
    public const CONNECTION = 'bot';

    public static function connect(Bots $bot): Connection
    {
        config(['database.connections.' . self::CONNECTION => [
            'driver'         => 'mysql',
            'host'           => $bot->db_host,
            'port'           => $bot->db_port ?? 3306,
            'database'       => $bot->bd_name,
            'username'       => $bot->bd_login,
            'password'       => $bot->bd_password,
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => true,
            'engine'         => null,
        ]]);

        // Сбрасываем прошлое подключение с тем же именем
        // (актуально, если за один запрос работаем с разными ботами).
        DB::purge(self::CONNECTION);

        return DB::connection(self::CONNECTION);
    }
}
