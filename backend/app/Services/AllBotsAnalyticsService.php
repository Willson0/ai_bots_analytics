<?php

namespace App\Services;

use App\Models\Bots;
use Carbon\Carbon;
use Throwable;

/**
 * Сводная статистика по всем (доступным) ботам за всё время.
 *
 * Как считается:
 *   1. Определяем общий временной отрезок: from = самая ранняя регистрация
 *      среди всех ботов, to = сейчас (МСК). Делим на равные бакеты.
 *   2. По каждому боту считаем сырые агрегаты (BotAnalyticsService::rawTotals)
 *      на ЭТОМ ЖЕ отрезке — чтобы столбцы графика совпадали и суммировались.
 *   3. Складываем абсолютные величины, сливаем модели и товары, и только
 *      в самом конце пересчитываем проценты, средние чеки и топы.
 *
 * Пользователи разных ботов — разные БД (непересекающиеся множества),
 * поэтому суммирование уникальных счётчиков по ботам корректно.
 */
class AllBotsAnalyticsService
{
    private const TZ = 'Europe/Moscow';

    /** @var int[] */
    private array $botIds;

    /** Период: 0 — всё время; 7/30/365 и т.п. — N дней. */
    private int $time;

    /** @param int[] $botIds */
    public function __construct(array $botIds, int $time = 0)
    {
        $this->botIds = array_values(array_unique(array_map('intval', $botIds)));
        $this->time = $time;
    }

