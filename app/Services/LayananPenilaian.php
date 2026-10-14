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

        $kelas = $sesi->siswa->kelas()->whereIn('kelas.id', $jadwal->kelas()->select('kelas.id'))->value('kelas.nama');

        $hasil = HasilUjian::updateOrCreate(['sesi_id' => $sesi->id], [
            'benar' => $benar,
            'salah' => $salah,
            'kosong' => $kosong,
            'skor_pg' => $skorPg,
            'mapel_nama' => $jadwal->mapel->nama,
            'kelas_nama' => $kelas,
            'jadwal_nama' => $jadwal->nama,
        ]);

        return $this->hitungEsai($sesi, $hasil);
    }

    /**
     * Hitung skor esai dan skor akhir dari nilai yang sudah diisi guru. Jawaban esai yang kosong otomatis 0.
     * Selama masih ada jawaban berisi yang belum dinilai: status "belum_dikoreksi" dan skor akhir kosong.
     * Aman dipanggil berulang (setiap kali nilai esai berubah).
     */
    public function hitungEsai(SesiUjian $sesi, ?HasilUjian $hasil = null): HasilUjian
    {
        $sesi->loadMissing('jadwal');
        $hasil ??= HasilUjian::where('sesi_id', $sesi->id)->firstOrFail();
        $jadwal = $sesi->jadwal;
        $esai = $this->paket->kunci($jadwal)['esai']; // soal_id => bobot

        if ($esai === []) {
            $hasil->update(['skor_esai' => null, 'skor_akhir' => $hasil->skor_pg, 'status_koreksi' => 'tanpa_esai']);

            return $hasil;
        }

        $jawaban = $sesi->jawaban()->whereIn('soal_id', array_keys($esai))->get()->keyBy('soal_id');
        $jumlah = 0.0;
        $bobotTotal = 0.0;
        $lengkap = true;

        foreach ($esai as $soalId => $bobot) {
            $bobotTotal += $bobot;
            $j = $jawaban[$soalId] ?? null;

            if ($j === null || trim((string) $j->teks_esai) === '') {
                continue; // kosong = 0
            }
            if ($j->nilai_esai === null) {
                $lengkap = false;
                break;
            }
            $jumlah += (float) $j->nilai_esai * $bobot;
        }

        if (! $lengkap) {
            $hasil->update(['skor_esai' => null, 'skor_akhir' => null, 'status_koreksi' => 'belum_dikoreksi']);

            return $hasil;
        }

        $skorEsai = $bobotTotal > 0 ? round($jumlah / $bobotTotal, 2) : null;
        $akhir = match (true) {
            $skorEsai === null => $hasil->skor_pg,
            $hasil->skor_pg === null => $skorEsai,
            default => round((float) $hasil->skor_pg * $jadwal->bobot_pg / 100 + $skorEsai * $jadwal->bobotEsai() / 100, 2),
        };

        $hasil->update(['skor_esai' => $skorEsai, 'skor_akhir' => $akhir, 'status_koreksi' => 'selesai']);

        return $hasil;
    }
}
