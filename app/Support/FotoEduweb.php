<?php

namespace App\Support;

/**
 * Foto akun dari EduWeb. Yang disimpan hanya jalur di dalam folder storage EduWeb (mis. "photos/abc.jpg"); alamat
 * lengkapnya dibentuk dari EDUWEB_URL saat ditampilkan, jadi data dari luar tidak pernah menentukan host gambar.
 */
class FotoEduweb
{
    /** Bersihkan jalur dari EduWeb; null bila kosong atau bentuknya mencurigakan. */
    public static function bersihkan(mixed $jalur): ?string
    {
        if (! is_string($jalur)) {
            return null;
        }
        if (str_starts_with(trim($jalur), '//')) {
            return null; // alamat tanpa skema ("//host/..."): bukan jalur storage
        }
        $j = ltrim(trim($jalur), '/');
        $j = preg_replace('#^storage/#', '', $j) ?? $j;

        if ($j === '' || strlen($j) > 150 || str_contains($j, '..') || ! preg_match('#^[A-Za-z0-9][A-Za-z0-9_\-./]*\.(?:jpe?g|png|webp|gif)$#i', $j)) {
            return null;
        }

        return $j;
    }

    /** Alamat gambar di EduWeb, atau null bila jalur kosong atau EDUWEB_URL belum diatur. */
    public static function url(?string $jalur): ?string
    {
        // Hanya skema dan host dari EDUWEB_URL yang dipakai (jalur API, bila ada, diabaikan); wajib HTTPS.
        $bagian = parse_url((string) config('eduweb.url'));
        $bersih = self::bersihkan($jalur);
        if (! is_array($bagian) || ($bagian['scheme'] ?? '') !== 'https' || empty($bagian['host']) || $bersih === null) {
            return null;
        }
        $dasar = 'https://'.$bagian['host'].(isset($bagian['port']) ? ':'.$bagian['port'] : '');

        return $dasar.'/storage/'.implode('/', array_map('rawurlencode', explode('/', $bersih)));
    }
}