    public function compute(): array
    {
        /** @var Bots[] $bots */
        $bots = Bots::whereIn('id', $this->botIds)->get();

        // --- 1. Временной отрезок (общий для всех ботов) ----------------
        $now = Carbon::now(self::TZ);

        if ($this->time === 0) {
            // Всё время: отрезок от самой ранней регистрации среди всех ботов,
            // поделённый на равные бакеты (одинаково для всех ботов).
            $earliest = null;
            foreach ($bots as $bot) {
                try {
                    $min = BotDatabase::connect($bot)->table('users')->min('created_at');
                } catch (Throwable $e) {
                    continue; // бот недоступен — учтём на основном проходе
                }
                if ($min) {
                    $c = Carbon::parse($min, self::TZ);
                    if ($earliest === null || $c->lessThan($earliest)) {
                        $earliest = $c;
                    }
                }
            }
            $from = $earliest ?? $now->copy()->subDay();
            $seconds = max(1, $now->getTimestamp() - $from->getTimestamp());
            $forcePeriod = [
                'from'        => $from,
                'to'          => $now,
                'granularity' => 'all',
                'bucketWidth' => (int) ceil($seconds / BotAnalyticsService::ALL_TIME_BUCKETS),
            ];
            $granularity = 'all';
        } else {
            // Неделя/месяц/год: одинаковая дневная шкала, отсчитанная от «сейчас».
            // Она не зависит от данных бота, поэтому у всех ботов бакеты совпадают
            // и forcePeriod не нужен — каждый бот посчитает её сам по $this->time.
            $from = $now->copy()->startOfDay()->subDays($this->time - 1);
            $forcePeriod = null;
            $granularity = 'day';
        }

        // --- 2. Аккумуляторы --------------------------------------------
        $labels = null;
        $activeSeries = [];
        $newSeries = [];

        $acc = [
            'active_total' => 0, 'active_new' => 0, 'active_old' => 0,
            'new_total' => 0, 'premium' => 0, 'op' => 0,
            'ref_links' => 0, 'ref_users' => 0, 'ref_unknown' => 0,
            'q_total' => 0, 'q_text' => 0, 'q_image' => 0, 'q_red_text' => 0, 'q_red_image' => 0,
            'm_purchases' => 0, 'm_revenue' => 0.0, 'm_rev_new' => 0.0, 'm_rev_old' => 0.0,
            'm_one_time' => 0, 'm_auto' => 0, 'm_rev_paid' => 0.0, 'm_cnt_paid' => 0,
            'm_trial_conv' => 0, 'm_trial_base' => 0, 'm_trial_to_pro' => 0,
        ];
        $modelsText = [];
        $modelsImage = [];
        $products = [];

        $botsUsed = 0;
        $skipped = [];

        // --- 3. Основной проход по ботам --------------------------------
        foreach ($bots as $bot) {
            try {
                $svc = new BotAnalyticsService($bot, $this->time, [], [], $forcePeriod);
                $r = $svc->rawTotals();
            } catch (Throwable $e) {
                $skipped[] = (int) $bot->id;
                continue;
            }

            $botsUsed++;
            if ($labels === null) {
                $labels = $r['buckets'];
                $len = count($labels);
                $activeSeries = array_fill(0, $len, 0);
                $newSeries = array_fill(0, $len, 0);
            }

            foreach ($r['active_series'] as $i => $v) {
                if (isset($activeSeries[$i])) {
                    $activeSeries[$i] += (int) $v;
                }
            }
            foreach ($r['new_series'] as $i => $v) {
                if (isset($newSeries[$i])) {
                    $newSeries[$i] += (int) $v;
                }
            }

            $acc['active_total'] += $r['active']['total'];
            $acc['active_new']   += $r['active']['new'];
            $acc['active_old']   += $r['active']['old'];
            $acc['new_total']    += $r['new_total'];
            $acc['premium']      += $r['premium'];
            $acc['op']           += $r['op'];
            $acc['ref_links']    += $r['ref_links'];
            $acc['ref_users']    += $r['ref_users'];
            $acc['ref_unknown']  += $r['ref_unknown'];

            $acc['q_total']     += $r['q']['total'];
            $acc['q_text']      += $r['q']['text'];
            $acc['q_image']     += $r['q']['image'];
            $acc['q_red_text']  += $r['q']['red_text'];
            $acc['q_red_image'] += $r['q']['red_image'];
            $this->mergeCounts($modelsText, $r['q']['models_text']);
            $this->mergeCounts($modelsImage, $r['q']['models_image']);

            $acc['m_purchases']    += $r['m']['purchases'];
            $acc['m_revenue']      += $r['m']['revenue'];
            $acc['m_rev_new']      += $r['m']['rev_new'];
            $acc['m_rev_old']      += $r['m']['rev_old'];
            $acc['m_one_time']     += $r['m']['one_time'];
            $acc['m_auto']         += $r['m']['auto'];
            $acc['m_rev_paid']     += $r['m']['rev_paid'];
            $acc['m_cnt_paid']     += $r['m']['cnt_paid'];
            $acc['m_trial_conv']   += $r['m']['trial_conv_count'];
            $acc['m_trial_base']   += $r['m']['trial_base'];
            $acc['m_trial_to_pro'] += $r['m']['trial_to_pro_count'];

            $this->mergeProducts($products, $r['products']);
        }

        $labels = $labels ?? [];

        // --- 4. Сборка результата (проценты/средние — здесь) ------------
        $series = fn (array $counts) => array_map(
            fn ($label, $i) => ['label' => $label, 'count' => (int) ($counts[$i] ?? 0)],
            $labels,
            array_keys($labels)
        );

        $newTotal = $acc['new_total'];
        $qTotal   = $acc['q_total'];
        $redTotal = $acc['q_red_text'] + $acc['q_red_image'];

        return [
            'period' => [
                'time'        => $this->time,
                'from'        => $from->toIso8601String(),
                'to'          => $now->toIso8601String(),
                'granularity' => $granularity,
            ],
            'users' => [
                'active' => [
                    'total'  => $acc['active_total'],
                    'new'    => $acc['active_new'],
                    'old'    => $acc['active_old'],
                    'series' => $series($activeSeries),
                ],
                'new' => ['total' => $newTotal, 'series' => $series($newSeries)],
                'new_premium'   => ['count' => $acc['premium'], 'percent' => $this->pct($acc['premium'], $newTotal)],
                'subscribed_op' => ['count' => $acc['op'], 'percent' => $this->pct($acc['op'], $newTotal)],
                'referrals' => [
                    'links'   => ['count' => $acc['ref_links'], 'percent' => $this->pct($acc['ref_links'], $newTotal)],
                    'users'   => ['count' => $acc['ref_users'], 'percent' => $this->pct($acc['ref_users'], $newTotal)],
                    'unknown' => ['count' => $acc['ref_unknown'], 'percent' => $this->pct($acc['ref_unknown'], $newTotal)],
                ],
            ],
            'queries' => [
                'total'   => $qTotal,
                'by_type' => [
                    'text'  => ['count' => $acc['q_text'], 'percent' => $this->pct($acc['q_text'], $qTotal)],
                    'image' => ['count' => $acc['q_image'], 'percent' => $this->pct($acc['q_image'], $qTotal)],
                ],
                'top_models' => [
                    'text'  => $this->topModels($modelsText, $qTotal),
                    'image' => $this->topModels($modelsImage, $qTotal),
                ],
                'redirects' => [
                    'total' => $redTotal,
                    'text'  => ['count' => $acc['q_red_text'], 'percent' => $this->pct($acc['q_red_text'], $redTotal)],
                    'image' => ['count' => $acc['q_red_image'], 'percent' => $this->pct($acc['q_red_image'], $redTotal)],
                ],
            ],
            'monetization' => [
                'revenue' => [
                    'total' => round($acc['m_revenue'], 2),
                    'new'   => round($acc['m_rev_new'], 2),
                    'old'   => round($acc['m_rev_old'], 2),
                ],
                'purchases' => [
                    'total'    => $acc['m_purchases'],
                    'one_time' => $acc['m_one_time'],
                    'auto'     => $acc['m_auto'],
                ],
                'avg_check' => [
                    'with_trial'    => $acc['m_purchases'] > 0 ? round($acc['m_revenue'] / $acc['m_purchases'], 2) : 0.0,
                    'without_trial' => $acc['m_cnt_paid'] > 0 ? round($acc['m_rev_paid'] / $acc['m_cnt_paid'], 2) : 0.0,
                ],
                'revenue_per_new_start' => $newTotal > 0 ? round($acc['m_rev_new'] / $newTotal, 2) : 0.0,
                'trial_conversion' => ['count' => $acc['m_trial_conv'], 'percent' => $this->pct($acc['m_trial_conv'], $newTotal)],
                'trial_to_pro'     => ['count' => $acc['m_trial_to_pro'], 'base' => $acc['m_trial_base'], 'percent' => $this->pct($acc['m_trial_to_pro'], $acc['m_trial_base'])],
                'top_products'     => $this->topProducts($products),
            ],
            'buttons' => ['total' => null, 'top' => null],
            'meta' => [
                'bots'    => $botsUsed,
                'skipped' => $skipped,
            ],
        ];
    }

