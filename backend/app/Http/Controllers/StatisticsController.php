<?php

namespace App\Http\Controllers;

use App\Http\Requests\StatisticsGetRequest;
use App\Models\Bots;
use App\Services\BotAnalyticsService;
use App\Services\BotDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StatisticsController extends Controller
{
    /** Список ботов / фильтры меняются редко — кэшируем на 10 минут. */
    private const LISTS_TTL = 600;

    /**
     * Статистика по боту за период с учётом фильтров.
     * GET /api/statistics?time=&bot=&contragent=&link=[&refresh=1]
     *
     * Ответ кэшируется по ключу (bot, time, contragent, link).
     * TTL зависит от периода: сутки обновляются чаще, «всё время» — реже.
     * ?refresh=1 — пересчитать принудительно (сбросить кэш).
     */
    public function get(StatisticsGetRequest $request): JsonResponse
    {
        $data = $request->validated();

        $bot  = (int) $data['bot'];
        $time = (int) $data['time'];

        // contragent и link — массивы id (фильтр по объединению).
        $contragents = array_values(array_unique(array_map('intval', $data['contragent'] ?? [])));
        $links       = array_values(array_unique(array_map('intval', $data['link'] ?? [])));
        sort($contragents);
        sort($links);

        $key = sprintf(
            'stats:%d:%d:c%s:l%s',
            $bot,
            $time,
            implode('_', $contragents) ?: 'all',
            implode('_', $links) ?: 'all'
        );
        $ttl = $this->statsTtl($time);

        if ($request->boolean('refresh')) {
            Cache::forget($key);
        }

        $payload = Cache::remember($key, $ttl, function () use ($bot, $time, $contragents, $links) {
            $model = Bots::findOrFail($bot);
            return (new BotAnalyticsService($model, $time, $contragents, $links))->collect();
        });

        return response()->json($payload);
    }

    /**
     * Список ботов для селектора.
     * GET /api/bots
     */
    public function bots(): JsonResponse
    {
        $bots = Cache::remember('stats:bots', self::LISTS_TTL, function () {
            return Bots::query()
                ->orderBy('name')
                ->get(['id', 'name', 'platform'])
                ->map(fn (Bots $bot) => [
                    'id'       => $bot->id,
                    'name'     => $bot->name,
                    'platform' => $this->platform($bot->platform, $bot->name),
                ])
                ->all();
        });

        return response()->json($bots);
    }

    /**
     * Контрагенты и ссылки конкретного бота — для фильтров.
     * GET /api/statistics/filters?bot=
     */
    public function filters(Request $request): JsonResponse
    {
        $request->validate([
            'bot' => 'required|integer|exists:bots,id',
        ]);

        $botId = $request->integer('bot');

        $payload = Cache::remember('stats:filters:' . $botId, self::LISTS_TTL, function () use ($botId) {
            $bot = Bots::findOrFail($botId);
            $db = BotDatabase::connect($bot);

            $partners = $db->table('partners')->get(['id', 'name', 'links']);

            // Карта имя_ссылки -> partner_id (partners.links — JSON-массив ИМЁН ссылок).
            $nameToPartner = [];
            foreach ($partners as $partner) {
                $names = is_array($partner->links) ? $partner->links : json_decode((string) $partner->links, true);
                if (!is_array($names)) {
                    continue;
                }
                foreach ($names as $n) {
                    $nameToPartner[(string) $n] = (int) $partner->id;
                }
            }

            $links = $db->table('links')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->map(fn ($link) => [
                    'id'         => (int) $link->id,
                    'name'       => (string) $link->name,
                    'contragent' => $nameToPartner[(string) $link->name] ?? null,
                ])
                ->all();

            $contragents = $partners->map(fn ($p) => [
                'id'   => (int) $p->id,
                'name' => (string) ($p->name ?? ('Партнёр #' . $p->id)),
            ])->values()->all();

            return ['contragents' => $contragents, 'links' => $links];
        });

        return response()->json($payload);
    }

    /**
     * TTL кэша статистики в секундах по периоду.
     *   time = 1  (сутки)      — 5 минут (данные текущего часа меняются часто)
     *   time = 0  (всё время)  — 60 минут
     *   иначе (7/30/N дней)    — 30 минут
     */
    private function statsTtl(int $time): int
    {
        return match ($time) {
            1       => 300,
            0       => 3600,
            default => 1800,
        };
    }

    /**
     * Площадка бота для бейджа в UI: MAX или TG.
     * Берём из колонки platform; если пусто — запасной вариант по имени.
     */
    private function platform(?string $platform, ?string $name): string
    {
        $p = mb_strtolower(trim((string) $platform));
        if ($p === 'max') {
            return 'MAX';
        }
        if ($p === 'tg' || $p === 'telegram') {
            return 'TG';
        }

        return str_contains(mb_strtolower((string) $name), 'max') ? 'MAX' : 'TG';
    }
}
