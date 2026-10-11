<?php

namespace App\Http\Controllers;

use App\Models\Manager;
use App\Services\ManagerAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerController extends Controller
{
    /** Текущий пользователь и его права — для фронта. GET /api/me */
    public function me(Request $request): JsonResponse
    {
        /** @var ManagerAccess $access */
        $access = $request->attributes->get('access');
        return response()->json($access->toArray());
    }

    /** Список менеджеров. GET /api/managers (только админ) */
    public function index(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $list = Manager::orderBy('id')->get()->map(fn (Manager $m) => $this->present($m));
        return response()->json($list);
    }

    /** Создать менеджера. POST /api/managers */
    public function store(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $data = $this->validateData($request);

        if (Manager::where('telegram_id', $data['telegram_id'])->exists()) {
            return response()->json(['message' => 'Менеджер с таким Telegram ID уже существует'], 422);
        }

        $m = Manager::create($data);
        return response()->json($this->present($m), 201);
    }

    /** Обновить. PUT/PATCH /api/managers/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->assertAdmin($request);
        $m = Manager::findOrFail($id);
        $data = $this->validateData($request, $id);
        $m->update($data);
        return response()->json($this->present($m));
    }

    /** Удалить. DELETE /api/managers/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->assertAdmin($request);
        Manager::findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------

    private function assertAdmin(Request $request): void
    {
        /** @var ManagerAccess $access */
        $access = $request->attributes->get('access');
        if (!$access || !$access->isAdmin) {
            abort(403, 'Только для администратора');
        }
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $v = $request->validate([
            'telegram_id'           => 'required|integer',
            'name'                  => 'nullable|string|max:100',
            'is_admin'              => 'boolean',
            'permissions'           => 'nullable|array',
            'permissions.bots'      => 'nullable',
            'permissions.sections'  => 'nullable|array',
            'permissions.general'   => 'boolean',
            'permissions.contragents' => 'nullable|array',
        ]);

        return [
            'telegram_id' => (int) $v['telegram_id'],
            'name'        => $v['name'] ?? null,
            'is_admin'    => (bool) ($v['is_admin'] ?? false),
            'permissions' => $v['permissions'] ?? [
                'bots' => 'all', 'contragents' => [], 'sections' => ManagerAccess::ALL_SECTIONS, 'general' => false,
            ],
        ];
    }

    private function present(Manager $m): array
    {
        return [
            'id'          => $m->id,
            'telegram_id' => (int) $m->telegram_id,
            'name'        => $m->name,
            'is_admin'    => (bool) $m->is_admin,
            'permissions' => is_array($m->permissions) ? $m->permissions : [],
        ];
    }
}
