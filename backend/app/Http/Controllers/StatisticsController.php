<?php

namespace App\Http\Controllers;

use App\Http\Requests\StatisticsGetRequest;
use App\Models\Bots;
use App\Services\BotAnalyticsService;
use App\Services\BotDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    /**
     * Статистика по боту за период с учётом фильтров.
     * GET /api/statistics?time=&bot=&contragent=&link=
     */
    public function get(StatisticsGetRequest $request): JsonResponse
    {
        $data = $request->validated();

        $bot = Bots::findOrFail($data['bot']);

        $service = new BotAnalyticsService(
            $bot,
            (int) $data['time'],
            isset($data['contragent']) ? (int) $data['contragent'] : null,
            isset($data['link']) ? (int) $data['link'] : null,
        );

        return response()->json($service->collect());
    }

    /**
     * Список ботов для селектора.
     * GET /api/bots
     */
    public function bots(): JsonResponse
    {
        $bots = Bots::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Bots $bot) => [
                'id'       => $bot->id,
                'name'     => $bot->name,
                'platform' => $this->platform($bot->name),
            ]);

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

        $bot = Bots::findOrFail($request->integer('bot'));
        $db = BotDatabase::connect($bot);

        $partners = $db->table('partners')->get(['id', 'name', 'links']);

        // Карта link_id -> partner_id (partners.links — JSON-массив id ссылок).
        $linkToPartner = [];
        foreach ($partners as $partner) {
            $ids = is_array($partner->links) ? $partner->links : json_decode((string) $partner->links, true);
            if (!is_array($ids)) {
                continue;
            }
            foreach ($ids as $linkId) {
                $linkToPartner[(int) $linkId] = (int) $partner->id;
            }
        }

        $links = $db->table('links')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($link) => [
                'id'         => (int) $link->id,
                'name'       => (string) $link->name,
                'contragent' => $linkToPartner[(int) $link->id] ?? null,
            ]);

        $contragents = $partners->map(fn ($p) => [
            'id'   => (int) $p->id,
            'name' => (string) ($p->name ?? ('Партнёр #' . $p->id)),
        ])->values();

        return response()->json([
            'contragents' => $contragents,
            'links'       => $links,
        ]);
    }

    /**
     * Грубое определение платформы по имени бота (для бейджа в UI).
     */
    private function platform(?string $name): string
    {
        $name = mb_strtolower((string) $name);

        return str_contains($name, 'max') ? 'MAX' : 'TG';
    }
}
