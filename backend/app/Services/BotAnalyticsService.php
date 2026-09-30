<?php

namespace App\Services;

use App\Models\Bots;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * Собирает статистику по конкретному боту.
 *
 * Подключается к БД бота (данные подключения берутся из модели Bots),
 * считает метрики за период (time) с учётом фильтров по контрагенту
 * (partners) и ссылке (links).
 *
 * Оптимизация: по каждой тяжёлой таблице делается один проход
 * (группировка / условные SUM), а не десяток отдельных COUNT-запросов.
 *
 * Соответствие time -> период:
 *   1  — сутки (разбивка по часам, с 00:00 по МСК до текущего часа)
 *   7  — неделя (разбивка по дням)
 *   30 — месяц (разбивка по дням)
 *   0  — всё время (равные интервалы, ~24 бакета)
 *   N  — произвольное число дней (разбивка по дням)
 */
class BotAnalyticsService
{
    /** Часовой пояс, в котором строятся периоды и бакеты. */
    private const TZ = 'Europe/Moscow';

    /** Кол-во бакетов для режима "всё время" (time = 0). */
    private const ALL_TIME_BUCKETS = 24;

    /** Сколько позиций показывать в топах. */
    private const TOP_LIMIT = 10;

    private Connection $db;

    private int $time;
    private Carbon $from;
    private Carbon $to;

    /** hour | day | all */
    private string $granularity;

    /** Ширина бакета в секундах (только для granularity = all). */
    private int $bucketWidth = 0;

    /**
     * Имена ссылок (links.name), которыми ограничена выборка пользователей.
     * null — фильтр не задан (учитываем всех).
     */
    private ?array $linkNames = null;

    public function __construct(Bots $bot, int $time, ?int $contragent = null, ?int $link = null)
    {
        $this->db = BotDatabase::connect($bot);
        $this->time = $time;

        $this->resolvePeriod();
        $this->resolveUserFilter($contragent, $link);
    }

    // ------------------------------------------------------------------
    // Период и бакеты
    // ------------------------------------------------------------------

    private function resolvePeriod(): void
    {
        $now = Carbon::now(self::TZ);
        $this->to = $now->copy();

        if ($this->time === 1) {
            $this->granularity = 'hour';
            $this->from = $now->copy()->startOfDay();
        } elseif ($this->time === 0) {
            $this->granularity = 'all';
            $earliest = $this->db->table('users')->min('created_at');
            $this->from = $earliest
                ? Carbon::parse($earliest, self::TZ)
                : $now->copy()->subDay();

            $seconds = max(1, $this->to->getTimestamp() - $this->from->getTimestamp());
            $this->bucketWidth = (int) ceil($seconds / self::ALL_TIME_BUCKETS);
        } else {
            $this->granularity = 'day';
            $this->from = $now->copy()->startOfDay()->subDays($this->time - 1);
        }
    }

    private function fromStr(): string
    {
        return $this->from->format('Y-m-d H:i:s');
    }

    private function toStr(): string
    {
        return $this->to->format('Y-m-d H:i:s');
    }

    /**
     * Описание бакетов периода: [['key' => ..., 'label' => ...], ...].
     * key совпадает с тем, что вернёт группирующий SQL-запрос.
     */
    private function buckets(): array
    {
        $buckets = [];

        if ($this->granularity === 'hour') {
            $lastHour = (int) $this->to->format('G');
            for ($h = 0; $h <= $lastHour; $h++) {
                $buckets[] = ['key' => $h, 'label' => sprintf('%02d:00', $h)];
            }
        } elseif ($this->granularity === 'day') {
            $cursor = $this->from->copy();
            while ($cursor->lessThanOrEqualTo($this->to)) {
                $buckets[] = ['key' => $cursor->format('Y-m-d'), 'label' => $cursor->format('Y-m-d')];
                $cursor->addDay();
            }
        } else { // all
            for ($i = 0; $i < self::ALL_TIME_BUCKETS; $i++) {
                $start = $this->from->copy()->addSeconds($i * $this->bucketWidth);
                if ($start->greaterThan($this->to)) {
                    break;
                }
                $buckets[] = ['key' => $i, 'label' => $start->format('Y-m-d H:i')];
            }
        }

        return $buckets;
    }

