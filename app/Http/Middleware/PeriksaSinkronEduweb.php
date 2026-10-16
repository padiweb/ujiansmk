<?php

namespace App\Http\Middleware;

use App\Eduweb\SinkronOtomatis;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cadangan sinkron otomatis tanpa cron: setelah halaman terkirim ke pengguna (tahap "terminate"),
 * bila sudah lewat EDUWEB_CEK_MENIT sejak pengecekan terakhir, ikuti kejadian terbaru dari EduWeb.
 */
class PeriksaSinkronEduweb
{
    public function __construct(private SinkronOtomatis $otomatis) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->isMethod('GET') || $request->expectsJson()) {
            return;
        }

        try {
            $this->otomatis->cobaBilaPerlu();
        } catch (\Throwable $e) {
            report($e); // pengecekan cadangan tidak boleh mengganggu halaman
        }
    }
}
