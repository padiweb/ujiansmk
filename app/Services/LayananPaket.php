<?php

namespace App\Services;

use App\Models\JadwalUjian;
use App\Models\Soal;
use Illuminate\Support\Facades\Cache;

/**
 * Paket soal satu jadwal dibuat sekali lalu di-cache; setiap siswa hanya mendapat urutannya sendiri.
 * Paket TIDAK PERNAH memuat kunci jawaban atau jawaban acuan esai. Peta kunci disimpan terpisah
 * dan hanya dipakai server saat menilai.
 */
class LayananPaket
{
    private const TTL = 21600; // 6 jam

    public function __construct(private LayananGambarUjian $gambar) {}

    /** @return array{soal: list<array<string,mixed>>} */
    public function paket(JadwalUjian $jadwal): array
    {
        return Cache::remember($this->kunciCache('paket', $jadwal), self::TTL, function () use ($jadwal) {
            $soal = [];
            foreach ($jadwal->soalBeku()->with('soal.opsi')->get() as $beku) {
                $s = $beku->soal;
                $soal[] = [
                    'id' => $s->id,
                    'tipe' => $s->tipe,
                    'pertanyaan' => $s->pertanyaan,
                    'gambar' => $this->gambar->url($jadwal, $s),
                    'opsi' => $s->tipe === Soal::PG ? $s->opsi->pluck('teks', 'label')->all() : (object) [],
                ];
            }

            return ['soal' => $soal];
        });
    }

    /**
     * Peta penilaian. pg: soal_id => ['kunci' => 'B', 'bobot' => 1.0]; esai: soal_id => bobot.
     *
     * @return array{pg: array<int,array{kunci:?string,bobot:float}>, esai: array<int,float>}
     */
    public function kunci(JadwalUjian $jadwal): array
    {
        return Cache::remember($this->kunciCache('kunci', $jadwal), self::TTL, function () use ($jadwal) {
            $peta = ['pg' => [], 'esai' => []];
            foreach ($jadwal->soalBeku()->with('soal.opsi')->get() as $beku) {
                $s = $beku->soal;
                if ($s->tipe === Soal::PG) {
                    $peta['pg'][$s->id] = ['kunci' => $s->opsi->firstWhere('benar', true)?->label, 'bobot' => (float) $beku->bobot];
                } else {
                    $peta['esai'][$s->id] = (float) $beku->bobot;
                }
            }

            return $peta;
        });
    }

    public function lupakan(JadwalUjian $jadwal): void
    {
        Cache::forget($this->kunciCache('paket', $jadwal));
        Cache::forget($this->kunciCache('kunci', $jadwal));
    }

    /**
     * Urutan khusus satu siswa: id soal (PG dulu, lalu esai, masing-masing diacak bila diaktifkan)
     * dan urutan label asli opsi tiap soal PG. Posisi tampil menentukan huruf yang dilihat siswa;
     * jawaban selalu disimpan sebagai label asli.
     *
     * @return array{soal: list<int>, opsi: array<int,list<string>>}
     */
    public function susunan(JadwalUjian $jadwal): array
    {
        $paket = $this->paket($jadwal)['soal'];
        $pg = collect($paket)->where('tipe', Soal::PG)->values();
        $esai = collect($paket)->where('tipe', Soal::ESAI)->values();

        if ($jadwal->acak_soal) {
            $pg = $pg->shuffle()->values();
            $esai = $esai->shuffle()->values();
        }

        $opsi = [];
        foreach ($pg as $s) {
            $label = array_keys((array) $s['opsi']);
            if ($jadwal->acak_opsi) {
                shuffle($label);
            }
            $opsi[$s['id']] = $label;
        }

        return ['soal' => $pg->concat($esai)->pluck('id')->all(), 'opsi' => $opsi];
    }

    private function kunciCache(string $jenis, JadwalUjian $jadwal): string
    {
        return "ujian:{$jenis}:{$jadwal->id}";
    }
}
