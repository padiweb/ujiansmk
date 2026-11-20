<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Denah;
use App\Models\DenahPenempatan;
use App\Models\DenahRuang;
use App\Models\JadwalUjian;
use App\Models\Kelas;
use App\Models\Periode;
use App\Models\Ruang;
use App\Models\SesiUjian;
use App\Models\Siswa;
use App\Models\TahunAjaran;
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
     * Atur kapasitas ruang pada denah (0 = ruang tidak dipakai). Penempatan di luar kapasitas baru dilepas.
     *
     * @param  array<int,int|null>  $kapasitas  ruang_id => kapasitas
     */
    public function aturRuang(Denah $denah, array $kapasitas, ?User $pelaku = null): void
    {
        DB::transaction(function () use ($denah, $kapasitas) {
            foreach ($kapasitas as $ruangId => $kap) {
                $kap = (int) $kap;
                if ($kap < 1) {
                    DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruangId)->delete();
                    DenahRuang::where('denah_id', $denah->id)->where('ruang_id', $ruangId)->delete();
                    continue;
                }
                if ($ruang = Ruang::find($ruangId)) {
                    $this->selaraskan($denah, $ruang, $kap);
                }
            }
        });

        $this->perbaruiJadwal($denah);
        $this->ikatJadwalUtama($denah);
        if ($pelaku) {
            AuditLog::catat('denah.ruang', "denah {$denah->id}", $pelaku);
        }
    }

    /**
     * Samakan baris ruang pada denah dengan data ruang (kapasitas, campur): siswa per meja, jumlah meja, kelebihan
     * kapasitas dilepas, dan urutan duduk (ruang campur: tingkat selang-seling per meja).
     */
    public function selaraskan(Denah $denah, Ruang $ruang, ?int $kapasitas = null): DenahRuang
    {
        $kap = max(1, $kapasitas ?? (int) $ruang->kapasitas);
        $dr = DenahRuang::firstOrNew(['denah_id' => $denah->id, 'ruang_id' => $ruang->id]);
        $tahun = $this->tahunId($denah);

        $urut = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)->orderBy('meja')->orderBy('posisi')->pluck('siswa_id')->map(fn ($v) => (int) $v)->all();
        if ($ruang->campur) {
            $tingkat = $this->tingkatSiswa($urut, $tahun);
            $urut = $this->selangSeling($urut, $tingkat);
            $per = max(2, min(3, count(array_unique(array_filter($tingkat)))));
        } else {
            $per = 1;
        }
        $urut = array_slice($urut, 0, $kap); // kelebihan kapasitas dilepas

        $dr->fill(['siswa_per_meja' => $per, 'kapasitas' => $kap, 'jumlah_meja' => max(1, (int) ceil($kap / $per))])->save();
        $this->susun($denah, $dr, $urut);

        return $dr;
    }

    /**
     * Masukkan semua siswa aktif yang belum punya ruang dari kelas-kelas terpilih ke ruang ini, sebanyak yang muat.
     * Ruang campur: tiap tingkat maksimal 50% kapasitas. Sisanya tetap belum punya ruang untuk ruang lain.
     *
     * @param  list<int>  $kelasIds
     * @return array{masuk:int,sisa:int,per_kelas:array<int,array{nama:string,masuk:int,sisa:int}>}
     *
     * @throws KesalahanJadwal
     */
    public function masukkanKelas(Denah $denah, Ruang $ruang, array $kelasIds, ?User $pelaku = null): array
    {
        $tahun = $this->tahunId($denah);
        $hasil = ['masuk' => 0, 'sisa' => 0, 'per_kelas' => []];

        DB::transaction(function () use ($denah, $ruang, $kelasIds, $tahun, &$hasil) {
            $dr = $this->selaraskan($denah, $ruang);
            $kap = $dr->kapasitas();

            foreach ($kelasIds as $kelasId) {
                $kelas = Kelas::find($kelasId);
                if (! $kelas || $kelas->tahun_ajaran_id !== $tahun) {
                    continue;
                }

                $calon = DB::table('keanggotaan_kelas as k')->join('siswa as s', 's.id', '=', 'k.siswa_id')
                    ->where('k.kelas_id', $kelas->id)->where('k.status', 'aktif')->where('s.status', 'aktif')
                    ->whereNotIn('s.id', DenahPenempatan::where('denah_id', $denah->id)->select('siswa_id'))
                    ->orderBy('s.nama')->pluck('s.id')->map(fn ($v) => (int) $v)->all();

                $isi = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)->count();
                $boleh = $kap - $isi;
                if ($ruang->campur) {
                    $sudah = $this->komposisiTingkat($denah, $ruang)[$kelas->tingkat] ?? 0;
                    $boleh = min($boleh, $ruang->porsiTingkat($kap) - $sudah);
                }
                $ambil = array_slice($calon, 0, max(0, $boleh));

                foreach ($ambil as $siswaId) {
                    [$meja, $posisi] = $dr->tempatKe($isi++);
                    DenahPenempatan::create(['denah_id' => $denah->id, 'siswa_id' => $siswaId, 'ruang_id' => $ruang->id, 'meja' => $meja, 'posisi' => $posisi]);
                }

                $hasil['masuk'] += count($ambil);
                $hasil['sisa'] += count($calon) - count($ambil);
                $hasil['per_kelas'][$kelas->id] = ['nama' => $kelas->nama, 'masuk' => count($ambil), 'sisa' => count($calon) - count($ambil)];
            }

            $this->selaraskan($denah, $ruang); // ruang campur: susun ulang selang-seling per tingkat
        });

        $this->perbaruiJadwal($denah);
        $this->ikatJadwalUtama($denah);
        if ($pelaku) {
            AuditLog::catat('ruang.masukkan_kelas', "ruang {$ruang->id}: {$hasil['masuk']} siswa dari kelas ".implode(',', $kelasIds), $pelaku);
        }

        return $hasil;
    }


    /**
     * Rencana pengisian ruang otomatis (tidak menyimpan apa pun). Kelas diurutkan tingkat lalu nama dan diisikan ruang demi ruang
     * (urutan ruang menurut nama), dengan prinsip: satu kelas utuh dalam satu ruang bila bisa. Di tiap ruang, kelas pertama yang tersisa
     * menjadi "jangkar"; sisa kursi diisi kelas utuh terbesar yang muat (ruang biasa bisa memuat 3 kelas atau lebih, tingkat boleh campur).
     * Bila tidak ada kelas utuh yang muat, kelas berikutnya dipecah dan sisanya masuk ruang berikutnya. Ruang "Campur tingkat" tetap
     * mematuhi batas 50% per tingkat. Siswa yang tidak mendapat tempat tercatat sebagai sisa.
     *
     * @param  list<int>  $kelasIds
     * @param  bool  $ulang  kosongkan penempatan sekarang lebih dulu (bila tidak: hanya siswa yang belum punya ruang, mengisi kursi yang masih kosong)
     * @param  bool  $pecah  boleh memecah satu kelas ke dua ruang untuk mengisi kursi tersisa
     * @return array{ruang:list<array<string,mixed>>,sisa:list<array<string,mixed>>,ditempatkan:int,sisa_total:int}
     */
    public function rencanaOtomatis(Denah $denah, array $kelasIds, bool $ulang = false, bool $pecah = true): array
    {
        $tahun = $this->tahunId($denah);
        $kelas = Kelas::where('tahun_ajaran_id', $tahun)->whereIn('id', array_map('intval', $kelasIds))->orderBy('tingkat')->orderBy('nama')->get();

        $sudahAda = $ulang ? collect() : DenahPenempatan::where('denah_id', $denah->id)->pluck('siswa_id');
        $antrean = []; // kelas_id => ['kelas' => Kelas, 'siswa' => list<int>]
        $terlihat = [];
        foreach ($kelas as $k) {
            $ids = DB::table('keanggotaan_kelas as a')->join('siswa as s', 's.id', '=', 'a.siswa_id')
                ->where('a.kelas_id', $k->id)->where('a.status', 'aktif')->where('s.status', 'aktif')
                ->whereNotIn('s.id', $sudahAda)->orderBy('s.nama')->pluck('s.id')->map(fn ($v) => (int) $v)
                ->reject(fn ($v) => isset($terlihat[$v]))->values()->all();
            foreach ($ids as $v) {
                $terlihat[$v] = true;
            }
            if ($ids !== []) {
                $antrean[$k->id] = ['kelas' => $k, 'siswa' => $ids];
            }
        }

        $ruangUrut = Ruang::all()->sortBy('nama', SORT_NATURAL | SORT_FLAG_CASE)->values();
        $baris = DenahRuang::where('denah_id', $denah->id)->get()->keyBy('ruang_id');
        $rencana = [];
        $ditempatkan = 0;
        $dipecah = []; // kelas_id yang sudah terbagi ke lebih dari satu ruang

        foreach ($ruangUrut as $ruang) {
            $dr = $baris[$ruang->id] ?? null;
            $kap = $dr ? $dr->kapasitas() : (int) $ruang->kapasitas;
            $awal = $ulang ? 0 : DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)->count();
            $bebas = $kap - $awal;
            $porsi = $ruang->campur ? $ruang->porsiTingkat($kap) : PHP_INT_MAX;
            $pakai = $ulang || ! $ruang->campur ? [] : $this->komposisiTingkat($denah, $ruang);
            $tambah = [];

            while ($bebas > 0) {
                $izin = fn (array $q) => min($bebas, $porsi === PHP_INT_MAX ? $bebas : $porsi - ($pakai[$q['kelas']->tingkat] ?? 0));
                $kandidat = array_filter($antrean, fn ($q) => $q['siswa'] !== [] && $izin($q) > 0);
                if ($kandidat === []) {
                    break;
                }

                $muat = fn (array $q) => count($q['siswa']) <= $izin($q);
                $tidakPernahMuat = fn (array $q) => count($q['siswa']) > min($kap, $porsi);
                $pilih = null;

                if ($tambah === []) {
                    // Jangkar: kelas pertama menurut urutan.
                    foreach ($kandidat as $id => $q) {
                        if ($muat($q) || $pecah || $tidakPernahMuat($q)) {
                            $pilih = $id;
                            break;
                        }
                    }
                } else {
                    // Kelas utuh terbesar yang muat (sama besar: yang lebih dulu menurut urutan).
                    $terbaik = -1;
                    foreach ($kandidat as $id => $q) {
                        if ($muat($q) && count($q['siswa']) > $terbaik) {
                            $terbaik = count($q['siswa']);
                            $pilih = $id;
                        }
                    }
                    if ($pilih === null && $pecah) {
                        $pilih = array_key_first($kandidat); // tak ada yang utuh: pecah kelas berikutnya
                    }
                }

                if ($pilih === null) {
                    break;
                }

                $q = $antrean[$pilih];
                $sebelum = count($q['siswa']);
                $n = min($sebelum, $izin($q));
                $ambil = array_splice($antrean[$pilih]['siswa'], 0, $n);
                $utuh = $n === $sebelum && ! isset($dipecah[$pilih]);
                if ($n < $sebelum) {
                    $dipecah[$pilih] = true;
                }
                $tambah[] = ['kelas_id' => $q['kelas']->id, 'nama' => $q['kelas']->nama, 'tingkat' => $q['kelas']->tingkat, 'jumlah' => $n, 'utuh' => $utuh, 'siswa' => $ambil];
                $pakai[$q['kelas']->tingkat] = ($pakai[$q['kelas']->tingkat] ?? 0) + $n;
                $bebas -= $n;
                $ditempatkan += $n;
            }

            $rencana[] = ['ruang' => $ruang, 'kapasitas' => $kap, 'awal' => $awal, 'tambah' => $tambah, 'terisi' => $awal + array_sum(array_column($tambah, 'jumlah'))];
        }

        $sisa = [];
        foreach ($antrean as $q) {
            if ($q['siswa'] !== []) {
                $sisa[] = ['kelas_id' => $q['kelas']->id, 'nama' => $q['kelas']->nama, 'tingkat' => $q['kelas']->tingkat, 'jumlah' => count($q['siswa'])];
            }
        }

        return ['ruang' => $rencana, 'sisa' => $sisa, 'ditempatkan' => $ditempatkan, 'sisa_total' => array_sum(array_column($sisa, 'jumlah'))];
    }

    /**
     * Terapkan rencana otomatis ke denah (dihitung ulang saat diterapkan, jadi selalu sesuai data terbaru).
     *
     * @param  list<int>  $kelasIds
     * @return array{ditempatkan:int,sisa_total:int}
     *
     * @throws KesalahanJadwal
     */
    public function isiOtomatis(Denah $denah, array $kelasIds, bool $ulang, bool $pecah, ?User $pelaku = null): array
    {
        if ($ulang && SesiUjian::where('status', SesiUjian::BERLANGSUNG)->whereIn('jadwal_id', $denah->jadwal()->where('status', JadwalUjian::AKTIF)->select('id'))->exists()) {
            throw new KesalahanJadwal('Ada ujian yang sedang berlangsung. Penempatan tidak dapat dikosongkan dan diatur ulang sekarang; pilih "hanya siswa yang belum punya ruang" atau tunggu ujian selesai.');
        }

        $rencana = $this->rencanaOtomatis($denah, $kelasIds, $ulang, $pecah);
        if ($rencana['ditempatkan'] === 0 && ! $ulang) {
            throw new KesalahanJadwal('Tidak ada siswa yang bisa ditempatkan: semua siswa pada kelas terpilih sudah punya ruang, atau tidak ada kursi yang tersisa.');
        }

        DB::transaction(function () use ($denah, $rencana, $ulang) {
            if ($ulang) {
                DenahPenempatan::where('denah_id', $denah->id)->delete();
            }
            foreach ($rencana['ruang'] as $p) {
                if ($p['tambah'] === []) {
                    continue;
                }
                $ruang = $p['ruang'];
                $dr = DenahRuang::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)->first() ?? $this->selaraskan($denah, $ruang);
                $isi = $p['awal'];
                foreach ($p['tambah'] as $g) {
                    foreach ($g['siswa'] as $siswaId) {
                        [$meja, $posisi] = $dr->tempatKe($isi++);
                        DenahPenempatan::create(['denah_id' => $denah->id, 'siswa_id' => $siswaId, 'ruang_id' => $ruang->id, 'meja' => $meja, 'posisi' => $posisi]);
                    }
                }
                if ($ruang->campur) {
                    $this->selaraskan($denah, $ruang); // susun selang-seling antar tingkat
                }
            }
        });

        $this->perbaruiJadwal($denah);
        $this->ikatJadwalUtama($denah);
        if ($pelaku) {
            AuditLog::catat('ruang.isi_otomatis', "{$rencana['ditempatkan']} siswa ditempatkan, {$rencana['sisa_total']} sisa".($ulang ? ' (diatur ulang)' : ''), $pelaku);
        }

        return ['ditempatkan' => $rencana['ditempatkan'], 'sisa_total' => $rencana['sisa_total']];
    }

    /** Keluarkan semua siswa satu kelas dari ruang ini. @return int jumlah siswa yang dikeluarkan */
    public function keluarkanKelas(Denah $denah, Ruang $ruang, Kelas $kelas, ?User $pelaku = null): int
    {
        $n = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)
            ->whereIn('siswa_id', DB::table('keanggotaan_kelas')->where('kelas_id', $kelas->id)->select('siswa_id'))->delete();

        $this->selaraskan($denah, $ruang);
        $this->perbaruiJadwal($denah);
        if ($pelaku) {
            AuditLog::catat('ruang.keluarkan_kelas', "ruang {$ruang->id}: kelas {$kelas->id} ({$n} siswa)", $pelaku);
        }

        return $n;
    }

    /** Jumlah siswa per tingkat di ruang ini. @return array<string,int> */
    public function komposisiTingkat(Denah $denah, Ruang $ruang): array
    {
        $ids = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)->pluck('siswa_id')->all();

        return collect($this->tingkatSiswa($ids, $this->tahunId($denah)))->filter()->countBy()->all();
    }

    /** Tahun ajaran yang dipakai menentukan kelas/tingkat siswa: yang aktif, atau tahun semester denah. */
    public function tahunId(Denah $denah): int
    {
        return (int) (TahunAjaran::where('aktif', true)->value('id') ?? $denah->periode()->value('tahun_ajaran_id'));
    }

    /** @param list<int> $siswaIds  @return array<int,string|null> siswa_id => tingkat */
    public function tingkatSiswa(array $siswaIds, int $tahunId): array
    {
        $peta = DB::table('keanggotaan_kelas as k')->join('kelas as kl', 'kl.id', '=', 'k.kelas_id')
            ->whereIn('k.siswa_id', $siswaIds)->where('kl.tahun_ajaran_id', $tahunId)
            ->select('k.siswa_id', 'kl.tingkat')->get()->unique('siswa_id')->pluck('tingkat', 'siswa_id')->all();

        $hasil = [];
        foreach ($siswaIds as $id) {
            $hasil[$id] = $peta[$id] ?? null;
        }

        return $hasil;
    }

    /**
     * Urutan selang-seling antar tingkat (X, XI, X, XI, ...) agar satu meja berisi tingkat berbeda.
     *
     * @param  list<int>  $siswaIds
     * @param  array<int,string|null>  $tingkat
     * @return list<int>
     */
    private function selangSeling(array $siswaIds, array $tingkat): array
    {
        $kelompok = [];
        foreach ($siswaIds as $id) {
            $kelompok[$tingkat[$id] ?? '-'][] = $id;
        }
        uasort($kelompok, fn ($a, $b) => count($b) <=> count($a));

        $hasil = [];
        while ($kelompok !== []) {
            foreach ($kelompok as $k => $g) {
                $hasil[] = array_shift($kelompok[$k]);
                if ($kelompok[$k] === []) {
                    unset($kelompok[$k]);
                }
            }
        }

        return $hasil;
    }

    /** Selaraskan ruang dari barisnya di denah (mempertahankan kapasitas denah itu). */
    private function selaraskanDari(Denah $denah, DenahRuang $dr): void
    {
        $ruang = Ruang::find($dr->ruang_id);
        if ($ruang && $ruang->campur) {
            $this->selaraskan($denah, $ruang, $dr->kapasitas());
        } else {
            $this->rapikan($denah, $dr);
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
                $ruangTujuan = Ruang::find($tujuan->ruang_id);
                if ($ruangTujuan?->campur) {
                    $tingkat = $this->tingkatSiswa([$siswa->id], $this->tahunId($denah))[$siswa->id] ?? null;
                    $sudah = $this->komposisiTingkat($denah, $ruangTujuan)[$tingkat] ?? 0;
                    if ($tingkat !== null && $sudah >= $ruangTujuan->porsiTingkat($tujuan->kapasitas())) {
                        throw new KesalahanJadwal("Porsi tingkat {$tingkat} di ruang ini sudah 50% dari kapasitas. Pilih ruang lain.");
                    }
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
                $this->selaraskanDari($denah, $asal); // tutup celah nomor meja di ruang asal
            }
            if ($tujuan) {
                $this->selaraskanDari($denah, $tujuan);
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
