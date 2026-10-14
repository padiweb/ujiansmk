<?php

namespace App\Http\Middleware;

use App\Models\SesiUjian;
use App\Services\LayananSesi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi titik akhir ujian TANPA sesi/cookie: sesi ujian + token perangkat acak di header.
 * Sengaja menjawab sama untuk "sesi tidak ada" dan "token salah" agar tidak membocorkan keberadaan sesi.
 */
class PastikanPerangkatUjian
{
    public function __construct(private LayananSesi $layanan) {}

    public function handle(Request $request, Closure $next): Response
    {
        $sesi = SesiUjian::query()->with('jadwal')->find((int) $request->route('sesi'));

        if (! $sesi || ! $this->layanan->perangkatCocok($sesi, $request->header('X-Perangkat'))) {
            return response()->json(['kode' => 'tidak_sah', 'pesan' => 'Perangkat tidak sah untuk sesi ini. Muat ulang halaman ujian.'], 401);
        }

        if (strlen($request->getContent()) > 3_000_000) {
            return response()->json(['kode' => 'terlalu_besar', 'pesan' => 'Data terlalu besar.'], 413);
        }

        $request->attributes->set('sesi', $sesi);

        return $next($request);
    }
}
