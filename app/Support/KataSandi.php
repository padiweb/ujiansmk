<?php

namespace App\Support;

class KataSandi
{
    /** Tanpa karakter yang mudah tertukar (0/O, 1/l/I) agar mudah dibaca siswa. */
    private const HURUF = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    public static function acak(int $panjang = 8): string
    {
        $hasil = '';
        $maks = strlen(self::HURUF) - 1;
        for ($i = 0; $i < $panjang; $i++) {
            $hasil .= self::HURUF[random_int(0, $maks)];
        }

        return $hasil;
    }
}