    /**
     * Временной ряд количества по таблице (один группирующий запрос).
     *
     * @return array<int,array{label:string,count:int}>
     */
    private function seriesFor(string $table, string $dateColumn, bool $distinctUser): array
    {
        $countExpr = $distinctUser ? 'COUNT(DISTINCT user_id)' : 'COUNT(*)';

        $query = $this->db->table($table)
            ->whereBetween($dateColumn, [$this->fromStr(), $this->toStr()]);
        $this->applyUserFilter($query, $table === 'users');

        if ($this->granularity === 'hour') {
            $rows = $query->selectRaw("HOUR($dateColumn) as k, $countExpr as c")->groupBy('k')->pluck('c', 'k');
        } elseif ($this->granularity === 'day') {
            $rows = $query->selectRaw("DATE($dateColumn) as k, $countExpr as c")->groupBy('k')->pluck('c', 'k');
        } else { // all
            $rows = $query->selectRaw(
                "FLOOR(TIMESTAMPDIFF(SECOND, ?, $dateColumn) / ?) as k, $countExpr as c",
                [$this->fromStr(), $this->bucketWidth]
            )->groupBy('k')->pluck('c', 'k');
        }

        $series = [];
        foreach ($this->buckets() as $bucket) {
            $series[] = ['label' => $bucket['label'], 'count' => (int) ($rows[$bucket['key']] ?? 0)];
        }

        return $series;
    }

    // ------------------------------------------------------------------
    // Фильтры по контрагенту / ссылке
    // ------------------------------------------------------------------

    private function resolveUserFilter(?int $contragent, ?int $link): void
    {
        $sets = [];

        if ($link !== null) {
            $name = $this->db->table('links')->where('id', $link)->value('name');
            $sets[] = $name !== null ? [(string) $name] : [];
        }

        if ($contragent !== null) {
            $sets[] = $this->partnerLinkNames($contragent);
        }

        if (empty($sets)) {
            $this->linkNames = null;
            return;
        }

        $names = array_shift($sets);
        foreach ($sets as $set) {
            $names = array_values(array_intersect($names, $set));
        }

        $this->linkNames = $names;
    }

    /**
     * Имена ссылок (links.name), закреплённых за партнёром.
     * partners.links — JSON: массив id ссылок (или их имён).
     */
    private function partnerLinkNames(int $contragent): array
    {
        $raw = $this->db->table('partners')->where('id', $contragent)->value('links');
        if ($raw === null) {
            return [];
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($decoded) || empty($decoded)) {
            return [];
        }

        $allNumeric = collect($decoded)->every(fn ($v) => is_int($v) || ctype_digit((string) $v));
        if ($allNumeric) {
            return $this->db->table('links')
                ->whereIn('id', array_map('intval', $decoded))
                ->pluck('name')
                ->map(fn ($n) => (string) $n)
                ->all();
        }

        return array_map('strval', $decoded);
    }

    /**
     * Применяет фильтр по ссылке к запросу.
     *
     * @param bool $isUsersTable true — фильтр по колонке link, иначе по user_id.
     */
    private function applyUserFilter(Builder $query, bool $isUsersTable): void
    {
        if ($this->linkNames === null) {
            return;
        }

        if (empty($this->linkNames)) {
            $query->whereRaw('1 = 0');
            return;
        }

        if ($isUsersTable) {
            $query->whereIn('link', $this->linkNames);
        } else {
            $names = $this->linkNames;
            $query->whereIn('user_id', function ($sub) use ($names) {
                $sub->from('users')->select('id')->whereIn('link', $names);
            });
        }
    }

    // ------------------------------------------------------------------
    // Публичный сбор всей статистики
    // ------------------------------------------------------------------

    public function collect(): array
    {
        // Активные пользователи считаются один раз и переиспользуются
        // (нужны и в блоке users, и в монетизации).
        $activeTotal = $this->activeTotal();
        $active = [
            'total'  => $activeTotal,
            'series' => $this->seriesFor('query_logs', 'created_at', true),
        ];

        return [
            'period'       => [
                'time'        => $this->time,
                'from'        => $this->from->toIso8601String(),
                'to'          => $this->to->toIso8601String(),
                'granularity' => $this->granularity,
            ],
            'users'        => $this->users($active),
            'queries'      => $this->queries(),
            'monetization' => $this->monetization($activeTotal),
            'buttons'      => $this->buttons(),
        ];
    }

    // ------------------------------------------------------------------
    // Пользователи
    // ------------------------------------------------------------------

