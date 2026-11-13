<?php

namespace App\Otomatis;

/**
 * Keluaran model tidak dipercaya. Setiap soal diperiksa ketat; yang cacat dibuang.
 * Urutan opsi diacak ulang di sisi server agar kunci tidak menumpuk di posisi tertentu.
 */
class PenyaringHasil
{
    private const LABEL = ['A', 'B', 'C', 'D', 'E'];

    /**
     * @param  list<array<string,mixed>>  $mentah
     * @return list<array{tipe:string,pertanyaan:string,opsi:array<string,string>,kunci:?string,rubrik:?string}>
     */
    public function saring(array $mentah, PermintaanSoal $p): array
    {
        [$sisaPg, $sisaEsai] = $p->pembagian();
        $hasil = [];

        foreach ($mentah as $item) {
            $tipe = is_string($item['tipe'] ?? null) ? strtolower(trim($item['tipe'])) : '';
            $pertanyaan = $this->teks($item['pertanyaan'] ?? null, 5000);
            if ($pertanyaan === null) {
                continue;
            }

            if ($tipe === 'pg' && $sisaPg > 0) {
                $soal = $this->soalPg($item, $pertanyaan, $p->jumlahOpsi);
                if ($soal) {
                    $hasil[] = $soal + $this->bacaan($item);
                    $sisaPg--;
                }
            } elseif ($tipe === 'esai' && $sisaEsai > 0) {
                $hasil[] = ['tipe' => 'esai', 'pertanyaan' => $pertanyaan, 'opsi' => [], 'kunci' => null,
                    'rubrik' => $this->teks($item['rubrik'] ?? null, 5000)] + $this->bacaan($item);
                $sisaEsai--;
            }
        }

        return $hasil;
    }

    /** Data bacaan yang menempel pada butir soal (bila ada dan sah). @return array<string,mixed> */
    private function bacaan(array $item): array
    {
        $kode = $this->teks($item['bacaan_kode'] ?? null, 30);
        $teks = $this->teks($item['bacaan_data']['teks'] ?? null, 8000);
        if ($kode === null || $teks === null) {
            return [];
        }

        return ['bacaan_kode' => $kode, 'bacaan' => ['judul' => $this->teks($item['bacaan_data']['judul'] ?? null, 150), 'teks' => $teks]];
    }

    private function soalPg(array $item, string $pertanyaan, int $jumlahOpsi): ?array
    {
        $opsi = $item['opsi'] ?? null;
        if (! is_array($opsi) || ! array_is_list($opsi) || count($opsi) !== $jumlahOpsi) {
            return null;
        }

        $kunci = is_string($item['kunci'] ?? null) ? strtoupper(trim($item['kunci'])) : '';
        $indeksKunci = array_search($kunci, array_slice(self::LABEL, 0, $jumlahOpsi), true);
        if ($indeksKunci === false) {
            return null;
        }

        $bersih = [];
        foreach ($opsi as $o) {
            $t = $this->teks($o, 1000);
            if ($t === null) {
                return null;
            }
            $t = trim(preg_replace('/^\s*[A-Ea-e][\.\)]\s+/u', '', $t) ?? $t);
            $bersih[] = $t;
        }

        // Opsi kembar membuat soal tidak adil.
        if (count(array_unique(array_map('mb_strtolower', $bersih))) !== count($bersih)) {
            return null;
        }

        // Acak urutan sambil melacak teks jawaban yang benar.
        $teksBenar = $bersih[$indeksKunci];
        shuffle($bersih);
        $susunan = [];
        $kunciBaru = null;
        foreach ($bersih as $i => $t) {
            $susunan[self::LABEL[$i]] = $t;
            if ($t === $teksBenar) {
                $kunciBaru = self::LABEL[$i];
            }
        }

        return ['tipe' => 'pg', 'pertanyaan' => $pertanyaan, 'opsi' => $susunan, 'kunci' => $kunciBaru, 'rubrik' => null];
    }

    private function teks(mixed $nilai, int $maks): ?string
    {
        if (! is_string($nilai) && ! is_numeric($nilai)) {
            return null;
        }

        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $nilai) ?? '';
        $t = trim($t);

        return $t === '' ? null : mb_substr($t, 0, $maks);
    }
}
