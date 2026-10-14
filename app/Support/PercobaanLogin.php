<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pembatasan percobaan login: per akun+IP (5 per menit) dan per IP (30 per menit).
 */
class PercobaanLogin
{
    private const MAKS_AKUN = 5;
    private const MAKS_IP = 30;
    private const DETIK = 60;

    public static function pastikanBolehMencoba(Request $request, string $akun, string $guard): void
    {
        foreach (self::kunci($request, $akun, $guard) as $kunci => $maks) {
            if (RateLimiter::tooManyAttempts($kunci, $maks)) {
                $detik = RateLimiter::availableIn($kunci);

                throw ValidationException::withMessages([
                    'username' => "Terlalu banyak percobaan masuk. Coba lagi dalam {$detik} detik.",
                ])->status(429);
            }
        }
    }

    public static function gagal(Request $request, string $akun, string $guard): void
    {
        foreach (array_keys(self::kunci($request, $akun, $guard)) as $kunci) {
            RateLimiter::hit($kunci, self::DETIK);
        }
    }

    public static function berhasil(Request $request, string $akun, string $guard): void
    {
        // Hanya menghapus penghitung akun+IP; penghitung IP tetap berjalan.
        RateLimiter::clear(array_key_first(self::kunci($request, $akun, $guard)));
    }

    /** @return array<string,int> */
    private static function kunci(Request $request, string $akun, string $guard): array
    {
        $akun = Str::lower(trim($akun));

        return [
            "login:{$guard}:{$akun}|{$request->ip()}" => self::MAKS_AKUN,
            "login-ip:{$guard}:{$request->ip()}" => self::MAKS_IP,
        ];
    }
}
