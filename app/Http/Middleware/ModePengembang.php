<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Halaman teknis hanya ada bila MODE_PENGEMBANG=true di .env; selain itu seolah tidak ada (404). */
class ModePengembang
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('ujian.mode_pengembang'), 404);

        return $next($request);
    }
}
