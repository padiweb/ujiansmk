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
        $belum = 0;

        foreach ($soal->sortBy(fn (Soal $s) => ($s->tipe === Soal::PG ? 0 : 1).sprintf('%08d', $s->urutan)) as $i => $s) {
            $level = in_array($s->level, ['L1', 'L2', 'L3'], true) ? $s->level : $this->level($s->pertanyaan, $s->tipe === Soal::ESAI);
            $sebaran[$level]++;
            $ada = filled($s->indikator);
            $belum += $ada ? 0 : 1;
            $baris[] = [
                'no' => count($baris) + 1, 'jenis' => $s->tipe, 'materi' => filled($s->materi) ? $s->materi : null,
                'indikator' => $ada ? $s->indikator : $this->indikatorUmum($level, $s->tipe === Soal::ESAI), 'level' => $level,
                'bentuk' => $s->tipe === Soal::PG ? 'Pilihan Ganda' : 'Esai', 'bobot' => rtrim(rtrim((string) $s->bobot, '0'), '.'),
                'kunci' => $s->tipe === Soal::PG ? $s->opsi->firstWhere('benar', true)?->label : null,
            ];
        }

        return ['baris' => $baris, 'sebaran' => $sebaran, 'belum' => $belum, 'jumlah_pg' => $soal->where('tipe', Soal::PG)->count(), 'jumlah_esai' => $soal->where('tipe', Soal::ESAI)->count()];
    }

    /**
     * Indikator pengganti bila guru belum menyusunnya: rumusan umum menurut level, TIDAK memuat kalimat soal.
     * Kisi-kisi dibagikan kepada siswa, jadi pertanyaan yang sebenarnya tidak boleh tercetak di sini.
     */
    public function indikatorUmum(string $level, bool $esai): string
    {
        $kko = match ($level) {
            'L3' => 'menganalisis dan mengevaluasi',
            'L2' => 'menerapkan',
            default => 'mengingat dan memahami',
        };

        return "Peserta didik dapat {$kko} konsep pada materi ini".($esai ? ' dan menguraikannya dalam bentuk esai.' : '.');
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

}
