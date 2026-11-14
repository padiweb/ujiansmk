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
            foreach ($jadwal->soalBeku()->with('soal.opsi', 'soal.bacaan')->get() as $beku) {
                $s = $beku->soal;
                $soal[] = [
                    'bacaan' => $s->bacaan ? ['id' => $s->bacaan->id, 'judul' => $s->bacaan->judul, 'teks' => $s->bacaan->teks, 'gambar' => $this->gambar->urlKunci($jadwal, 'b'.$s->bacaan->id, $s->bacaan->gambar)] : null,
                    'opsi_gambar' => $s->tipe === Soal::PG ? $s->opsi->filter(fn ($o) => $o->gambar)->mapWithKeys(fn ($o) => [$o->label => $this->gambar->urlKunci($jadwal, 'o'.$o->id, $o->gambar)])->all() : [],
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
            // Soal yang memakai bacaan yang sama tetap berdampingan: yang diacak adalah urutan kelompoknya.
            $pg = $this->acakKelompok($pg);
            $esai = $this->acakKelompok($esai);
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

    /** Acak urutan satuan (soal tunggal atau kumpulan soal satu bacaan); urutan di dalam satu bacaan dipertahankan. */
    private function acakKelompok(\Illuminate\Support\Collection $daftar): \Illuminate\Support\Collection
    {
        $satuan = [];
        foreach ($daftar as $s) {
            $satuan[($s['bacaan']['id'] ?? null) ? 'b'.$s['bacaan']['id'] : 's'.$s['id']][] = $s;
        }

        return collect($satuan)->shuffle()->flatten(1)->values();
    }

    private function kunciCache(string $jenis, JadwalUjian $jadwal): string
    {
        return "ujian:{$jenis}:{$jadwal->id}";
    }
}
