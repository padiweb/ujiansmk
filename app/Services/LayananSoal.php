<?php

namespace App\Services;

use App\Models\BankSoal;
use App\Models\OpsiSoal;
use App\Models\Soal;
use App\Models\User;
use App\Support\GambarAman;
use Illuminate\Support\Facades\DB;

class LayananSoal
{
    public const LABEL = ['A', 'B', 'C', 'D', 'E'];

    /**
     * Ganti seluruh opsi soal PG. $opsi = ['A' => 'teks', ...] (hanya yang terisi), $kunci = 'A'..'E'.
     */
    public function setOpsi(Soal $soal, array $opsi, string $kunci, array $gambar = []): void
    {
        $lama = $soal->opsi()->pluck('gambar', 'label')->all();
        $soal->opsi()->delete();

        foreach ($opsi as $label => $teks) {
            OpsiSoal::create([
                'soal_id' => $soal->id,
                'label' => $label,
                'teks' => (string) $teks,
                'gambar' => $gambar[$label] ?? null,
                'benar' => $label === $kunci,
            ]);
        }

        // Berkas gambar opsi lama yang tidak dipakai lagi dihapus dari penyimpanan.
        $dipakai = array_filter($gambar);
        foreach ($lama as $berkas) {
            if ($berkas && ! in_array($berkas, $dipakai, true)) {
                GambarAman::hapus($berkas);
            }
        }
    }

    /** Pasangan (mapel_id, tingkat) yang diampu guru pada tahun ajaran aktif. */
    public function pasanganDiampu(User $guru): array
    {
        return \App\Models\Pengampu::query()
            ->join('kelas', 'kelas.id', '=', 'pengampu.kelas_id')
            ->join('tahun_ajaran', 'tahun_ajaran.id', '=', 'kelas.tahun_ajaran_id')
            ->where('tahun_ajaran.aktif', true)
            ->where('pengampu.guru_id', $guru->id)
            ->select('pengampu.mapel_id', 'kelas.tingkat')
            ->distinct()
            ->get()
            ->map(fn ($r) => [(int) $r->mapel_id, $r->tingkat])
            ->all();
    }

    public function bolehMembuat(User $user, int $mapelId, string $tingkat): bool
    {
        if ($user->role === User::ADMIN) {
            return true;
        }

        return $user->role === User::GURU && in_array([$mapelId, $tingkat], $this->pasanganDiampu($user), true);
    }

    /** Salin bank beserta seluruh soal, opsi, dan gambarnya menjadi bank baru milik $oleh. */
    public function salinBank(BankSoal $asal, User $oleh, string $namaBaru): BankSoal
    {
        return DB::transaction(function () use ($asal, $oleh, $namaBaru) {
            $baru = BankSoal::create([
                'mapel_id' => $asal->mapel_id,
                'tingkat' => $asal->tingkat,
                'nama' => $namaBaru,
                'jumlah_opsi_bawaan' => $asal->jumlah_opsi_bawaan,
                'dibuat_oleh' => $oleh->id,
            ]);

            $petaBacaan = [];
            foreach ($asal->bacaan()->get() as $b) {
                $petaBacaan[$b->id] = \App\Models\BacaanSoal::create(['bank_soal_id' => $baru->id, 'judul' => $b->judul, 'teks' => $b->teks, 'gambar' => GambarAman::salin($b->gambar), 'sumber' => $b->sumber])->id;
            }

            foreach ($asal->soal()->with('opsi')->get() as $s) {
                $salinan = $s->replicate(['bank_soal_id', 'gambar', 'bacaan_id']);
                $salinan->bank_soal_id = $baru->id;
                $salinan->bacaan_id = $petaBacaan[$s->bacaan_id] ?? null;
                $salinan->gambar = GambarAman::salin($s->gambar);
                $salinan->save();

                foreach ($s->opsi as $o) {
                    OpsiSoal::create(['soal_id' => $salinan->id, 'label' => $o->label, 'teks' => $o->teks, 'gambar' => GambarAman::salin($o->gambar), 'benar' => $o->benar]);
                }
            }

            return $baru;
        });
    }
}
