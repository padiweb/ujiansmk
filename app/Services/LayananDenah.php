<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Denah;
use App\Models\DenahPenempatan;
use App\Models\DenahRuang;
use App\Models\JadwalUjian;
use App\Models\SesiUjian;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Denah ruang ujian: pembagian otomatis campuran tingkat, perubahan manual, dan penerapan ke sesi jadwal. */
class LayananDenah
{
    /**
     * Bagi siswa dari kelas-kelas terpilih ke ruang-ruang denah. Siswa diacak per tingkat; tiap meja diisi dari
     * tingkat yang berbeda (kelompok terbanyak didahulukan) agar tidak saling menyontek. Penempatan lama diganti.
     *
     * @param  list<int>  $kelasIds
     * @return array{ditempatkan:int,tanpa_tempat:int,meja_sejenis:int,kapasitas:int}
     *
     * @throws KesalahanJadwal
     */
    public function bagi(Denah $denah, array $kelasIds, ?User $pelaku = null, ?int $benih = null): array
    {
        $ruang = $denah->ruang()->get();
        if ($ruang->isEmpty()) {
            throw new KesalahanJadwal('Tambahkan minimal satu ruang ke denah ini.');
        }

        $baris = DB::table('keanggotaan_kelas as k')
            ->join('siswa as s', 's.id', '=', 'k.siswa_id')
            ->join('kelas as kl', 'kl.id', '=', 'k.kelas_id')
            ->where('s.status', 'aktif')->where('k.status', 'aktif')
            ->whereIn('k.kelas_id', $kelasIds)
            ->select('s.id as siswa_id', 'kl.tingkat')->get()->unique('siswa_id');

        if ($baris->isEmpty()) {
            throw new KesalahanJadwal('Tidak ada siswa aktif di kelas yang dipilih.');
        }

        if ($benih !== null) {
            mt_srand($benih);
        }
        /** @var array<string,list<int>> $kelompok tingkat => id siswa (teracak) */
        $kelompok = $baris->groupBy('tingkat')->map(fn ($g) => $g->pluck('siswa_id')->shuffle()->values()->all())->all();

        $hasil = [];
        $ditempatkan = 0;

        foreach ($ruang as $r) {
            for ($meja = 1; $meja <= $r->jumlah_meja; $meja++) {
                $dipakai = [];
                for ($posisi = 1; $posisi <= $r->siswa_per_meja; $posisi++) {
                    $pilih = $this->pilihKelompok($kelompok, $dipakai);
                    if ($pilih === null) {
                        break 3; // semua siswa sudah mendapat tempat
                    }
                    $dipakai[] = $pilih;
                    $hasil[] = ['denah_id' => $denah->id, 'siswa_id' => array_shift($kelompok[$pilih]), 'ruang_id' => $r->ruang_id, 'meja' => $meja, 'posisi' => $posisi];
                    $ditempatkan++;
                }
            }
        }

        $sisa = collect($kelompok)->sum(fn ($g) => count($g));
        $tingkatSiswa = $baris->pluck('tingkat', 'siswa_id');
        $sejenis = collect($hasil)->groupBy(fn ($h) => $h['ruang_id'].'-'.$h['meja'])
            ->filter(fn ($meja) => $meja->count() > 1 && $meja->pluck('siswa_id')->map(fn ($id) => $tingkatSiswa[$id])->unique()->count() < $meja->count())->count();

        DB::transaction(function () use ($denah, $hasil) {
            DenahPenempatan::where('denah_id', $denah->id)->delete();
            foreach (array_chunk($hasil, 500) as $potong) {
                DenahPenempatan::insert($potong);
            }
        });

        $this->perbaruiJadwal($denah);
        if ($pelaku) {
            AuditLog::catat('denah.bagi', "denah {$denah->id}: {$ditempatkan} ditempatkan, {$sisa} tanpa tempat", $pelaku);
        }

        return ['ditempatkan' => $ditempatkan, 'tanpa_tempat' => $sisa, 'meja_sejenis' => $sejenis, 'kapasitas' => $ruang->sum(fn (DenahRuang $r) => $r->kapasitas())];
    }

