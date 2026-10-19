<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Denah;
use App\Models\DenahPenempatan;
use App\Models\DenahRuang;
use App\Models\JadwalUjian;
use App\Models\Periode;
use App\Models\Ruang;
use App\Models\SesiUjian;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Denah ruang ujian: pembagian otomatis campuran tingkat, perubahan manual, dan penerapan ke sesi jadwal. */
class LayananDenah
{
    /**
     * Bagi siswa dari kelas-kelas terpilih ke ruang-ruang denah. Bawaan (seperti aplikasi lama): siswa diurutkan per kelas
     * lalu nama, mengisi ruang satu per satu sampai kapasitasnya, satu siswa per meja. Dengan $campur, siswa diacak per
     * tingkat dan tiap meja (2-3 siswa) diisi dari tingkat berbeda agar tidak saling menyontek. Penempatan lama diganti.
     *
     * @param  list<int>  $kelasIds
     * @return array{ditempatkan:int,tanpa_tempat:int,meja_sejenis:int,kapasitas:int}
     *
     * @throws KesalahanJadwal
     */
    public function bagi(Denah $denah, array $kelasIds, ?User $pelaku = null, ?int $benih = null, bool $campur = false): array
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
            ->orderBy('kl.nama')->orderBy('s.nama')
            ->select('s.id as siswa_id', 'kl.tingkat')->get()->unique('siswa_id');

        if ($baris->isEmpty()) {
            throw new KesalahanJadwal('Tidak ada siswa aktif di kelas yang dipilih.');
        }

        // Bentuk meja menyesuaikan mode: 1 siswa per meja (bawaan) atau 2-3 siswa campur tingkat.
        foreach ($ruang as $r) {
            $kap = $r->kapasitas();
            $per = $campur ? max(2, min(3, (int) $r->siswa_per_meja)) : 1;
            $r->forceFill(['siswa_per_meja' => $per, 'kapasitas' => $kap, 'jumlah_meja' => max(1, (int) ceil($kap / $per))])->save();
        }
        $ruang = $denah->ruang()->get();

        $hasil = [];
        $ditempatkan = 0;

        if ($campur) {
            if ($benih !== null) {
                mt_srand($benih);
            }
            /** @var array<string,list<int>> $kelompok tingkat => id siswa (teracak) */
            $kelompok = $baris->groupBy('tingkat')->map(fn ($g) => $g->pluck('siswa_id')->shuffle()->values()->all())->all();

            foreach ($ruang as $r) {
                $kap = $r->kapasitas();
                $isi = 0;
                for ($meja = 1; $meja <= $r->jumlah_meja && $isi < $kap; $meja++) {
                    $dipakai = [];
                    for ($posisi = 1; $posisi <= $r->siswa_per_meja && $isi < $kap; $posisi++) {
                        $pilih = $this->pilihKelompok($kelompok, $dipakai);
                        if ($pilih === null) {
                            break 3; // semua siswa sudah mendapat tempat
                        }
                        $dipakai[] = $pilih;
                        $hasil[] = ['denah_id' => $denah->id, 'siswa_id' => array_shift($kelompok[$pilih]), 'ruang_id' => $r->ruang_id, 'meja' => $meja, 'posisi' => $posisi];
                        $ditempatkan++;
                        $isi++;
                    }
                }
            }
            $sisa = collect($kelompok)->sum(fn ($g) => count($g));
        } else {
            $antrean = $baris->pluck('siswa_id')->all();
            foreach ($ruang as $r) {
                for ($i = 0; $i < $r->kapasitas() && $antrean !== []; $i++) {
                    [$meja, $posisi] = $r->tempatKe($i);
                    $hasil[] = ['denah_id' => $denah->id, 'siswa_id' => array_shift($antrean), 'ruang_id' => $r->ruang_id, 'meja' => $meja, 'posisi' => $posisi];
                    $ditempatkan++;
                }
            }
            $sisa = count($antrean);
        }

        $tingkatSiswa = $baris->pluck('tingkat', 'siswa_id');
        $sejenis = $campur
            ? collect($hasil)->groupBy(fn ($h) => $h['ruang_id'].'-'.$h['meja'])
                ->filter(fn ($meja) => $meja->count() > 1 && $meja->pluck('siswa_id')->map(fn ($id) => $tingkatSiswa[$id])->unique()->count() < $meja->count())->count()
            : 0;

        DB::transaction(function () use ($denah, $hasil) {
            DenahPenempatan::where('denah_id', $denah->id)->delete();
            foreach (array_chunk($hasil, 500) as $potong) {
                DenahPenempatan::insert($potong);
            }
        });

