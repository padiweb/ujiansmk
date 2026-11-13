<?php

namespace App\Services;

use App\Models\BankSoal;
use App\Models\Soal;
use Illuminate\Support\Str;

/**
 * Menyusun kisi-kisi dari soal sebuah bank: level kognitif ditebak dari kata kerja operasional pada pertanyaan
 * (L1 pengetahuan/pemahaman, L2 penerapan, L3 penalaran). Guru dapat mengoreksinya di dokumen hasil cetak.
 */
class LayananKisiKisi
{
    private const L3 = ['analisis', 'analisa', 'evaluasi', 'simpulkan', 'kesimpulan', 'rancang', 'rancanglah', 'mengapa', 'bagaimana jika', 'paling tepat', 'solusi', 'prediksi', 'kritisi', 'bandingkan dan', 'pertimbangan', 'merumuskan', 'buatlah', 'kasus', 'studi kasus'];

    private const L2 = ['hitung', 'tentukan', 'terapkan', 'gunakan', 'selesaikan', 'jelaskan', 'bandingkan', 'uraikan', 'cara', 'langkah', 'konfigurasi', 'implementasi', 'identifikasi', 'klasifikasi', 'kerjakan', 'berapa', 'hasil dari', 'output'];

    /**
     * @return array{baris:list<array{no:int,jenis:string,indikator:string,level:string,bentuk:string,bobot:string,kunci:?string}>,sebaran:array<string,int>,jumlah_pg:int,jumlah_esai:int}
     */
    public function susun(BankSoal $bank): array
    {
        $soal = $bank->soal()->with('opsi')->orderBy('tipe')->orderBy('urutan')->orderBy('id')->get();
        $baris = [];
        $sebaran = ['L1' => 0, 'L2' => 0, 'L3' => 0];

        foreach ($soal->sortBy(fn (Soal $s) => ($s->tipe === Soal::PG ? 0 : 1).sprintf('%08d', $s->urutan)) as $i => $s) {
            $level = $this->level($s->pertanyaan, $s->tipe === Soal::ESAI);
            $sebaran[$level]++;
            $baris[] = [
                'no' => count($baris) + 1, 'jenis' => $s->tipe, 'indikator' => $this->indikator($s->pertanyaan), 'level' => $level,
                'bentuk' => $s->tipe === Soal::PG ? 'Pilihan Ganda' : 'Esai', 'bobot' => rtrim(rtrim((string) $s->bobot, '0'), '.'),
                'kunci' => $s->tipe === Soal::PG ? $s->opsi->firstWhere('benar', true)?->label : null,
            ];
        }

        return ['baris' => $baris, 'sebaran' => $sebaran, 'jumlah_pg' => $soal->where('tipe', Soal::PG)->count(), 'jumlah_esai' => $soal->where('tipe', Soal::ESAI)->count()];
    }

    public function level(string $pertanyaan, bool $esai = false): string
    {
        $t = Str::lower($pertanyaan);
        foreach (self::L3 as $k) {
            if (str_contains($t, $k)) {
                return 'L3';
            }
        }
        foreach (self::L2 as $k) {
            if (str_contains($t, $k)) {
                return 'L2';
            }
        }

        return $esai ? 'L2' : 'L1'; // esai minimal menuntut pemahaman/penerapan
    }

    /** Ringkasan pertanyaan sebagai indikator: kalimat pertama yang bermakna, dipendekkan. */
    public function indikator(string $pertanyaan): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $pertanyaan));
        $kalimat = preg_split('/(?<=[.?!:])\s+/u', $t, 2)[0] ?? $t;

        return Str::limit($kalimat !== '' ? $kalimat : $t, 170);
    }
}
