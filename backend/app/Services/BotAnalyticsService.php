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
    public const ALL_TIME_BUCKETS = 24;

    /** Сколько позиций показывать в топах. */
    public const TOP_LIMIT = 10;

    private Connection $db;

    private int $time;
    private Carbon $from;
    private Carbon $to;

    /** hour | day | all */
    private string $granularity;

    /** Ширина бакета в секундах (только для granularity = all). */
    private int $bucketWidth = 0;

    /**
     * Принудительный период (общий для всех ботов при сводной статистике).
     * ['from'=>Carbon,'to'=>Carbon,'granularity'=>string,'bucketWidth'=>int]
     */
    private ?array $forcePeriod = null;

    /**
     * Имена ссылок (links.name), которыми ограничена выборка пользователей.
     * null — фильтр не задан (учитываем всех).
     */
    private ?array $linkNames = null;

    /**
     * @param int[] $contragents id контрагентов (partners) — фильтр по объединению
     * @param int[] $links        id ссылок (links) — фильтр по объединению
     */
    public function __construct(Bots $bot, int $time, array $contragents = [], array $links = [], ?array $forcePeriod = null)
    {
        $this->db = BotDatabase::connect($bot);
        $this->time = $time;
        $this->forcePeriod = $forcePeriod;

        $this->resolvePeriod();
        $this->resolveUserFilter($contragents, $links);
    }

    // ------------------------------------------------------------------
    // Период и бакеты
    // ------------------------------------------------------------------

    private function resolvePeriod(): void
    {
        // Сводная статистика: период задан извне и одинаков для всех ботов,
        // чтобы бакеты графика совпадали и их можно было суммировать.
        if ($this->forcePeriod !== null) {
            $this->from        = $this->forcePeriod['from'];
            $this->to          = $this->forcePeriod['to'];
            $this->granularity = $this->forcePeriod['granularity'];
            $this->bucketWidth = (int) ($this->forcePeriod['bucketWidth'] ?? 0);
            return;
        }

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

    /**
     * Фильтр по объединению (OR): берём имена ссылок всех выбранных ссылок
     * И всех ссылок выбранных контрагентов, объединяем в один набор.
     *
     * @param int[] $contragents
     * @param int[] $links
     */
    private function resolveUserFilter(array $contragents, array $links): void
    {
        if (empty($contragents) && empty($links)) {
            $this->linkNames = null; // фильтр не задан — учитываем всех
            return;
        }

        $names = [];

        if (!empty($links)) {
            $names = $this->db->table('links')
                ->whereIn('id', $links)
                ->pluck('name')
                ->map(fn ($n) => (string) $n)
                ->all();
        }

        foreach ($contragents as $cid) {
            $names = array_merge($names, $this->partnerLinkNames((int) $cid));
        }

        $this->linkNames = array_values(array_unique($names));
    }

    /**
     * Имена ссылок (links.name), закреплённых за партнёром.
     * partners.links — JSON-массив ИМЁН ссылок. Терпим к форматам
     * (массив / JSON-строка / двойная упаковка).
     */
    private function partnerLinkNames(int $contragent): array
    {
        $raw = $this->db->table('partners')->where('id', $contragent)->value('links');

        if (is_array($raw)) {
            $v = $raw;
        } else {
            $s = (string) $raw;
            $v = json_decode($s, true);
            if (is_string($v)) {
                $v = json_decode($v, true);
            }
            if (!is_array($v)) {
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

    /**
     * Фильтр по ссылке для запроса с JOIN на users (колонка указывается явно,
     * напр. 'u.link').
     */
    private function applyLinkFilterColumn(Builder $query, string $column): void
    {
        if ($this->linkNames === null) {
            return;
        }
        if (empty($this->linkNames)) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->whereIn($column, $this->linkNames);
    }

    // ------------------------------------------------------------------
    // Публичный сбор всей статистики
    // ------------------------------------------------------------------

    public function collect(): array
    {
        $active = $this->activeBreakdown();            // total + new/old + series
        $agg = $this->newUsersAggregates();            // все агрегаты по новым юзерам

        return [
            'period'       => [
                'time'        => $this->time,
                'from'        => $this->from->toIso8601String(),
                'to'          => $this->to->toIso8601String(),
                'granularity' => $this->granularity,
            ],
            'users'        => $this->users($active, $agg),
            'queries'      => $this->queries(),
            'monetization' => $this->monetization($agg['new_total']),
            'buttons'      => $this->buttons(),
        ];
    }

    /**
     * Сырые агрегаты по одному боту для сводной статистики.
     * Все величины — абсолютные числа (без процентов и средних): их суммирует
     * агрегатор по всем ботам, а проценты/чеки пересчитывает в самом конце.
     * Ряды (active_series / new_series) выровнены по общим бакетам периода.
     */
    public function rawTotals(): array
    {
        $active    = $this->activeBreakdown();                           // total,new,old,series[{label,count}]
        $agg       = $this->newUsersAggregates();                        // new_total,premium,op,ref_*
        $newSeries = $this->seriesFor('users', 'created_at', false);     // [{label,count}]
        $q         = $this->queriesRaw();
        $m         = $this->monetizationRaw();
        $products  = $this->topProductsRaw();

        return [
            'buckets'       => array_map(fn ($b) => $b['label'], $this->buckets()),
            'active'        => ['total' => $active['total'], 'new' => $active['new'], 'old' => $active['old']],
            'active_series' => array_map(fn ($p) => (int) $p['count'], $active['series']),
            'new_series'    => array_map(fn ($p) => (int) $p['count'], $newSeries),
            'new_total'     => $agg['new_total'],
            'premium'       => $agg['premium'],
            'op'            => $agg['op'],
            'ref_links'     => $agg['ref_links'],
            'ref_users'     => $agg['ref_users'],
            'ref_unknown'   => $agg['ref_unknown'],
            'q'             => $q,
            'm'             => $m,
            'products'      => $products,
        ];
    }

    // ------------------------------------------------------------------
    // Пользователи
    // ------------------------------------------------------------------

    private function users(array $active, array $agg): array
    {
        $newTotal = $agg['new_total'];
        $withPct = fn (int $c) => ['count' => $c, 'percent' => $this->pct($c, $newTotal)];

        return [
            'active'        => $active,
            'new'           => ['total' => $newTotal, 'series' => $this->seriesFor('users', 'created_at', false)],
            'new_premium'   => $withPct($agg['premium']),
            'subscribed_op' => $withPct($agg['op']),
            // Рефералы — взаимоисключающие группы (в сумме = все новые).
            'referrals'     => [
                'links'   => $withPct($agg['ref_links']),
                'users'   => $withPct($agg['ref_users']),
                'unknown' => $withPct($agg['ref_unknown']),
            ],
        ];
    }

    /**
     * Активные пользователи за период + разбивка новые/старые.
     * Новые — created_at >= начала периода, старые — раньше.
     *
     * @return array{total:int, new:int, old:int, series:array}
     */
    private function activeBreakdown(): array
    {
        $from = $this->fromStr();

        $q = $this->db->table('query_logs as ql')
            ->join('users as u', 'u.id', '=', 'ql.user_id')
            ->whereBetween('ql.created_at', [$from, $this->toStr()]);
        $this->applyLinkFilterColumn($q, 'u.link');

        $row = $q->selectRaw(
            'COUNT(DISTINCT ql.user_id) AS total,
             COUNT(DISTINCT CASE WHEN u.created_at >= ? THEN ql.user_id END) AS new_a,
             COUNT(DISTINCT CASE WHEN u.created_at < ?  THEN ql.user_id END) AS old_a',
            [$from, $from]
        )->first();

        return [
            'total'  => (int) ($row->total ?? 0),
            'new'    => (int) ($row->new_a ?? 0),
            'old'    => (int) ($row->old_a ?? 0),
            'series' => $this->seriesFor('query_logs', 'created_at', true),
        ];
    }

    /**
     * Все агрегаты по новым пользователям за период — одним запросом.
     * ОП — по реальному признаку users.was_subed = 1.
     * Рефералы — взаимоисключающе: link → «по ссылкам»;
     * иначе referal_user_from != 0 → «от юзеров»; иначе «неизвестно».
     *
     * @return array{new_total:int, premium:int, op:int, ref_links:int, ref_users:int, ref_unknown:int}
     */
    private function newUsersAggregates(): array
    {
        $q = $this->db->table('users')->whereBetween('created_at', [$this->fromStr(), $this->toStr()]);
        $this->applyUserFilter($q, true);

        $row = $q->selectRaw(
            "COUNT(*) AS new_total,
             SUM(is_premium = 1) AS premium,
             SUM(was_subed = 1) AS op_cnt,
             SUM(link IS NOT NULL AND link <> '') AS ref_links,
             SUM((link IS NULL OR link = '') AND referal_user_from <> 0) AS ref_users,
             SUM((link IS NULL OR link = '') AND referal_user_from = 0) AS ref_unknown"
        )->first();

        return [
            'new_total'   => (int) ($row->new_total ?? 0),
            'premium'     => (int) ($row->premium ?? 0),
            'op'          => (int) ($row->op_cnt ?? 0),
            'ref_links'   => (int) ($row->ref_links ?? 0),
            'ref_users'   => (int) ($row->ref_users ?? 0),
            'ref_unknown' => (int) ($row->ref_unknown ?? 0),
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
        $raw = $this->queriesRaw();
        $total = $raw['total'];

        $top = function (array $m) use ($total) {
            arsort($m);
            $out = [];
            foreach (array_slice($m, 0, self::TOP_LIMIT, true) as $model => $c) {
                $out[] = ['model' => $model, 'count' => $c, 'percent' => $this->pct($c, $total)];
            }
            return $out;
        };

        $redTotal = $raw['red_text'] + $raw['red_image'];

        return [
            'total'   => $total,
            'by_type' => [
                'text'  => ['count' => $raw['text'],  'percent' => $this->pct($raw['text'], $total)],
                'image' => ['count' => $raw['image'], 'percent' => $this->pct($raw['image'], $total)],
            ],
            'top_models' => [
                'text'  => $top($raw['models_text']),
                'image' => $top($raw['models_image']),
            ],
            // Отдельный блок: соотношение редиректов (от общего числа редиректов).
            'redirects' => [
                'total' => $redTotal,
                'text'  => ['count' => $raw['red_text'],  'percent' => $this->pct($raw['red_text'], $redTotal)],
                'image' => ['count' => $raw['red_image'], 'percent' => $this->pct($raw['red_image'], $redTotal)],
            ],
        ];
    }

    /**
     * Сырые числа по query_logs — одним группирующим запросом (type, model).
     * Редиректы сворачиваются в основные категории: redirect_text -> Текст,
     * redirect_image -> Фото; плюс отдельно считаются сами редиректы.
     * Используется и для презентации (queries()), и для сводной статистики.
     *
     * @return array{total:int,text:int,image:int,models_text:array,models_image:array,red_text:int,red_image:int}
     */
    private function queriesRaw(): array
    {
        $rows = $this->queryLogsBase()
            ->selectRaw('type, model, COUNT(*) AS c')
            ->groupBy('type', 'model')
            ->get();

        $total = 0;
        $byType = ['text' => 0, 'image' => 0];
        $models = ['text' => [], 'image' => []];
        $red = ['text' => 0, 'image' => 0];

        foreach ($rows as $r) {
            $c = (int) $r->c;
            $type = (string) $r->type;

            if ($type === 'text' || $type === 'redirect_text') {
                $cat = 'text';
            } elseif ($type === 'image' || $type === 'redirect_image') {
                $cat = 'image';
            } else {
                continue; // неизвестные типы не учитываем
            }

            if ($type === 'redirect_text') {
                $red['text'] += $c;
            } elseif ($type === 'redirect_image') {
                $red['image'] += $c;
            }

            $total += $c;
            $byType[$cat] += $c;
            $model = (string) $r->model;
            $models[$cat][$model] = ($models[$cat][$model] ?? 0) + $c;
        }

        return [
            'total'        => $total,
            'text'         => $byType['text'],
            'image'        => $byType['image'],
            'models_text'  => $models['text'],
            'models_image' => $models['image'],
            'red_text'     => $red['text'],
            'red_image'    => $red['image'],
        ];
    }

    /** Базовый запрос по query_logs за период (с фильтром). */
    private function queryLogsBase(): Builder
    {
        $q = $this->db->table('query_logs')
            ->whereBetween('created_at', [$this->fromStr(), $this->toStr()]);
        $this->applyUserFilter($q, false);
        return $q;
    }

    // ------------------------------------------------------------------
    // Монетизация
    // ------------------------------------------------------------------

    private function monetization(int $newTotal): array
    {
        $r = $this->monetizationRaw();

        return [
            'revenue' => [
                'total' => round($r['revenue'], 2),
                'new'   => round($r['rev_new'], 2),
                'old'   => round($r['rev_old'], 2),
            ],
            'purchases' => [
                'total'    => $r['purchases'],
                'one_time' => $r['one_time'],
                'auto'     => $r['auto'],
            ],
            'avg_check' => [
                'with_trial'    => $r['purchases'] > 0 ? round($r['revenue'] / $r['purchases'], 2) : 0.0,
                'without_trial' => $r['cnt_paid'] > 0 ? round($r['rev_paid'] / $r['cnt_paid'], 2) : 0.0,
            ],
            // Доход со старта = выручка новых юзеров / кол-во новых юзеров.
            'revenue_per_new_start' => $newTotal > 0 ? round($r['rev_new'] / $newTotal, 2) : 0.0,
            // Конверсия в пробную = новые юзеры, купившие пробную / все новые.
            'trial_conversion' => ['count' => $r['trial_conv_count'], 'percent' => $this->pct($r['trial_conv_count'], $newTotal)],
            'trial_to_pro'     => ['count' => $r['trial_to_pro_count'], 'base' => $r['trial_base'], 'percent' => $this->pct($r['trial_to_pro_count'], $r['trial_base'])],
            'top_products'     => $this->topProducts(),
        ];
    }

    /**
     * Сырые числа по монетизации — одним запросом с JOIN на users + подзапросы
     * по пробным. Используется и для презентации (monetization()), и для сводной
     * статистики (суммирование по ботам, проценты/чеки пересчитываются после).
     *
     * @return array{purchases:int,revenue:float,rev_new:float,rev_old:float,one_time:int,auto:int,rev_paid:float,cnt_paid:int,trial_conv_count:int,trial_base:int,trial_to_pro_count:int}
     */
    private function monetizationRaw(): array
    {
        $from = $this->fromStr();

        $q = $this->db->table('payments as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.is_bought', 1)
            ->whereBetween('p.created_at', [$from, $this->toStr()]);
        $this->applyLinkFilterColumn($q, 'u.link');

        $row = $q->selectRaw(
            'COUNT(*) AS purchases,
             COALESCE(SUM(p.rub_summ), 0) AS revenue,
             COALESCE(SUM(CASE WHEN u.created_at >= ? THEN p.rub_summ END), 0) AS rev_new,
             COALESCE(SUM(CASE WHEN u.created_at <  ? THEN p.rub_summ END), 0) AS rev_old,
             SUM(p.is_autopayment = 0) AS one_time,
             SUM(p.is_autopayment = 1) AS auto_renew,
             COALESCE(SUM(CASE WHEN p.summ <> 1 THEN p.rub_summ END), 0) AS rev_paid,
             SUM(p.summ <> 1) AS cnt_paid',
            [$from, $from]
        )->first();

        $tp = $this->trialToPro();

        return [
            'purchases'          => (int) ($row->purchases ?? 0),
            'revenue'            => (float) ($row->revenue ?? 0),
            'rev_new'            => (float) ($row->rev_new ?? 0),
            'rev_old'            => (float) ($row->rev_old ?? 0),
            'one_time'           => (int) ($row->one_time ?? 0),
            'auto'               => (int) ($row->auto_renew ?? 0),
            'rev_paid'           => (float) ($row->rev_paid ?? 0),
            'cnt_paid'           => (int) ($row->cnt_paid ?? 0),
            'trial_conv_count'   => $this->trialConversionCount(),
            'trial_base'         => $tp['base'],
            'trial_to_pro_count' => $tp['count'],
        ];
    }

    /**
     * Уникальные новые юзеры (started в периоде), купившие пробную (summ = 1).
     */
    private function trialConversionCount(): int
    {
        $from = $this->fromStr();

        $q = $this->db->table('payments as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.is_bought', 1)
            ->where('p.summ', 1)
            ->whereBetween('u.created_at', [$from, $this->toStr()]);
        $this->applyLinkFilterColumn($q, 'u.link');

        return (int) $q->distinct()->count('p.user_id');
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
        // Кол-во уникальных пользователей с пробной покупкой за период.
        $trialUsersQuery = $this->db->table('payments')
            ->where('is_bought', 1)
            ->where('summ', 1)
            ->whereBetween('created_at', [$this->fromStr(), $this->toStr()]);
        $this->applyUserFilter($trialUsersQuery, false);
        $trialUsersCount = (int) $trialUsersQuery->distinct()->count('user_id');

        if ($trialUsersCount === 0) {
            return ['count' => 0, 'base' => 0, 'percent' => 0.0];
        }

        // Из них — кто купил НАСТОЯЩУЮ платную PRO-подписку.
        // Важно: сама пробная тоже лежит как sub = 'pro', но за 1 ₽ (summ = 1).
        // Если её не исключить, каждый пробник «конвертируется сам в себя» → 100 %.
        // Настоящая оплата: sub = 'pro' И summ <> 1 (цена не 1 ₽).
        // Через подзапрос, а не массив id (иначе на больших периодах
        // превышается лимит плейсхолдеров MySQL — 65535).
        $converted = (int) $this->db->table('payments')
            ->where('is_bought', 1)
            ->where('sub', 'pro')
            ->where('summ', '<>', 1)
            ->whereIn('user_id', function ($q) {
                $q->from('payments')
                    ->select('user_id')
                    ->where('is_bought', 1)
                    ->where('summ', 1)
                    ->whereBetween('created_at', [$this->fromStr(), $this->toStr()]);
                $this->applyUserFilter($q, false);
            })
            ->distinct()
            ->count('user_id');

        return ['count' => $converted, 'base' => $trialUsersCount, 'percent' => $this->pct($converted, $trialUsersCount)];
    }

    /**
     * Топ товаров. Тип товара берётся из payments.sub:
     *   - подписка (pro / smart / …) — название типа + дни (или «пробная» при summ = 1);
     *   - tokens — «Покупка N токенов», где N = payments.days.
     */
    private function topProducts(): array
    {
        return $this->purchasesQuery()
            ->selectRaw('
                sub,
                CASE WHEN summ = 1 THEN 1 ELSE 0 END as is_trial,
                days,
                rub_summ,
                COUNT(*) as sold,
                SUM(rub_summ) as revenue
            ')
            ->groupBy('sub', 'is_trial', 'days', 'rub_summ')
            ->orderByDesc('sold')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->map(fn ($row) => [
                'name'    => $this->productName((string) $row->sub, (int) $row->is_trial === 1, (int) $row->days),
                'price'   => round((float) $row->rub_summ, 2),
                'sold'    => (int) $row->sold,
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    /**
     * Сырой список товаров БЕЗ ограничения по количеству, сгруппированный по
     * названию+цене — чтобы агрегатор мог слить одинаковые товары разных ботов
     * и уже потом взять топ.
     *
     * @return array<string,array{name:string,price:float,sold:int,revenue:float}>
     */
    private function topProductsRaw(): array
    {
        $rows = $this->purchasesQuery()
            ->selectRaw('
                sub,
                CASE WHEN summ = 1 THEN 1 ELSE 0 END as is_trial,
                days,
                rub_summ,
                COUNT(*) as sold,
                SUM(rub_summ) as revenue
            ')
            ->groupBy('sub', 'is_trial', 'days', 'rub_summ')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $name  = $this->productName((string) $row->sub, (int) $row->is_trial === 1, (int) $row->days);
            $price = round((float) $row->rub_summ, 2);
            $key   = $name . '|' . $price;
            if (!isset($out[$key])) {
                $out[$key] = ['name' => $name, 'price' => $price, 'sold' => 0, 'revenue' => 0.0];
            }
            $out[$key]['sold']    += (int) $row->sold;
            $out[$key]['revenue'] += (float) $row->revenue;
        }
        return $out;
    }

    /** Название товара по типу подписки (sub) из payments. */
    private function productName(string $sub, bool $isTrial, int $days): string
    {
        $sub = trim($sub);

        // Покупка токенов: в days лежит количество токенов.
        if (strcasecmp($sub, 'tokens') === 0) {
            return 'Покупка ' . $days . ' токенов';
        }

        $name = match (mb_strtolower($sub)) {
            'pro'   => 'PRO',
            'smart' => 'Smart',
            ''      => 'Подписка',
            default => mb_convert_case($sub, MB_CASE_TITLE, 'UTF-8'),
        };

        return $isTrial ? "$name (пробная)" : "$name $days дн.";
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
