<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pelanggaran;
use App\Models\SesiUjian;
use App\Services\LayananPaket;
use App\Services\LayananPelanggaran;
use App\Services\LayananSesi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Titik akhir pelaksanaan ujian. Sengaja ringan: tanpa sesi/cookie, sedikit kueri per permintaan.
 * Setiap tindakan yang mengubah data selalu lewat LayananSesi (aturan waktu dan status di server).
 */
class UjianApiController extends Controller
{
    public function __construct(private LayananSesi $layanan, private LayananPaket $paket, private LayananPelanggaran $pelanggaran) {}

    /** Paket soal tanpa kunci, sudah diurutkan untuk siswa ini, plus jawaban yang pernah tersimpan. */
    public function paket(Request $request): JsonResponse
    {
        /** @var SesiUjian $sesi */
        $sesi = $request->attributes->get('sesi');
        $jadwal = $sesi->jadwal;

        $susunan = $sesi->susunan ?? $this->paket->susunan($jadwal);
        $soal = collect($this->paket->paket($jadwal)['soal'])->keyBy('id');

        $daftar = [];
        foreach ($susunan['soal'] as $id) {
            if (! isset($soal[$id])) {
                continue;
            }
            $s = $soal[$id];
            $daftar[] = [
                'id' => $s['id'],
                'tipe' => $s['tipe'],
                'pertanyaan' => $s['pertanyaan'],
                'gambar' => $s['gambar'],
                // Urutan opsi khusus siswa; "label" adalah label ASLI (dikirim balik saat menjawab),
                // sedangkan huruf yang dilihat siswa ditentukan oleh posisinya.
                'opsi' => $s['tipe'] === 'pg'
                    ? collect($susunan['opsi'][$id] ?? array_keys((array) $s['opsi']))->map(fn ($l) => ['label' => $l, 'teks' => ((array) $s['opsi'])[$l] ?? ''])->all()
                    : [],
            ];
        }

        $jawaban = $sesi->jawaban()->get(['soal_id', 'opsi_label', 'teks_esai'])
            ->mapWithKeys(fn ($j) => [$j->soal_id => ['o' => $j->opsi_label, 't' => $j->teks_esai]]);

        return response()->json([
            'jadwal' => ['nama' => $jadwal->nama, 'mapel' => $jadwal->mapel->nama, 'batas_pelanggaran' => $jadwal->batas_pelanggaran],
            'soal' => $daftar,
            'jawaban' => (object) $jawaban->all(),
            'keadaan' => $this->layanan->keadaan($sesi),
        ])->header('Cache-Control', 'no-store');
    }

    public function sinkron(Request $request): JsonResponse
    {
        $keadaan = $this->layanan->sinkron($request->attributes->get('sesi'), (array) $request->input('jawaban', []));

        return response()->json($keadaan)->header('Cache-Control', 'no-store');
    }

    /** Laporan pelanggaran dari peramban. Hitungan dan blokir diputuskan server (LayananPelanggaran). */
    public function pelanggaran(Request $request): JsonResponse
    {
        $data = $request->validate([
            'jenis' => ['required', 'string', Rule::in(array_keys(Pelanggaran::JENIS))],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'info' => ['nullable', 'array'],
        ]);

        $keadaan = $this->pelanggaran->catat(
            $request->attributes->get('sesi'),
            $data['jenis'],
            $data['keterangan'] ?? null,
            $this->saringInfo($data['info'] ?? []),
        );

        return response()->json($keadaan)->header('Cache-Control', 'no-store');
    }

    /** Hanya kunci dan nilai sederhana yang diizinkan; sisanya dibuang. */
    private function saringInfo(array $info): array
    {
        $diizinkan = ['durasi', 'lebar', 'tinggi', 'layar_lebar', 'layar_tinggi', 'rasio', 'alasan'];
        $bersih = [];

        foreach ($diizinkan as $kunci) {
            $nilai = $info[$kunci] ?? null;
            if (is_int($nilai) || is_float($nilai)) {
                $bersih[$kunci] = round((float) $nilai, 3);
            } elseif (is_string($nilai)) {
                $bersih[$kunci] = mb_substr($nilai, 0, 60);
            }
        }

        return $bersih;
    }

    public function kirim(Request $request): JsonResponse
    {
        /** @var SesiUjian $sesi */
        $sesi = $request->attributes->get('sesi');
        try {
            $hasil = $this->layanan->kirim($sesi, (array) $request->input('jawaban', []));
        } catch (\App\Services\KesalahanUjian $e) {
            if ($e->kode !== 'terlalu_cepat') {
                throw $e;
            }

            // Jawaban dikirim bersama agar tidak hilang; hanya penyelesaian yang ditahan.
            $this->layanan->sinkron($sesi, (array) $request->input('jawaban', []));

            return response()->json(['pesan' => $e->getMessage(), 'kode' => $e->kode] + $this->layanan->keadaan($sesi->refresh()), $e->status)->header('Cache-Control', 'no-store');
        }
        $sesi->refresh();

        $tampil = $sesi->jadwal->tampilkan_hasil === 'langsung';

        return response()->json($this->layanan->keadaan($sesi) + [
            'selesai' => true,
            'hasil' => $tampil ? [
                'skor_pg' => $hasil->skor_pg !== null ? (float) $hasil->skor_pg : null,
                'benar' => $hasil->benar,
                'salah' => $hasil->salah,
                'kosong' => $hasil->kosong,
                'menunggu_esai' => $hasil->status_koreksi === 'belum_dikoreksi',
            ] : null,
        ])->header('Cache-Control', 'no-store');
    }
}