    /** Слить счётчики моделей: $into[model] += $from[model]. */
    private function mergeCounts(array &$into, array $from): void
    {
        foreach ($from as $model => $c) {
            $into[$model] = ($into[$model] ?? 0) + (int) $c;
        }
    }

    /** Слить товары по ключу name|price, суммируя продажи и выручку. */
    private function mergeProducts(array &$into, array $from): void
    {
        foreach ($from as $key => $p) {
            if (!isset($into[$key])) {
                $into[$key] = ['name' => $p['name'], 'price' => $p['price'], 'sold' => 0, 'revenue' => 0.0];
            }
            $into[$key]['sold']    += (int) $p['sold'];
            $into[$key]['revenue'] += (float) $p['revenue'];
        }
    }

    /** Топ моделей по количеству. */
    private function topModels(array $models, int $total): array
    {
        arsort($models);
        $out = [];
        foreach (array_slice($models, 0, BotAnalyticsService::TOP_LIMIT, true) as $model => $c) {
            $out[] = ['model' => (string) $model, 'count' => (int) $c, 'percent' => $this->pct((int) $c, $total)];
        }
        return $out;
    }

    /** Топ товаров по количеству продаж. */
    private function topProducts(array $products): array
    {
        usort($products, fn ($a, $b) => $b['sold'] <=> $a['sold']);
        return array_map(
            fn ($p) => [
                'name'    => $p['name'],
                'price'   => round((float) $p['price'], 2),
                'sold'    => (int) $p['sold'],
                'revenue' => round((float) $p['revenue'], 2),
            ],
            array_slice($products, 0, BotAnalyticsService::TOP_LIMIT)
        );
    }

    private function pct(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 2) : 0.0;
    }
}
