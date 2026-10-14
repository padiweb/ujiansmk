<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PastikanPeran
{
    /**
     * Pemakaian: ->middleware('peran:admin,guru')
     */
    public function handle(Request $request, Closure $next, string ...$peran): Response
    {
        $user = $request->user('web');

        if (! $user || ! $user->aktif || ! $user->punyaPeran(...$peran)) {
            abort(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        return $next($request);
    }
}
