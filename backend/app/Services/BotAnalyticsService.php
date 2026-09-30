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
            // Сутки: с 00:00 до текущего момента, по часам.
            $this->granularity = 'hour';
            $this->from = $now->copy()->startOfDay();
        } elseif ($this->time === 0) {
            // Всё время: от самой ранней записи до сейчас, равными интервалами.
            $this->granularity = 'all';
            $earliest = $this->db->table('users')->min('created_at');
            $this->from = $earliest
                ? Carbon::parse($earliest, self::TZ)
                : $now->copy()->subDay();

            $seconds = max(1, $this->to->getTimestamp() - $this->from->getTimestamp());
            $this->bucketWidth = (int) ceil($seconds / self::ALL_TIME_BUCKETS);
        } else {
            // Неделя / месяц / произвольное число дней — по дням.
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
                $buckets[] = [
                    'key'   => $h,
                    'label' => sprintf('%02d:00', $h),
                ];
            }
        } elseif ($this->granularity === 'day') {
            $cursor = $this->from->copy();
            while ($cursor->lessThanOrEqualTo($this->to)) {
                $buckets[] = [
                    'key'   => $cursor->format('Y-m-d'),
                    'label' => $cursor->format('Y-m-d'),
                ];
                $cursor->addDay();
            }
        } else { // all
            for ($i = 0; $i < self::ALL_TIME_BUCKETS; $i++) {
                $start = $this->from->copy()->addSeconds($i * $this->bucketWidth);
                if ($start->greaterThan($this->to)) {
                    break;
                }
                $buckets[] = [
                    'key'   => $i,
                    'label' => $start->format('Y-m-d H:i'),
                ];
            }
        }

        return $buckets;
    }

    /**
     * Временной ряд количества по таблице.
     *
     * @param string $table       Таблица (users | query_logs | ...).
     * @param string $dateColumn  Колонка с датой.
     * @param bool   $distinctUser Считать COUNT(DISTINCT user_id) вместо COUNT(*).
     * @return array{total:int, series:array<int,array{label:string,count:int}>}
     */
    private function timeSeries(string $table, string $dateColumn, bool $distinctUser): array
    {
        $countExpr = $distinctUser ? 'COUNT(DISTINCT user_id)' : 'COUNT(*)';

        $query = $this->db->table($table)
            ->whereBetween($dateColumn, [$this->fromStr(), $this->toStr()]);
        $this->applyUserFilter($query, $table === 'users');

        if ($this->granularity === 'hour') {
            $rows = (clone $query)
                ->selectRaw("HOUR($dateColumn) as k, $countExpr as c")
                ->groupBy('k')
                ->pluck('c', 'k');
        } elseif ($this->granularity === 'day') {
            $rows = (clone $query)
                ->selectRaw("DATE($dateColumn) as k, $countExpr as c")
                ->groupBy('k')
                ->pluck('c', 'k');
        } else { // all
            $rows = (clone $query)
                ->selectRaw(
                    "FLOOR(TIMESTAMPDIFF(SECOND, ?, $dateColumn) / ?) as k, $countExpr as c",
                    [$this->fromStr(), $this->bucketWidth]
                )
                ->groupBy('k')
                ->pluck('c', 'k');
        }

        $series = [];
        foreach ($this->buckets() as $bucket) {
            $series[] = [
                'label' => $bucket['label'],
                'count' => (int) ($rows[$bucket['key']] ?? 0),
            ];
        }

        // total считаем отдельно: для distinct — уникальные за весь период,
        // а не сумма по бакетам (иначе один юзер посчитается несколько раз).
        $totalQuery = $this->db->table($table)
            ->whereBetween($dateColumn, [$this->fromStr(), $this->toStr()]);
        $this->applyUserFilter($totalQuery, $table === 'users');
        $total = $distinctUser
            ? (int) $totalQuery->distinct()->count('user_id')
            : (int) $totalQuery->count();

        return ['total' => $total, 'series' => $series];
    }

    // ------------------------------------------------------------------
    // Фильтры по контрагенту / ссылке
    // ------------------------------------------------------------------

    private function resolveUserFilter(?int $contragent, ?int $link): void
    {
        $sets = [];

        if ($link !== null) {
            $name = $this->db->table('links')->where('id', $link)->value('name');
            // Если ссылка не найдена — пустой набор (никто не подойдёт).
            $sets[] = $name !== null ? [(string) $name] : [];
        }

        if ($contragent !== null) {
            $sets[] = $this->partnerLinkNames($contragent);
        }

        if (empty($sets)) {
            $this->linkNames = null;
            return;
        }

        // Если заданы оба фильтра — берём пересечение.
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

        // Если элементы числовые — это id ссылок, резолвим в имена.
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
     * @param Builder $query
     * @param bool $isUsersTable true — фильтр по колонке link, иначе по user_id.
     */
    private function applyUserFilter(Builder $query, bool $isUsersTable): void
    {
        if ($this->linkNames === null) {
            return;
        }

        if (empty($this->linkNames)) {
            // Фильтр задан, но подходящих ссылок нет — выборка пустая.
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
        return [
            'period'       => [
                'time'        => $this->time,
                'from'        => $this->from->toIso8601String(),
                'to'          => $this->to->toIso8601String(),
                'granularity' => $this->granularity,
            ],
            'users'        => $this->users(),
            'queries'      => $this->queries(),
            'monetization' => $this->monetization(),
            'buttons'      => $this->buttons(),
        ];
    }

    // ------------------------------------------------------------------
    // Пользователи
    // ------------------------------------------------------------------

    private function users(): array
    {
        $active = $this->timeSeries('query_logs', 'created_at', true);
        $new    = $this->timeSeries('users', 'created_at', false);
        $newTotal = $new['total'];

        return [
            'active'              => $active,
            'new'                 => $new,
            'new_premium'         => $this->countWithPercent($this->newUsersQuery()->where('is_premium', 1), $newTotal),
            'subscribed_op'       => $this->subscribedOp($newTotal),
            'from_referral_links' => $this->countWithPercent(
                $this->newUsersQuery()->whereNotNull('link')->where('link', '<>', ''),
                $newTotal
            ),
            'from_other_users'    => $this->countWithPercent(
                $this->newUsersQuery()->where('referal_user_from', '<>', 0),
                $newTotal
            ),
        ];
    }

    /** Базовый запрос по новым пользователям за период (с фильтром). */
    private function newUsersQuery(): Builder
    {
        $q = $this->db->table('users')
            ->whereBetween('created_at', [$this->fromStr(), $this->toStr()]);
        $this->applyUserFilter($q, true);
        return $q;
    }

    /**
     * Подписались на ОП.
     *
     * В таблице op нет привязки к пользователю (это конфиг каналов ОП),
     * поэтому считаем: новые пользователи, которые НЕ пропустили ОП, —
     * т.е. без ссылки со skip_op = 1. links.skip_op = 1 означает,
     * что пользователи по этой ссылке пропускают обязательную подписку.
     *
     * ДОПУЩЕНИЕ — при необходимости легко заменить на реальный признак,
     * если в users появится поле факта подписки на ОП.
     */
    private function subscribedOp(int $newTotal): array
    {
        $skipLinkNames = $this->db->table('links')
            ->where('skip_op', 1)
            ->pluck('name')
            ->map(fn ($n) => (string) $n)
            ->all();

        $q = $this->newUsersQuery();
        if (!empty($skipLinkNames)) {
            $q->where(function ($w) use ($skipLinkNames) {
                $w->whereNull('link')->orWhereNotIn('link', $skipLinkNames);
            });
        }

        return $this->countWithPercent($q, $newTotal);
    }

    // ------------------------------------------------------------------
    // Запросы к нейросети
    // ------------------------------------------------------------------

    private function queries(): array
    {
        $base = fn () => $this->applyToQueryLogs();

        $total = (int) $base()->count();

        // Категории по query_logs.type: text | image | redirect_text | redirect_image.
        $text     = (int) $base()->where('type', 'text')->count();
        $image    = (int) $base()->where('type', 'image')->count();
        $redirect = (int) $base()->where('type', 'like', 'redirect\_%')->count();

        return [
            'total'   => $total,
            'by_type' => [
                'text'     => ['count' => $text,     'percent' => $this->pct($text, $total)],
                'image'    => ['count' => $image,    'percent' => $this->pct($image, $total)],
                'redirect' => ['count' => $redirect, 'percent' => $this->pct($redirect, $total)],
            ],
            'top_models' => [
                'text'     => $this->topModels(fn ($q) => $q->where('type', 'text'), $total),
                'image'    => $this->topModels(fn ($q) => $q->where('type', 'image'), $total),
                'redirect' => $this->topModels(fn ($q) => $q->where('type', 'like', 'redirect\_%'), $total),
            ],
        ];
    }

    /** Базовый запрос по query_logs за период (с фильтром). */
    private function applyToQueryLogs(): Builder
    {
        $q = $this->db->table('query_logs')
            ->whereBetween('created_at', [$this->fromStr(), $this->toStr()]);
        $this->applyUserFilter($q, false);
        return $q;
    }

    /**
     * Топ моделей внутри категории.
     *
     * @param callable(Builder):Builder $scope Дополнительное условие категории.
     * @param int $totalQueries Общее число запросов (для процента).
     */
    private function topModels(callable $scope, int $totalQueries): array
    {
        $q = $this->applyToQueryLogs();
        $scope($q);

        return $q->selectRaw('model, COUNT(*) as c')
            ->groupBy('model')
            ->orderByDesc('c')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->map(fn ($row) => [
                'model'   => $row->model,
                'count'   => (int) $row->c,
                'percent' => $this->pct((int) $row->c, $totalQueries),
            ])
            ->all();
    }

    // ------------------------------------------------------------------
    // Монетизация
    // ------------------------------------------------------------------

    private function monetization(): array
    {
        // Все покупки за период (is_bought = 1).
        $purchases      = (int) $this->purchasesQuery()->count();
        $revenueTotal   = (float) $this->purchasesQuery()->sum('rub_summ');

        // Пробная подписка: summ = 1, иначе — платная PRO.
        $trialCount     = (int) $this->purchasesQuery()->where('summ', 1)->count();
        $proCount       = (int) $this->purchasesQuery()->where('summ', '<>', 1)->count();

        $activeTotal    = (int) $this->activeUsersCount();

        return [
            'purchases'          => $purchases,
            'revenue_total'      => round($revenueTotal, 2),
            // Средний чек на одну покупку.
            'avg_check'          => $purchases > 0 ? round($revenueTotal / $purchases, 2) : 0.0,
            // "Доход со старта" = доход за период / активные пользователи за период.
            'revenue_per_active' => $activeTotal > 0 ? round($revenueTotal / $activeTotal, 2) : 0.0,
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

    private function activeUsersCount(): int
    {
        $q = $this->db->table('query_logs')
            ->whereBetween('created_at', [$this->fromStr(), $this->toStr()]);
        $this->applyUserFilter($q, false);
        return (int) $q->distinct()->count('user_id');
    }

    /**
     * Продлившие пробную подписку на платную PRO.
     *
     * Считаем пользователей, у кого за период была пробная покупка (summ = 1)
     * и есть платная PRO-покупка (summ <> 1, is_bought = 1).
     * Процент — от числа уникальных пользователей с пробной за период.
     */
    private function trialToPro(): array
    {
        // Пользователи с пробной покупкой за период.
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

        // Из них — кто затем оплатил платную PRO.
        $converted = (int) $this->db->table('payments')
            ->where('is_bought', 1)
            ->where('summ', '<>', 1)
            ->whereIn('user_id', $trialUsers)
            ->distinct()
            ->count('user_id');

        return [
            'count'   => $converted,
            'percent' => $this->pct($converted, $trialUsersCount),
        ];
    }

    /**
     * Топ товаров.
     *
     * sub всегда = pro; товар различается по типу (пробная / платная) и
     * длительности/цене. Группируем по (пробная?, days, rub_summ).
     */
    private function topProducts(): array
    {
        $q = $this->purchasesQuery();

        return $q->selectRaw('
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
                $name = $isTrial
                    ? 'PRO (пробная)'
                    : 'PRO ' . (int) $row->days . ' дн.';

                return [
                    'name'    => $name,
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
        return [
            'total' => null,
            'top'   => null,
        ];
    }

    // ------------------------------------------------------------------
    // Утилиты
    // ------------------------------------------------------------------

    /**
     * @param Builder $query
     * @param int $whole База для процента.
     * @return array{count:int, percent:float}
     */
    private function countWithPercent(Builder $query, int $whole): array
    {
        $count = (int) $query->count();
        return ['count' => $count, 'percent' => $this->pct($count, $whole)];
    }

    private function pct(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 2) : 0.0;
    }
}