    private function users(array $active): array
    {
        $newSeries = $this->seriesFor('users', 'created_at', false);
        $agg = $this->newUsersAggregates();
        $newTotal = $agg['new_total'];

        $withPct = fn (int $c) => ['count' => $c, 'percent' => $this->pct($c, $newTotal)];

        return [
            'active'              => $active,
            'new'                 => ['total' => $newTotal, 'series' => $newSeries],
            'new_premium'         => $withPct($agg['premium']),
            'subscribed_op'       => $withPct($agg['op']),
            'from_referral_links' => $withPct($agg['from_links']),
            'from_other_users'    => $withPct($agg['from_users']),
        ];
    }

    /**
     * Все агрегаты по новым пользователям за период — одним запросом.
     *
     * «Подписались на ОП»: в таблице op нет привязки к пользователю
     * (это конфиг каналов), поэтому считаем новых, которые НЕ пропустили ОП —
     * т.е. без ссылки со skip_op = 1. Легко заменить на реальный признак,
     * если он появится в users.
     *
     * @return array{new_total:int, premium:int, from_users:int, from_links:int, op:int}
     */
    private function newUsersAggregates(): array
    {
        // Ссылки, пропускающие ОП.
        $skip = $this->db->table('links')->where('skip_op', 1)->pluck('name')->map(fn ($n) => (string) $n)->all();

        if (empty($skip)) {
            $opExpr = '1';
            $opBind = [];
        } else {
            $ph = implode(',', array_fill(0, count($skip), '?'));
            $opExpr = "(link IS NULL OR link NOT IN ($ph))";
            $opBind = $skip;
        }

        $q = $this->db->table('users')->whereBetween('created_at', [$this->fromStr(), $this->toStr()]);
        $this->applyUserFilter($q, true);

        $row = $q->selectRaw(
            "COUNT(*) AS new_total,
             SUM(is_premium = 1) AS premium,
             SUM(referal_user_from <> 0) AS from_users,
             SUM(link IS NOT NULL AND link <> '') AS from_links,
             SUM($opExpr) AS op_cnt",
            $opBind
        )->first();

        return [
            'new_total'  => (int) ($row->new_total ?? 0),
            'premium'    => (int) ($row->premium ?? 0),
            'from_users' => (int) ($row->from_users ?? 0),
            'from_links' => (int) ($row->from_links ?? 0),
            'op'         => (int) ($row->op_cnt ?? 0),
        ];
    }

    // ------------------------------------------------------------------
    // Запросы к нейросети
    // ------------------------------------------------------------------

    /**
     * Всё по query_logs — одним группирующим запросом (type, model).
     * В PHP раскладываем на категории text / image / redirect,
     * считаем общий итог, разбивку по типам и топ моделей.
     */
    private function queries(): array
    {
        $rows = $this->queryLogsBase()
            ->selectRaw('type, model, COUNT(*) AS c')
            ->groupBy('type', 'model')
            ->get();

        $total = 0;
        $byType = ['text' => 0, 'image' => 0, 'redirect' => 0];
        $models = ['text' => [], 'image' => [], 'redirect' => []];

        foreach ($rows as $r) {
            $c = (int) $r->c;
            $total += $c;
            $cat = $this->queryCategory((string) $r->type);
            if ($cat === null) {
                continue;
            }
            $byType[$cat] += $c;
            $model = (string) $r->model;
            $models[$cat][$model] = ($models[$cat][$model] ?? 0) + $c;
        }

        $top = function (array $m) use ($total) {
            arsort($m);
            $out = [];
            foreach (array_slice($m, 0, self::TOP_LIMIT, true) as $model => $c) {
                $out[] = ['model' => $model, 'count' => $c, 'percent' => $this->pct($c, $total)];
            }
            return $out;
        };

        return [
            'total'   => $total,
            'by_type' => [
                'text'     => ['count' => $byType['text'],     'percent' => $this->pct($byType['text'], $total)],
                'image'    => ['count' => $byType['image'],    'percent' => $this->pct($byType['image'], $total)],
                'redirect' => ['count' => $byType['redirect'], 'percent' => $this->pct($byType['redirect'], $total)],
            ],
            'top_models' => [
                'text'     => $top($models['text']),
                'image'    => $top($models['image']),
                'redirect' => $top($models['redirect']),
            ],
        ];
    }

    /** Категория запроса по query_logs.type: text | image | redirect | null. */
    private function queryCategory(string $type): ?string
    {
        if ($type === 'text') {
            return 'text';
        }
        if ($type === 'image') {
            return 'image';
        }
        if (str_starts_with($type, 'redirect')) {
            return 'redirect';
        }
        return null;
    }

