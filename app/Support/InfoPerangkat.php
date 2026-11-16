<?php

namespace App\Support;

/**
 * Menebak sistem operasi dan peramban dari User-Agent, serta cara siswa membuka ujian (aplikasi, PWA terpasang, atau peramban).
 * Hanya untuk informasi pengawas: User-Agent bisa dipalsukan, jadi hasilnya tidak dipakai untuk memutuskan apa pun.
 */
class InfoPerangkat
{
    public const MODE = ['aplikasi' => 'Aplikasi', 'pwa' => 'PWA terpasang', 'peramban' => 'Peramban'];

    /** @return array{sistem_operasi:string,nama_peramban:string,mode_tampil:string} */
    public static function dari(string $ua, ?string $modeKlien = null, bool $sentuh = false): array
    {
        $aplikasi = str_contains($ua, 'ESMK-CBT') || str_contains($ua, 'UjianSMKAltan');

        return [
            'sistem_operasi' => self::sistemOperasi($ua, $sentuh),
            'nama_peramban' => self::peramban($ua),
            'mode_tampil' => $aplikasi ? 'aplikasi' : ($modeKlien === 'pwa' ? 'pwa' : 'peramban'),
        ];
    }

    public static function sistemOperasi(string $ua, bool $sentuh = false): string
    {
        return match (true) {
            (bool) preg_match('/Android\s+([\d.]+)/i', $ua, $m) => 'Android '.strtok($m[1], '.'),
            str_contains($ua, 'Android') => 'Android',
            (bool) preg_match('/(iPhone|iPod).*?OS\s+(\d+)/i', $ua, $m) => 'iOS '.$m[2],
            (bool) preg_match('/iPad.*?OS\s+(\d+)/i', $ua, $m) => 'iPadOS '.$m[1],
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPod') => 'iOS',
            str_contains($ua, 'iPad') => 'iPadOS',
            str_contains($ua, 'Macintosh') && $sentuh => 'iPadOS', // iPad yang meminta "situs desktop"
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Tidak dikenal',
        };
    }

    public static function peramban(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/FBAN|FBAV/', $ua) => 'Facebook (dalam aplikasi)',
            str_contains($ua, 'Instagram') => 'Instagram (dalam aplikasi)',
            (bool) preg_match('/musical_ly|BytedanceWebview|TikTok/i', $ua) => 'TikTok (dalam aplikasi)',
            str_contains($ua, 'Line/') => 'LINE (dalam aplikasi)',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'MiuiBrowser') => 'Peramban Xiaomi',
            str_contains($ua, 'HuaweiBrowser') => 'Peramban Huawei',
            str_contains($ua, 'VivoBrowser') => 'Peramban Vivo',
            str_contains($ua, 'UCBrowser') => 'UC Browser',
            (bool) preg_match('/OPR\/|OPT\/|Opera/', $ua) => 'Opera',
            (bool) preg_match('/EdgA\/|EdgiOS\/|Edg\//', $ua) => 'Edge',
            str_contains($ua, 'FxiOS') || str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'DuckDuckGo') => 'DuckDuckGo',
            str_contains($ua, 'Android') && str_contains($ua, '; wv)') => 'WebView (dalam aplikasi)',
            str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Tidak dikenal',
        };
    }
}