        $this->perbaruiJadwal($denah);
        if ($pelaku) {
            AuditLog::catat('denah.bagi', "denah {$denah->id}: {$ditempatkan} ditempatkan, {$sisa} tanpa tempat".($campur ? ' (campur tingkat)' : ''), $pelaku);
        }

        return ['ditempatkan' => $ditempatkan, 'tanpa_tempat' => $sisa, 'meja_sejenis' => $sejenis, 'kapasitas' => $ruang->sum(fn (DenahRuang $r) => $r->kapasitas())];
    }

    /**
     * Atur kapasitas ruang pada denah (null/0 = ruang tidak dipakai). Penempatan di luar kapasitas baru dilepas.
     *
     * @param  array<int,int|null>  $kapasitas  ruang_id => kapasitas
     */
    public function aturRuang(Denah $denah, array $kapasitas, ?User $pelaku = null): void
    {
        DB::transaction(function () use ($denah, $kapasitas) {
            foreach ($kapasitas as $ruangId => $kap) {
                $kap = (int) $kap;
                $r = DenahRuang::where('denah_id', $denah->id)->where('ruang_id', $ruangId)->first();

                if ($kap < 1) {
                    if ($r) {
                        DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruangId)->delete();
                        $r->delete();
                    }
                    continue;
                }

                $per = $r ? max(1, (int) $r->siswa_per_meja) : 1;
                $r = DenahRuang::updateOrCreate(['denah_id' => $denah->id, 'ruang_id' => $ruangId], [
                    'kapasitas' => $kap, 'siswa_per_meja' => $per, 'jumlah_meja' => max(1, (int) ceil($kap / $per)),
                ]);
                $this->rapikan($denah, $r);
                $urutan = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruangId)->orderBy('meja')->orderBy('posisi')->pluck('id');
                if ($urutan->count() > $kap) {
                    DenahPenempatan::whereIn('id', $urutan->slice($kap)->all())->delete(); // kelebihan kapasitas dilepas
                }
            }
        });

        $this->perbaruiJadwal($denah);
        $this->ikatJadwalUtama($denah);
        if ($pelaku) {
            AuditLog::catat('denah.ruang', "denah {$denah->id}", $pelaku);
        }
    }

    /** Susun ulang nomor meja/posisi ruang ini berurutan tanpa celah, mengikuti urutan sekarang. */
    public function rapikan(Denah $denah, DenahRuang $r): void
    {
        $ids = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $r->ruang_id)->orderBy('meja')->orderBy('posisi')->pluck('siswa_id')->map(fn ($v) => (int) $v)->all();
        $this->susun($denah, $r, $ids);
    }

    /**
     * Terapkan urutan baru (hasil seret-lepas) pada satu ruang; nomor meja mengikuti urutan.
     *
     * @param  list<int>  $siswaIds
     */
    public function urutkan(Denah $denah, DenahRuang $r, array $siswaIds, ?User $pelaku = null): void
    {
        $ada = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $r->ruang_id)->orderBy('meja')->orderBy('posisi')->pluck('siswa_id')->map(fn ($v) => (int) $v)->all();
        $urut = array_values(array_unique(array_filter(array_map('intval', $siswaIds), fn ($id) => in_array($id, $ada, true))));
        $urut = array_merge($urut, array_values(array_diff($ada, $urut))); // yang tidak disebut tetap di ruang, di urutan akhir

        $this->susun($denah, $r, $urut);
        $this->perbaruiJadwal($denah);
        if ($pelaku) {
            AuditLog::catat('denah.urutan', "denah {$denah->id} ruang {$r->ruang_id}", $pelaku);
        }
    }

    /** Urutkan ruang menurut kelas lalu nama (seperti urutan awal di aplikasi lama). */
    public function urutkanPerKelas(Denah $denah, DenahRuang $r, ?User $pelaku = null): void
    {
        $kelas = DB::table('keanggotaan_kelas as k')->join('kelas as kl', 'kl.id', '=', 'k.kelas_id')
            ->where('kl.tahun_ajaran_id', $denah->periode()->value('tahun_ajaran_id'))->select('k.siswa_id', 'kl.nama')->get()->unique('siswa_id')->pluck('nama', 'siswa_id');
        $ids = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $r->ruang_id)->with('siswa:id,nama')->get()
            ->sortBy(fn ($p) => [$kelas[$p->siswa_id] ?? '', $p->siswa->nama])->pluck('siswa_id')->map(fn ($v) => (int) $v)->all();

        $this->susun($denah, $r, $ids);
        $this->perbaruiJadwal($denah);
        if ($pelaku) {
            AuditLog::catat('denah.urut_kelas', "denah {$denah->id} ruang {$r->ruang_id}", $pelaku);
        }
    }

    /**
     * Pindahkan siswa ke ruang lain (di akhir daftar) atau keluarkan dari denah ($ruangId null).
     *
     * @throws KesalahanJadwal
     */
    public function pindah(Denah $denah, Siswa $siswa, ?int $ruangId, ?User $pelaku = null): void
    {
        $tujuan = null;
        if ($ruangId !== null) {
            $tujuan = DenahRuang::where('denah_id', $denah->id)->where('ruang_id', $ruangId)->first()
                ?? throw new KesalahanJadwal('Ruang itu bukan bagian dari denah ini.');
        }

        DB::transaction(function () use ($denah, $siswa, $tujuan) {
            $lama = DenahPenempatan::where('denah_id', $denah->id)->where('siswa_id', $siswa->id)->lockForUpdate()->first();
            $asal = $lama ? DenahRuang::where('denah_id', $denah->id)->where('ruang_id', $lama->ruang_id)->first() : null;

            if ($tujuan && (! $lama || $lama->ruang_id !== $tujuan->ruang_id)) {
                $isi = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $tujuan->ruang_id)->count();
                if ($isi >= $tujuan->kapasitas()) {
                    throw new KesalahanJadwal('Ruang tujuan sudah penuh ('.$tujuan->kapasitas().' siswa). Naikkan kapasitasnya atau pilih ruang lain.');
                }
            }

            $lama?->delete();
            if ($tujuan && (! $lama || $lama->ruang_id !== $tujuan->ruang_id)) {
                $isi = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $tujuan->ruang_id)->count();
                [$meja, $posisi] = $tujuan->tempatKe($isi);
                DenahPenempatan::create(['denah_id' => $denah->id, 'siswa_id' => $siswa->id, 'ruang_id' => $tujuan->ruang_id, 'meja' => $meja, 'posisi' => $posisi]);
            } elseif ($tujuan && $lama) {
                DenahPenempatan::create($lama->only(['denah_id', 'siswa_id', 'ruang_id', 'meja', 'posisi'])); // ruang sama: tidak berubah
            }
            if ($asal) {
                $this->rapikan($denah, $asal); // tutup celah nomor meja di ruang asal
            }
        });

        $this->perbaruiJadwal($denah);
        if ($pelaku) {
            AuditLog::catat('denah.pindah', "denah {$denah->id}: siswa {$siswa->id} ke ".($ruangId ?? 'luar denah'), $pelaku);
        }
    }

    /**
     * Denah utama: satu penempatan tetap (siswa -> ruang -> nomor meja) yang dipakai semua jadwal, seperti aplikasi lama.
     * Dibuat otomatis; setiap ruang selalu punya barisnya dengan kapasitas dari data ruang.
     *
     * @throws KesalahanJadwal
     */
    public function utama(?User $pembuat = null): Denah
    {
        $denah = Denah::where('utama', true)->first();
        if (! $denah) {
            $periode = Periode::where('aktif', true)->value('id') ?? Periode::orderByDesc('id')->value('id')
                ?? throw new KesalahanJadwal('Isi data semester dulu di Master Data.');
            $denah = Denah::create(['nama' => 'Denah Utama', 'periode_id' => $periode, 'utama' => true, 'dibuat_oleh' => $pembuat?->id]);
        }

        $sudah = $denah->ruang()->pluck('ruang_id')->all();
        foreach (Ruang::whereNotIn('id', $sudah)->get() as $r) {
            DenahRuang::create(['denah_id' => $denah->id, 'ruang_id' => $r->id, 'siswa_per_meja' => 1, 'kapasitas' => $r->kapasitas, 'jumlah_meja' => max(1, (int) $r->kapasitas)]);
        }

        return $denah;
    }

    /** Jadwal yang belum punya denah (belum selesai) memakai denah utama. */
    public function ikatJadwalUtama(Denah $denah): void
    {
        if (! $denah->utama) {
            return;
        }

        JadwalUjian::whereNull('denah_id')->where('status', '!=', JadwalUjian::SELESAI)->get()->each(function (JadwalUjian $j) use ($denah) {
            $j->update(['denah_id' => $denah->id]);
            $this->terapkan($j);
        });
    }

    /** @param list<int> $urut id siswa lengkap sesuai urutan baru */
    private function susun(Denah $denah, DenahRuang $r, array $urut): void
    {
        DB::transaction(function () use ($denah, $r, $urut) {
            DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $r->ruang_id)->delete();
            foreach ($urut as $i => $siswaId) {
                [$meja, $posisi] = $r->tempatKe($i);
                DenahPenempatan::create(['denah_id' => $denah->id, 'siswa_id' => $siswaId, 'ruang_id' => $r->ruang_id, 'meja' => $meja, 'posisi' => $posisi]);
            }
        });
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
