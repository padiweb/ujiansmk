<?php

namespace App\Services;

use App\Models\JadwalUjian;
use App\Models\Soal;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gambar soal untuk siswa dilayani langsung oleh server web (tanpa PHP) agar 2.000 siswa tidak
 * menghasilkan puluhan ribu permintaan PHP. Berkasnya disalin ke folder statis dengan nama yang
 * tidak bisa ditebak, hanya saat jadwal aktif, lalu dihapus saat jadwal selesai atau dibatalkan.
 * Nama berkas diturunkan dari HMAC (kunci aplikasi), jadi tidak perlu tabel pemetaan.
 */
class LayananGambarUjian
{
    public function jalurDasar(): string
    {
        return rtrim(config('ujian.jalur_media') ?: public_path('media/u'), '/\\');
    }

    public function urlDasar(): string
    {
        return rtrim(config('ujian.url_media') ?: url('media/u'), '/');
    }

    /** Salin gambar semua soal beku jadwal ke folder statis. Mengembalikan jumlah gambar. */
    public function salinKePublik(JadwalUjian $jadwal): int
    {
        if (! $jadwal->media_kode) {
            $jadwal->update(['media_kode' => Str::random(40)]);
        }

        $folder = $this->jalurDasar().DIRECTORY_SEPARATOR.$jadwal->media_kode;
        $jumlah = 0;

        $salin = function (?string $sumber, string $nama) use ($folder, &$jumlah) {
            if (! $sumber || ! Storage::disk('local')->exists($sumber)) {
                return;
            }
            File::ensureDirectoryExists($folder);
            File::copy(Storage::disk('local')->path($sumber), $folder.DIRECTORY_SEPARATOR.$nama);
            $jumlah++;
        };

        foreach ($jadwal->soalBeku()->with('soal.opsi', 'soal.bacaan')->get() as $beku) {
            $soal = $beku->soal;
            if (! $soal) {
                continue;
            }
            $salin($soal->gambar, $this->namaBerkas($jadwal, $soal));
            foreach ($soal->opsi as $o) {
                $salin($o->gambar, $this->namaBerkasKunci($jadwal, "o{$o->id}", $o->gambar));
            }
            if ($soal->bacaan) {
                $salin($soal->bacaan->gambar, $this->namaBerkasKunci($jadwal, "b{$soal->bacaan->id}", $soal->bacaan->gambar));
            }
        }

        return $jumlah;
    }

    public function bersihkan(JadwalUjian $jadwal): void
    {
        if ($jadwal->media_kode) {
            File::deleteDirectory($this->jalurDasar().DIRECTORY_SEPARATOR.$jadwal->media_kode);
        }
    }

    public function url(JadwalUjian $jadwal, Soal $soal): ?string
    {
        if (! $soal->gambar || ! $jadwal->media_kode) {
            return null;
        }

        return $this->urlDasar().'/'.$jadwal->media_kode.'/'.$this->namaBerkas($jadwal, $soal);
    }

    /** URL gambar opsi atau bacaan ($jenis: 'o'.id opsi, 'b'.id bacaan). */
    public function urlKunci(JadwalUjian $jadwal, string $jenis, ?string $berkas): ?string
    {
        if (! $berkas || ! $jadwal->media_kode) {
            return null;
        }

        return $this->urlDasar().'/'.$jadwal->media_kode.'/'.$this->namaBerkasKunci($jadwal, $jenis, $berkas);
    }

    private function namaBerkasKunci(JadwalUjian $jadwal, string $jenis, ?string $berkas): string
    {
        $ekstensi = strtolower(pathinfo((string) $berkas, PATHINFO_EXTENSION)) ?: 'webp';

        return substr(hash_hmac('sha256', "{$jadwal->id}|{$jenis}", (string) config('app.key')), 0, 40).'.'.$ekstensi;
    }

    private function namaBerkas(JadwalUjian $jadwal, Soal $soal): string
    {
        $ekstensi = strtolower(pathinfo((string) $soal->gambar, PATHINFO_EXTENSION)) ?: 'webp';

        return substr(hash_hmac('sha256', "{$jadwal->id}|{$soal->id}", (string) config('app.key')), 0, 40).'.'.$ekstensi;
    }
}