    /** Базовый запрос по query_logs за период (с фильтром). */
    private function queryLogsBase(): Builder
    {
        $q = $this->db->table('query_logs')
            ->whereBetween('created_at', [$this->fromStr(), $this->toStr()]);
        $this->applyUserFilter($q, false);
        return $q;
    }

    private function activeTotal(): int
    {
        return (int) $this->queryLogsBase()->distinct()->count('user_id');
    }

    // ------------------------------------------------------------------
    // Монетизация
    // ------------------------------------------------------------------

    private function monetization(int $activeTotal): array
    {
        // Покупки, доход, пробные и PRO — одним запросом.
        $row = $this->purchasesQuery()->selectRaw(
            'COUNT(*) AS purchases,
             COALESCE(SUM(rub_summ), 0) AS revenue,
             SUM(summ = 1) AS trial,
             SUM(summ <> 1) AS pro'
        )->first();

        $purchases  = (int) ($row->purchases ?? 0);
        $revenue    = (float) ($row->revenue ?? 0);
        $trialCount = (int) ($row->trial ?? 0);
        $proCount   = (int) ($row->pro ?? 0);

        return [
            'purchases'          => $purchases,
            'revenue_total'      => round($revenue, 2),
            'avg_check'          => $purchases > 0 ? round($revenue / $purchases, 2) : 0.0,
            'revenue_per_active' => $activeTotal > 0 ? round($revenue / $activeTotal, 2) : 0.0,
            'trial_subs'         => ['count' => $trialCount, 'percent' => $this->pct($trialCount, $purchases)],
            'pro_subs'           => ['count' => $proCount,   'percent' => $this->pct($proCount, $purchases)],
            'trial_to_pro'       => $this->trialToPro(),
            'top_products'       => $this->topProducts(),
        ];
    }

    /** Базовый запрос по покупкам за период (с фильтром). */
    private function purchasesQuery(): Builder
    {
        $q = $this->db->table('payments')
            ->where('is_bought', 1)
            ->whereBetween('created_at', [$this->fromStr(), $this->toStr()]);
        $this->applyUserFilter($q, false);
        return $q;
    }

    /**
     * Продлившие пробную подписку на платную PRO.
     * Процент — от числа уникальных пользователей с пробной за период.
     */
    private function trialToPro(): array
    {
        $trialUsersQuery = $this->db->table('payments')
            ->where('is_bought', 1)
            ->where('summ', 1)
            ->whereBetween('created_at', [$this->fromStr(), $this->toStr()]);
        $this->applyUserFilter($trialUsersQuery, false);
        $trialUsers = $trialUsersQuery->distinct()->pluck('user_id')->all();

        $trialUsersCount = count($trialUsers);
        if ($trialUsersCount === 0) {
            return ['count' => 0, 'percent' => 0.0];
        }

        $converted = (int) $this->db->table('payments')
            ->where('is_bought', 1)
            ->where('summ', '<>', 1)
            ->whereIn('user_id', $trialUsers)
            ->distinct()
            ->count('user_id');

        return ['count' => $converted, 'percent' => $this->pct($converted, $trialUsersCount)];
    }

    /**
     * Топ товаров. sub всегда = pro; товар различается типом
     * (пробная / платная) и длительностью/ценой.
     */
    private function topProducts(): array
    {
        return $this->purchasesQuery()
            ->selectRaw('
                CASE WHEN summ = 1 THEN 1 ELSE 0 END as is_trial,
                days,
                rub_summ,
                COUNT(*) as sold,
                SUM(rub_summ) as revenue
            ')
            ->groupBy('is_trial', 'days', 'rub_summ')
            ->orderByDesc('sold')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->map(function ($row) {
                $isTrial = (int) $row->is_trial === 1;
                return [
                    'name'    => $isTrial ? 'PRO (пробная)' : 'PRO ' . (int) $row->days . ' дн.',
                    'price'   => round((float) $row->rub_summ, 2),
                    'sold'    => (int) $row->sold,
                    'revenue' => round((float) $row->revenue, 2),
                ];
            })
            ->all();
    }

    // ------------------------------------------------------------------
    // Кнопки (пока не считаем — возвращаем null)
    // ------------------------------------------------------------------

    private function buttons(): array
    {
        return ['total' => null, 'top' => null];
    }

    // ------------------------------------------------------------------
    // Утилиты
    // ------------------------------------------------------------------

    private function pct(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 2) : 0.0;
    }
}
