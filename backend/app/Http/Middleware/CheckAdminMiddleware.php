<?php

namespace App\Http\Middleware;

use App\Models\AdminCookie;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckAdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $cookieparam = Cookie::get("admin");
        if (!$cookieparam) abort (401, "Cookie отсутствуют.");

        $cookie = AdminCookie::where("cookie", $cookieparam)->first();
        if (!$cookie) abort (401, "Cookie не найдены в базе данных.");

        $user = $cookie->user;
        if (!$user) abort (401, "По данным Cookie ни один админ не найден");

        $request->attributes->add(["user" => $user]);
        return $next($request);
    }
}
