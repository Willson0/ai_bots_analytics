<?php

namespace App\Services;

use App\Models\Manager;
use Illuminate\Http\Request;

/**
 * Права доступа текущего пользователя (менеджера или супер-админа).
 */
class ManagerAccess
{
    /** Захардкоженные супер-админы (полный доступ). */
    public const SUPER_ADMIN_IDS = [1337592809, 1215122907];

    public const ALL_SECTIONS = ['gen', 'ai', 'shop', 'btn'];

    public int $telegramId;
    public bool $isAdmin;
    public ?string $name;

    /** 'all' | int[] */
    public $bots;
    /** [botId => 'all'|int[]] */
    public array $contragents;
    /** 'all' | string[] (ключи разделов) */
    public $sections;
    public bool $general;

    public static function forRequest(Request $request): ?self
    {
        $tid = TelegramAuth::resolveTelegramId($request);
        if ($tid === null) {
            return null;
        }

        if (in_array($tid, self::SUPER_ADMIN_IDS, true)) {
            return self::superAdmin($tid);
        }

        $m = Manager::where('telegram_id', $tid)->first();
        if (!$m) {
            return null;
        }
        return self::fromManager($m);
    }

    private static function superAdmin(int $tid): self
    {
        $a = new self();
        $a->telegramId = $tid;
        $a->isAdmin = true;
        $a->name = 'Супер-админ';
        $a->bots = 'all';
        $a->contragents = [];
        $a->sections = 'all';
        $a->general = true;
        return $a;
    }

    private static function fromManager(Manager $m): self
    {
        $p = is_array($m->permissions) ? $m->permissions : [];

        $a = new self();
        $a->telegramId = (int) $m->telegram_id;
        $a->isAdmin = (bool) $m->is_admin;
        $a->name = $m->name;
        $a->bots = ($p['bots'] ?? 'all') === 'all' ? 'all' : array_map('intval', (array) ($p['bots'] ?? []));
        $a->contragents = is_array($p['contragents'] ?? null) ? $p['contragents'] : [];
        $a->sections = ($p['sections'] ?? 'all') === 'all' ? 'all' : array_values((array) ($p['sections'] ?? self::ALL_SECTIONS));
        $a->general = (bool) ($p['general'] ?? false);
        return $a;
    }

    // ------------------------------------------------------------------

    public function canBot(int $botId): bool
    {
        return $this->bots === 'all' || in_array($botId, $this->bots, true);
    }

    /** Разрешённые контрагенты для бота: 'all' | int[]. */
    public function contragentScope(int $botId): string|array
    {
        if ($this->isAdmin) {
            return 'all';
        }
        $v = $this->contragents[(string) $botId] ?? ($this->contragents[$botId] ?? 'all');
        return $v === 'all' ? 'all' : array_map('intval', (array) $v);
    }

    public function canSection(string $key): bool
    {
        return $this->sections === 'all' || in_array($key, $this->sections, true);
    }

    public function canGeneral(): bool
    {
        return $this->general;
    }

    /** Данные для /api/me. */
    public function toArray(): array
    {
        return [
            'telegram_id' => $this->telegramId,
            'name'        => $this->name,
            'is_admin'    => $this->isAdmin,
            'bots'        => $this->bots,
            'contragents' => $this->contragents,
            'sections'    => $this->sections === 'all' ? self::ALL_SECTIONS : $this->sections,
            'general'     => $this->general,
        ];
    }
}
