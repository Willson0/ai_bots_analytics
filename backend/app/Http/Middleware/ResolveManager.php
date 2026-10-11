<?php

namespace App\Http\Middleware;

use App\Services\ManagerAccess;
use Closure;
use Illuminate\Http\Request;

/**
 * Резолвит текущего менеджера/админа по Telegram initData и кладёт
 * контекст доступа в атрибуты запроса. Без доступа — 401.
 */
class ResolveManager
{
    public function handle(Request $request, Closure $next)
    {
        $access = ManagerAccess::forRequest($request);

        if (!$access) {
            return response()->json(['message' => 'Нет доступа'], 401);
        }

        $request->attributes->set('access', $access);
        return $next($request);
    }
}
