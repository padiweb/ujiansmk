<?php

namespace App\Services;

use App\Models\Pelanggaran;
use App\Models\SesiUjian;
use Illuminate\Support\Facades\DB;

/**
 * Hitungan pelanggaran dikelola SERVER, bukan peramban, sehingga tidak bisa direset siswa
 * (login ulang, hapus cookie, ubah penyimpanan lokal). Peramban hanya melapor.
 *
 * Aturan hitungan (config/ujian.php → pelanggaran):
 *  - Hanya jenis berstatus "dihitung" yang menambah hitungan; sisanya hanya dicatat untuk pengawas.
 *  - Satu kejadian fisik sering memicu beberapa sensor: maksimal SATU hitungan per jeda_semua detik.
 *  - Pelanggaran sejenis dalam jeda_sejenis detik tidak dihitung dua kali.
 *  - Beberapa detik pertama setelah mulai diabaikan (peralihan ke layar penuh).
 *  - Hitungan mencapai batas_pelanggaran jadwal → sesi diblokir.
 */
class LayananPelanggaran
{
    public function __construct(private LayananSesi $sesi) {}

    /**
     * @param  array<string,scalar>  $info  sudah disaring oleh pemanggil
     * @return array<string,mixed> keadaan sesi + hasil pencatatan
     */
    public function catat(SesiUjian $sesi, string $jenis, ?string $keterangan, array $info = []): array
    {
        if ($sesi->status === SesiUjian::SELESAI) {
            return $this->sesi->keadaan($sesi) + ['dicatat' => false, 'dihitung' => false];
        }

        if (now()->gt($sesi->batas_selesai->copy()->addSeconds($this->sesi->toleransi()))) {
            return $this->sesi->keadaan($this->sesi->selesaikan($sesi, 'waktu_habis')->sesi) + ['dicatat' => false, 'dihitung' => false];
        }

        $hasil = DB::transaction(function () use ($sesi, $jenis, $keterangan, $info) {
            $s = SesiUjian::query()->with('jadwal')->lockForUpdate()->findOrFail($sesi->id);
            if ($s->status === SesiUjian::SELESAI) {
                return [$s, false, false];
            }

            $cfg = config('ujian.pelanggaran');
            $sekarang = now();

            // Pelindung banjir catatan (klien rusak atau sengaja membanjiri).
            $barisMenitIni = Pelanggaran::where('sesi_id', $s->id)->where('created_at', '>=', $sekarang->copy()->subMinute())->count();
            if ($barisMenitIni >= $cfg['maks_catatan_per_menit']) {
                return [$s, false, false];
            }

            $dihitung = Pelanggaran::JENIS[$jenis][1] && $s->status === SesiUjian::BERLANGSUNG;

            if ($dihitung && $s->mulai_pada && $sekarang->lt($s->mulai_pada->copy()->addSeconds($cfg['abaikan_awal']))) {
                $dihitung = false;
            }

            if ($dihitung) {
                $jeda = max($cfg['jeda_semua'], $cfg['jeda_sejenis']);
                $terbaru = Pelanggaran::where('sesi_id', $s->id)->where('dihitung', true)
                    ->where('created_at', '>=', $sekarang->copy()->subSeconds($jeda))->get(['jenis', 'created_at']);

                foreach ($terbaru as $p) {
                    $umur = $p->created_at->diffInSeconds($sekarang, true);
                    if ($umur < $cfg['jeda_semua'] || ($p->jenis === $jenis && $umur < $cfg['jeda_sejenis'])) {
                        $dihitung = false;
                        break;
                    }
                }
            }

            Pelanggaran::create([
                'sesi_id' => $s->id,
                'jenis' => $jenis,
                'keterangan' => $keterangan !== null ? mb_substr($keterangan, 0, 255) : null,
                'dihitung' => $dihitung,
                'info' => $info ?: null,
                'created_at' => $sekarang, // jam aplikasi, bukan jam basis data (zona waktu bisa berbeda)
            ]);

            if ($dihitung) {
                $s->increment('jumlah_pelanggaran');
                $s->refresh();

                if ($s->jumlah_pelanggaran >= $s->jadwal->batas_pelanggaran) {
                    $s->update(['status' => SesiUjian::DIBLOKIR]);
                }
            }

            return [$s, true, $dihitung];
        });

        [$terkini, $dicatat, $dihitung] = $hasil;

        return $this->sesi->keadaan($terkini) + [
            'dicatat' => $dicatat,
            'dihitung' => $dihitung,
            'ke' => $terkini->jumlah_pelanggaran,
            'batas' => $terkini->jadwal->batas_pelanggaran,
        ];
    }
}
