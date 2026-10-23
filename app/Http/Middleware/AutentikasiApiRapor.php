<?php

namespace App\Http\Middleware;

use App\Models\KlienApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentikasi server-ke-server untuk API rapor: header "Authorization: Bearer rpr_...".
 * Semua kegagalan dijawab seragam agar tidak membocorkan apakah kunci itu ada atau sudah dicabut.
 */
class AutentikasiApiRapor
{
    public function handle(Request $request, Closure $next): Response
    {
        $kunci = (string) $request->bearerToken();

        if ($kunci === '' || ! str_starts_with($kunci, 'rpr_') || strlen($kunci) > 80) {
            return $this->tolak();
        }

        $klien = KlienApi::where('hash', KlienApi::hash($kunci))->first();
        if (! $klien || ! $klien->aktif()) {
            return $this->tolak();
        }

        // Jejak pemakaian cukup diperbarui paling sering sekali semenit
        if (! $klien->terakhir_dipakai || $klien->terakhir_dipakai->lt(now()->subMinute())) {
            $klien->forceFill(['terakhir_dipakai' => now(), 'ip_terakhir' => $request->ip()])->saveQuietly();
        }

        $request->attributes->set('klien_api', $klien);

        return $next($request);
    }

    private function tolak(): Response
    {
        return response()->json(['message' => 'Tidak sah.'], 401);
    }
}
