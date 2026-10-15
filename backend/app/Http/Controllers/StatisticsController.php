<?php

namespace App\Http\Controllers;

use App\Http\Requests\StatisticsGetRequest;
use App\Jobs\ComputeAllBotsStats;
use App\Models\AllBotsReport;
use App\Models\Bots;
use App\Services\BotAnalyticsService;
use App\Services\BotDatabase;
use App\Services\ManagerAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StatisticsController extends Controller
{
    /** Список ботов / фильтры меняются редко — кэшируем на 10 минут. */
    private const LISTS_TTL = 600;

    /** Сколько считать готовый сводный отчёт «свежим» (сек). */
    private const ALL_FRESH_TTL = 3600;

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

        /** @var ManagerAccess $access */
        $access = $request->attributes->get('access');

        $bot  = (int) $data['bot'];
        $time = (int) $data['time'];

        if (!$access->canBot($bot)) {
            abort(403, 'Нет доступа к этому боту');
        }

        // contragent и link — массивы id (фильтр по объединению).
        $contragents = array_values(array_unique(array_map('intval', $data['contragent'] ?? [])));
        $links       = array_values(array_unique(array_map('intval', $data['link'] ?? [])));

        // Ограничение по разрешённым контрагентам (если менеджер ограничен).
        $scope = $access->contragentScope($bot);
        if (is_array($scope)) {
            $contragents = empty($contragents)
                ? $scope                                  // нет выбора → только свои
                : array_values(array_intersect($contragents, $scope));
            if (empty($contragents)) {
                $contragents = [-1]; // ничего не разрешено → пустая выборка
            }
        }

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

        // Разделы, недоступные менеджеру, не отдаём.
        $payload = $this->applySectionAccess($payload, $access);

        return response()->json($payload);
    }

    /**
     * Запуск сводной статистики по всем доступным ботам (в фоне).
     * POST /api/statistics/all[?refresh=1]
     *
     * Если свежий отчёт уже готов — вернём его сразу. Иначе ставим задачу в
     * очередь и отвечаем status=queued; по готовности менеджеру придёт
     * уведомление в Telegram, можно закрыть приложение.
     */
    public function allStart(Request $request): JsonResponse
    {
        /** @var ManagerAccess $access */
        $access = $request->attributes->get('access');
        if (!$access->canGeneral()) {
            abort(403, 'Нет доступа к общей статистике');
        }

        $ids = $this->allBotIds();
        if (empty($ids)) {
            return response()->json(['status' => 'error', 'message' => 'Нет ботов в системе']);
        }

        $time   = $this->allTime($request);
        $key    = $this->allCacheKey($ids, $time);
        $force  = $request->boolean('refresh');
        $report = AllBotsReport::where('cache_key', $key)->first();

        // Готовый и ещё свежий отчёт — отдаём мгновенно.
        if (!$force && $report && $report->status === 'ready'
            && $report->generated_at && $report->generated_at->gt(now()->subSeconds(self::ALL_FRESH_TTL))) {
            return response()->json($this->presentReport($report, $access));
        }

        // Уже считается (недавно поставлен в очередь) — не плодим задачи.
        if (!$force && $report && $report->status === 'queued'
            && $report->updated_at && $report->updated_at->gt(now()->subMinutes(20))) {
            return response()->json(['status' => 'queued']);
        }

        AllBotsReport::updateOrCreate(
            ['cache_key' => $key],
            ['status' => 'queued', 'message' => null]
        );
        ComputeAllBotsStats::dispatch($ids, (int) $access->telegramId, $key, $time);

        return response()->json(['status' => 'queued']);
    }

    /**
     * Текущее состояние сводного отчёта (для опроса фронтом).
     * GET /api/statistics/all
     */
    public function allStatus(Request $request): JsonResponse
    {
        /** @var ManagerAccess $access */
        $access = $request->attributes->get('access');
        if (!$access->canGeneral()) {
            abort(403, 'Нет доступа к общей статистике');
        }

        $ids    = $this->allBotIds();
        $time   = $this->allTime($request);
        $key    = $this->allCacheKey($ids, $time);
        $report = AllBotsReport::where('cache_key', $key)->first();

        if (!$report) {
            return response()->json(['status' => 'none']);
        }
        return response()->json($this->presentReport($report, $access));
    }

    /** Приводит отчёт к ответу API (с учётом доступа к разделам). */
    private function presentReport(AllBotsReport $report, ManagerAccess $access): array
    {
        $out = [
            'status'       => $report->status,
            'generated_at' => optional($report->generated_at)->toIso8601String(),
        ];
        if ($report->status === 'ready' && is_array($report->payload)) {
            $out['data'] = $this->applySectionAccess($report->payload, $access);
        }
        if ($report->status === 'error') {
            $out['message'] = $report->message;
        }
        return $out;
    }

    /**
     * id всех ботов системы — для сводной статистики.
     * Право «general» означает доступ к сводке ПО ВСЕМ ботам (так и подписано
     * в админке), независимо от индивидуального доступа к конкретным ботам.
     */
    private function allBotIds(): array
    {
        return Bots::query()->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** Период сводной статистики из запроса: 0 (всё время) / 7 / 30 / 365. */
    private function allTime(Request $request): int
    {
        $time = (int) $request->input('time', 0);
        return in_array($time, [0, 7, 30, 365], true) ? $time : 0;
    }

    /** Ключ отчёта — подпись набора ботов + период (не зависит от порядка). */
    private function allCacheKey(array $ids, int $time = 0): string
    {
        $ids = array_map('intval', $ids);
        sort($ids);
        return 'all:' . $time . ':' . md5(implode('_', $ids));
    }

    /** Обнуляет разделы ответа, недоступные менеджеру. */
    private function applySectionAccess(array $payload, ManagerAccess $access): array
    {
        $map = ['gen' => 'users', 'ai' => 'queries', 'shop' => 'monetization', 'btn' => 'buttons'];
        foreach ($map as $section => $key) {
            if (!$access->canSection($section) && array_key_exists($key, $payload)) {
                $payload[$key] = null;
            }
        }
        return $payload;
    }

    /**
     * Список ботов для селектора.
     * GET /api/bots
     */
    public function bots(Request $request): JsonResponse
    {
        /** @var ManagerAccess $access */
        $access = $request->attributes->get('access');

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

        // Только разрешённые менеджеру боты.
        $bots = array_values(array_filter($bots, fn ($b) => $access->canBot((int) $b['id'])));

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

        /** @var ManagerAccess $access */
        $access = $request->attributes->get('access');
        if (!$access->canBot($botId)) {
            abort(403, 'Нет доступа к этому боту');
        }

        $cacheKey = 'stats:filters:' . $botId;

        if ($request->boolean('refresh')) {
            Cache::forget($cacheKey);
        }

        $payload = Cache::remember($cacheKey, self::LISTS_TTL, function () use ($botId) {
            $bot = Bots::findOrFail($botId);
            $db = BotDatabase::connect($bot);

            $partners = $db->table('partners')->get(['id', 'name', 'links']);

            // Карта имя_ссылки(lower) -> partner_id и id -> имя партнёра.
            // partners.links — массив ИМЁН ссылок; матчим без учёта регистра/пробелов.
            $nameToPartner = [];
            $partnerNames = [];
            foreach ($partners as $partner) {
                $pid = (int) $partner->id;
                $partnerNames[$pid] = (string) ($partner->name ?? ('Партнёр #' . $pid));
                foreach ($this->decodeLinkNames($partner->links) as $n) {
                    $nameToPartner[mb_strtolower($n)] = $pid;
                }
            }

            $links = $db->table('links')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->map(function ($link) use ($nameToPartner, $partnerNames) {
                    $key = mb_strtolower(trim((string) $link->name));
                    $cid = $nameToPartner[$key] ?? null;
                    return [
                        'id'              => (int) $link->id,
                        'name'            => (string) $link->name,
                        'contragent'      => $cid,
                        'contragent_name' => $cid !== null ? ($partnerNames[$cid] ?? null) : null,
                    ];
                })
                ->all();

            $contragents = $partners->map(fn ($p) => [
                'id'   => (int) $p->id,
                'name' => (string) ($p->name ?? ('Партнёр #' . $p->id)),
            ])->values()->all();

            return ['contragents' => $contragents, 'links' => $links];
        });

        // Если менеджер ограничен контрагентами этого бота — показываем только их
        // (и ссылки только этих контрагентов).
        $scope = $access->contragentScope($botId);
        if (is_array($scope)) {
            $allowed = array_map('intval', $scope);
            $payload['contragents'] = array_values(array_filter(
                $payload['contragents'],
                fn ($c) => in_array((int) $c['id'], $allowed, true)
            ));
            $payload['links'] = array_values(array_filter(
                $payload['links'],
                fn ($l) => $l['contragent'] !== null && in_array((int) $l['contragent'], $allowed, true)
            ));
        }

        return response()->json($payload);
    }

    /**
     * Разбирает partners.links в массив имён ссылок.
     * Терпим к форматам: массив, JSON-строка, двойная JSON-упаковка.
     *
     * @return string[]
     */
    private function decodeLinkNames($raw): array
    {
        if (is_array($raw)) {
            $v = $raw;
        } else {
            $s = (string) $raw;
            $v = json_decode($s, true);
            if (is_string($v)) {                 // двойная упаковка
                $v = json_decode($v, true);
            }
            if (!is_array($v)) {
                // Фоллбэк: не-JSON формат (напр. python-repr ['a','b'])
                // — вытаскиваем все токены в кавычках (одинарных или двойных).
                $v = preg_match_all('/[\'"]([^\'"]+)[\'"]/u', $s, $m) ? $m[1] : [];
            }
        }

        $out = [];
        foreach ($v as $n) {
            if (is_array($n)) {
                continue;
            }
            $name = trim((string) $n);
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
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
