<?php

namespace App\Services;

use App\Models\PengaturanSekolah;
use App\Models\SkalaPredikat;
use Illuminate\Support\Collection;

/**
 * Perhitungan nilai rapor. Bobot tiap komponen diatur admin (Pengaturan Rapor); komponen berbobot 0 tidak dipakai.
 *
 *  NP (pengetahuan)  = jumlah (komponen x bobot%) dari ujian, harian (rata-rata nilai tugas dan kuis EduWeb), tugas akhir
 *  NK (keterampilan) = jumlah (komponen x bobot%) dari praktik, adab
 *  NA (nilai akhir)  = NP x bobotNP% + NK x bobotNK%  (NK 0 dan NP > 0 -> NA = NP; NP 0 dan NK > 0 -> NA = NK)
 * Semua hasil dibulatkan ke bilangan bulat seperti aplikasi lama.
 */
class LayananRapor
{
    /** @var list<string> */
    public const KOMPONEN_PENGETAHUAN = ['ujian', 'harian', 'tugas_akhir'];

    /** @var list<string> */
    public const KOMPONEN_KETERAMPILAN = ['praktik', 'adab'];

    public const LABEL = [
        'ujian' => 'Ujian', 'harian' => 'Harian', 'tugas_akhir' => 'Tugas Akhir',
        'praktik' => 'Praktik', 'adab' => 'Adab',
    ];

    /** Bobot bawaan (mengikuti aplikasi lama). */
    public const BAWAAN = [
        'ujian' => 20, 'harian' => 60, 'tugas_akhir' => 20, 'praktik' => 70, 'adab' => 30, 'np' => 50, 'nk' => 50,
    ];

    /** Bobot yang berlaku sekarang. @return array<string,int> */
    public function bobot(): array
    {
        $p = PengaturanSekolah::ambil();
        $bobot = [];
        foreach (array_keys(self::BAWAAN) as $k) {
            $bobot[$k] = (int) ($p->{'bobot_'.$k} ?? self::BAWAAN[$k]);
        }

        return $bobot;
    }

    /**
     * Periksa susunan bobot. @param array<string,int|string|null> $b  @return list<string> pesan galat (kosong = sah)
     */
    public function periksaBobot(array $b): array
    {
        $galat = [];
        foreach (array_keys(self::BAWAAN) as $k) {
            if (! isset($b[$k]) || ! is_numeric($b[$k]) || (int) $b[$k] < 0 || (int) $b[$k] > 100 || (int) $b[$k] != $b[$k]) {
                return ['Setiap bobot harus berupa bilangan bulat 0 sampai 100.'];
            }
        }

        $np = array_sum(array_map(fn ($k) => (int) $b[$k], self::KOMPONEN_PENGETAHUAN));
        $nk = array_sum(array_map(fn ($k) => (int) $b[$k], self::KOMPONEN_KETERAMPILAN));

        if ($np !== 100) {
            $galat[] = "Bobot Pengetahuan (Ujian, Harian, Tugas Akhir) harus berjumlah 100; sekarang {$np}.";
        }
        if ($nk !== 0 && $nk !== 100) {
            $galat[] = "Bobot Keterampilan (Praktik, Adab) harus berjumlah 100, atau 0 semua bila tidak dipakai; sekarang {$nk}.";
        }
        if ((int) $b['np'] + (int) $b['nk'] !== 100) {
            $galat[] = 'Bobot Pengetahuan dan Keterampilan pada nilai akhir harus berjumlah 100; sekarang '.((int) $b['np'] + (int) $b['nk']).'.';
        }

        return $galat;
    }

    /** Komponen yang dipakai (bobot lebih dari 0), urut tampil. @return list<string> */
    public function komponenAktif(?array $bobot = null): array
    {
        $bobot ??= $this->bobot();

        return array_values(array_filter(
            array_merge(self::KOMPONEN_PENGETAHUAN, self::KOMPONEN_KETERAMPILAN),
            fn ($k) => ($bobot[$k] ?? 0) > 0,
        ));
    }

    /** @return Collection<int,SkalaPredikat> urut dari batas tertinggi */
    public function skala(): Collection
    {
        return SkalaPredikat::orderByDesc('nilai_min')->get();
    }

    public function predikat(int $na, ?Collection $skala = null): string
    {
        if ($na <= 0) {
            return '-';
        }

        return ($skala ?? $this->skala())->first(fn ($s) => $na >= $s->nilai_min)?->predikat ?? '-';
    }

    /**
     * Hitung NP, NK, NA, dan predikat dari nilai komponen (nilai kosong dianggap 0).
     *
     * @param  array<string,int|float|string|null>  $nilai
     * @param  array<string,int>|null  $bobot
     * @return array{np:int,nk:int,na:int,predikat:string}
     */
    public function hitung(array $nilai, ?array $bobot = null, ?Collection $skala = null): array
    {
        $bobot ??= $this->bobot();
        $v = fn (string $k) => max(0.0, min(100.0, (float) ($nilai[$k] ?? 0)));

        $jumlah = fn (array $kunci) => (int) round(array_sum(array_map(fn ($k) => $v($k) * ($bobot[$k] ?? 0) / 100, $kunci)));
        $np = $jumlah(self::KOMPONEN_PENGETAHUAN);
        $nk = $jumlah(self::KOMPONEN_KETERAMPILAN);

        $na = match (true) {
            $nk === 0 && $np > 0 => $np,
            $np === 0 && $nk > 0 => $nk,
            default => (int) round(($np * ($bobot['np'] ?? 50) + $nk * ($bobot['nk'] ?? 50)) / 100),
        };

        return ['np' => $np, 'nk' => $nk, 'na' => $na, 'predikat' => $this->predikat($na, $skala)];
    }
}