    /**
     * Pindahkan siswa ke meja/posisi tertentu; bila terisi, kedua siswa bertukar tempat.
     *
     * @throws KesalahanJadwal
     */
    public function tempatkan(Denah $denah, Siswa $siswa, int $ruangId, int $meja, int $posisi, ?User $pelaku = null): void
    {
        $r = DenahRuang::where('denah_id', $denah->id)->where('ruang_id', $ruangId)->first()
            ?? throw new KesalahanJadwal('Ruang itu bukan bagian dari denah ini.');

        if ($meja < 1 || $meja > $r->jumlah_meja) {
            throw new KesalahanJadwal("Nomor meja harus antara 1 dan {$r->jumlah_meja}.");
        }
        if ($posisi < 1 || $posisi > $r->siswa_per_meja) {
            throw new KesalahanJadwal("Posisi harus antara 1 dan {$r->siswa_per_meja} (siswa per meja di ruang ini).");
        }

        DB::transaction(function () use ($denah, $siswa, $ruangId, $meja, $posisi) {
            $lama = DenahPenempatan::where('denah_id', $denah->id)->where('siswa_id', $siswa->id)->lockForUpdate()->first();
            $penghuni = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruangId)->where('meja', $meja)->where('posisi', $posisi)->lockForUpdate()->first();

            if ($penghuni && $lama && $penghuni->id === $lama->id) {
                return;
            }

            if ($penghuni && $lama) {
                // Tukar: kosongkan salah satu dulu agar tidak melanggar keunikan tempat.
                $tempatLama = $lama->only(['ruang_id', 'meja', 'posisi']);
                $lama->delete();
                $penghuni->update($tempatLama);
                DenahPenempatan::create(['denah_id' => $denah->id, 'siswa_id' => $siswa->id, 'ruang_id' => $ruangId, 'meja' => $meja, 'posisi' => $posisi]);

                return;
            }
            if ($penghuni) {
                $penghuni->delete(); // siswa baru menggantikan; penghuni lama dilepas (belum bertempat)
            }
            if ($lama) {
                $lama->update(['ruang_id' => $ruangId, 'meja' => $meja, 'posisi' => $posisi]);
            } else {
                DenahPenempatan::create(['denah_id' => $denah->id, 'siswa_id' => $siswa->id, 'ruang_id' => $ruangId, 'meja' => $meja, 'posisi' => $posisi]);
            }
        });

        $this->perbaruiJadwal($denah);
        if ($pelaku) {
            AuditLog::catat('denah.tempatkan', "denah {$denah->id}: siswa {$siswa->id} ke ruang {$ruangId} meja {$meja}.{$posisi}", $pelaku);
        }
    }

    public function lepas(Denah $denah, Siswa $siswa, ?User $pelaku = null): void
    {
        DenahPenempatan::where('denah_id', $denah->id)->where('siswa_id', $siswa->id)->delete();
        $this->perbaruiJadwal($denah);
        if ($pelaku) {
            AuditLog::catat('denah.lepas', "denah {$denah->id}: siswa {$siswa->id}", $pelaku);
        }
    }

    /** Salin ruang dan nomor meja dari denah ke sesi jadwal yang belum selesai. Aman dipanggil berulang. */
    public function terapkan(JadwalUjian $jadwal): int
    {
        if (! $jadwal->denah_id) {
            return 0;
        }

        $tempat = DenahPenempatan::where('denah_id', $jadwal->denah_id)->get()->keyBy('siswa_id');
        $n = 0;

        SesiUjian::where('jadwal_id', $jadwal->id)->where('status', '!=', SesiUjian::SELESAI)->get()->each(function (SesiUjian $s) use ($tempat, &$n) {
            $p = $tempat[$s->siswa_id] ?? null;
            $ruang = $p?->ruang_id;
            $meja = $p?->meja;
            if ($s->ruang_id !== $ruang || $s->no_meja !== $meja) {
                $s->forceFill(['ruang_id' => $ruang, 'no_meja' => $meja])->saveQuietly();
                $n++;
            }
        });

        return $n;
    }

    /** Lepas ruang/meja sesi jadwal (denah dicabut dari jadwal). */
    public function lepasDariJadwal(JadwalUjian $jadwal): void
    {
        SesiUjian::where('jadwal_id', $jadwal->id)->where('status', '!=', SesiUjian::SELESAI)->update(['ruang_id' => null, 'no_meja' => null]);
    }

    /** Peserta jadwal yang belum punya tempat di denahnya. */
    public function tanpaTempat(JadwalUjian $jadwal): int
    {
        if (! $jadwal->denah_id) {
            return 0;
        }

        $ditempatkan = DenahPenempatan::where('denah_id', $jadwal->denah_id)->select('siswa_id');

        return SesiUjian::where('jadwal_id', $jadwal->id)->whereNotIn('siswa_id', $ditempatkan)->count();
    }

    public function perbaruiJadwal(Denah $denah): void
    {
        $denah->jadwal()->where('status', JadwalUjian::AKTIF)->get()->each(fn (JadwalUjian $j) => $this->terapkan($j));
    }

    /**
     * Pilih tingkat berikutnya untuk satu posisi meja: kelompok terbanyak yang belum ada di meja ini;
     * bila tidak ada, kelompok terbanyak mana pun (meja sejenis).
     *
     * @param  array<string,list<int>>  $kelompok
     * @param  list<string>  $dipakai
     */
    private function pilihKelompok(array $kelompok, array $dipakai): ?string
    {
        $tersedia = array_filter($kelompok, fn ($g) => $g !== []);
        if ($tersedia === []) {
            return null;
        }

        $beda = array_diff_key($tersedia, array_flip($dipakai));
        $sumber = $beda !== [] ? $beda : $tersedia;
        uasort($sumber, fn ($a, $b) => count($b) <=> count($a));

        return (string) array_key_first($sumber);
    }
}
