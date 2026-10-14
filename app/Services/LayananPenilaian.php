<?php

namespace App\Services;

use App\Models\HasilUjian;
use App\Models\SesiUjian;

class LayananPenilaian
{
    public function __construct(private LayananPaket $paket) {}

    /**
     * Nilai PG otomatis dan simpan hasilnya. Skor PG = bobot soal benar / bobot semua soal PG x 100.
     * Bila jadwal punya soal esai, skor akhir menunggu koreksi guru.
     */
    public function nilai(SesiUjian $sesi): HasilUjian
    {
        $sesi->loadMissing('jadwal.mapel', 'siswa');
        $jadwal = $sesi->jadwal;
        $peta = $this->paket->kunci($jadwal);
        $jawaban = $sesi->jawaban()->pluck('opsi_label', 'soal_id');

        $benar = $salah = $kosong = 0;
        $bobotBenar = $bobotTotal = 0.0;

        foreach ($peta['pg'] as $soalId => $info) {
            $bobotTotal += $info['bobot'];
            $dipilih = $jawaban[$soalId] ?? null;

            if ($dipilih === null || $dipilih === '') {
                $kosong++;
            } elseif ($info['kunci'] !== null && $dipilih === $info['kunci']) {
                $benar++;
                $bobotBenar += $info['bobot'];
            } else {
                $salah++;
            }
        }

        $skorPg = $bobotTotal > 0 ? round($bobotBenar / $bobotTotal * 100, 2) : null;
        $adaEsai = $peta['esai'] !== [];

        $kelas = $sesi->siswa->kelas()->whereIn('kelas.id', $jadwal->kelas()->select('kelas.id'))->value('kelas.nama');

        return HasilUjian::updateOrCreate(['sesi_id' => $sesi->id], [
            'benar' => $benar,
            'salah' => $salah,
            'kosong' => $kosong,
            'skor_pg' => $skorPg,
            'skor_esai' => null,
            'skor_akhir' => $adaEsai ? null : $skorPg,
            'status_koreksi' => $adaEsai ? 'belum_dikoreksi' : 'tanpa_esai',
            'mapel_nama' => $jadwal->mapel->nama,
            'kelas_nama' => $kelas,
            'jadwal_nama' => $jadwal->nama,
        ]);
    }
}
