<?php

namespace App\Services;

use App\Eduweb\KesalahanEduweb;
use App\Eduweb\KlienEduweb;
use App\Models\AuditLog;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\NilaiRapor;
use App\Models\Pengampu;
use App\Models\Periode;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Input nilai rapor oleh guru mapel: hak akses, nilai Ujian otomatis dari kelas yang terkunci, dan penyimpanan. */
class LayananNilaiRapor
{
    /** Komponen yang diisi/ditimpa guru di layar (Ujian dan Harian bisa terisi otomatis). */
    public const KOMPONEN = ['ujian', 'harian', 'tugas_akhir', 'praktik', 'adab'];

    public function __construct(private LayananRapor $rapor, private KlienEduweb $eduweb) {}

    // ── Hak akses ───────────────────────────────────────────────────────────

    /** Admin boleh semua mapel+kelas yang punya pengampu; guru hanya yang diampunya. */
    public function boleh(User $user, Mapel $mapel, Kelas $kelas): bool
    {
        if (! $user->aktif) {
            return false;
        }

        $q = Pengampu::where('mapel_id', $mapel->id)->where('kelas_id', $kelas->id);

        return match ($user->role) {
            'admin' => $q->exists(),
            'guru' => $q->where('guru_id', $user->id)->exists(),
            default => false,
        };
    }

    /**
     * Pasangan mapel + kelas yang boleh diisi pada semester ini, dengan kemajuan pengisian.
     *
     * @return Collection<int,array{mapel:Mapel,kelas:Kelas,guru:?string,siswa:int,terisi:int,final:bool}>
     */
    public function pasangan(User $user, Periode $periode): Collection
    {
        $pengampu = Pengampu::query()->with('mapel', 'kelas', 'guru:id,nama')
            ->whereHas('kelas', fn ($k) => $k->where('tahun_ajaran_id', $periode->tahun_ajaran_id))
            ->when($user->role !== 'admin', fn ($q) => $q->where('guru_id', $user->id))->get();

        $jumlah = DB::table('keanggotaan_kelas as k')->join('siswa as s', 's.id', '=', 'k.siswa_id')
            ->whereIn('k.kelas_id', $pengampu->pluck('kelas_id')->unique())->where('k.status', 'aktif')->where('s.status', 'aktif')
            ->select('k.kelas_id', DB::raw('count(distinct s.id) as n'))->groupBy('k.kelas_id')->pluck('n', 'kelas_id');

        $aktif = $this->rapor->komponenAktif();
        $isi = NilaiRapor::query()->where('periode_id', $periode->id)->whereIn('kelas_id', $pengampu->pluck('kelas_id')->unique())
            ->get()->groupBy(fn ($n) => $n->mapel_id.'-'.$n->kelas_id);

        return $pengampu->unique(fn ($p) => $p->mapel_id.'-'.$p->kelas_id)->map(function ($p) use ($jumlah, $isi, $aktif) {
            $baris = $isi[$p->mapel_id.'-'.$p->kelas_id] ?? collect();

            return [
                'mapel' => $p->mapel, 'kelas' => $p->kelas, 'guru' => $p->guru?->nama,
                'siswa' => (int) ($jumlah[$p->kelas_id] ?? 0),
                'terisi' => $baris->filter(fn ($n) => collect($aktif)->contains(fn ($k) => $n->{$k} !== null))->count(),
                'final' => $baris->isNotEmpty() && $baris->every(fn ($n) => $n->final()),
            ];
        })->sortBy([fn ($a, $b) => strcmp($a['mapel']->nama, $b['mapel']->nama), fn ($a, $b) => strnatcmp($a['kelas']->nama, $b['kelas']->nama)])->values();
    }

    // ── Data tabel ──────────────────────────────────────────────────────────

    /** @return Collection<int,Siswa> siswa aktif kelas ini, urut nama */
    public function siswa(Kelas $kelas): Collection
    {
        return Siswa::query()->where('status', 'aktif')
            ->whereIn('id', DB::table('keanggotaan_kelas')->where('kelas_id', $kelas->id)->where('status', 'aktif')->select('siswa_id'))
            ->orderBy('nama')->get(['id', 'nama', 'nisn']);
    }

    /**
     * Nilai Ujian otomatis: rata-rata skor akhir CBT dari jadwal semester ini untuk mapel ini yang nilainya
     * sudah dikunci untuk kelas ini (dan berasal dari kegiatan bertanda "untuk rapor", atau jadwal tanpa kegiatan).
     *
     * @return array<int,float> siswa_id => nilai
     */
    public function ujianOtomatis(Mapel $mapel, Kelas $kelas, Periode $periode): array
    {
        $rata = DB::table('hasil_ujian as h')
            ->join('sesi_ujian as s', 's.id', '=', 'h.sesi_id')
            ->join('jadwal_ujian as j', 'j.id', '=', 's.jadwal_id')
            ->join('kunci_nilai as kn', fn ($on) => $on->on('kn.jadwal_id', '=', 'j.id')->where('kn.kelas_id', $kelas->id))
            ->leftJoin('kegiatan_ujian as g', 'g.id', '=', 'j.kegiatan_id')
            ->where('j.mapel_id', $mapel->id)->where('j.periode_id', $periode->id)
            ->where(fn ($q) => $q->whereNull('g.id')->orWhere('g.untuk_rapor', true))
            ->whereNotNull('h.skor_akhir')
            ->whereIn('s.siswa_id', DB::table('keanggotaan_kelas')->where('kelas_id', $kelas->id)->select('siswa_id'))
            ->select('s.siswa_id', DB::raw('avg(h.skor_akhir) as rata'))->groupBy('s.siswa_id')->pluck('rata', 'siswa_id');

        return $rata->map(fn ($v) => round((float) $v, 2))->all();
    }

    /**
     * Baris tabel Input Nilai. Ujian bersumber otomatis selalu mengikuti hasil terkunci terbaru; yang diubah guru dipertahankan.
     *
     * @return list<array<string,mixed>>
     */
    public function tabel(Mapel $mapel, Kelas $kelas, Periode $periode): array
    {
        $tersimpan = NilaiRapor::where('mapel_id', $mapel->id)->where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->get()->keyBy('siswa_id');
        $otomatis = $this->ujianOtomatis($mapel, $kelas, $periode);
        $bobot = $this->rapor->bobot();
        $skala = $this->rapor->skala();

        return $this->siswa($kelas)->map(function (Siswa $s) use ($tersimpan, $otomatis, $bobot, $skala) {
            $n = $tersimpan[$s->id] ?? null;
            $nilai = [];
            foreach (self::KOMPONEN as $k) {
                $nilai[$k] = $n?->{$k} !== null ? (float) $n->{$k} : null;
            }
            $auto = $otomatis[$s->id] ?? null;
            if ($auto !== null && ($n === null || $n->ujian_sumber !== 'manual')) {
                $nilai['ujian'] = $auto; // belum diubah guru: ikuti hasil terkunci terbaru
            }
            $hasil = $this->rapor->hitung($nilai, $bobot, $skala);

            return [
                'siswa' => $s, 'nilai' => $nilai, 'catatan' => $n?->catatan_guru, 'auto_ujian' => $auto, 'harian_sumber' => $nilai['harian'] === null ? null : $n?->harian_sumber,
                'ujian_sumber' => $nilai['ujian'] === null ? null : ($auto !== null && abs($nilai['ujian'] - $auto) < 0.005 ? 'otomatis' : ($n?->ujian_sumber ?? null)),
                'final' => $n?->final() ?? false, 'ada' => $n !== null,
            ] + $hasil;
        })->all();
    }

    // ── Simpan ──────────────────────────────────────────────────────────────

    /**
     * Simpan nilai satu kelas. Hasil dihitung ulang di server; nilai yang sudah final tidak disentuh.
     *
     * @param  array<int|string,array<string,mixed>>  $input  siswa_id => [komponen => nilai, catatan => teks]
     * @return array{disimpan:int,dilewati_final:int}
     */
    public function simpan(User $pelaku, Mapel $mapel, Kelas $kelas, Periode $periode, array $input): array
    {
        $bobot = $this->rapor->bobot();
        $skala = $this->rapor->skala();
        $aktif = $this->rapor->komponenAktif($bobot);
        $otomatis = $this->ujianOtomatis($mapel, $kelas, $periode);
        $siswaIds = $this->siswa($kelas)->pluck('id')->all();
        $disimpan = 0;
        $final = 0;

        DB::transaction(function () use ($pelaku, $mapel, $kelas, $periode, $input, $bobot, $skala, $aktif, $otomatis, $siswaIds, &$disimpan, &$final) {
            $ada = NilaiRapor::where('mapel_id', $mapel->id)->where('periode_id', $periode->id)->where('kelas_id', $kelas->id)->lockForUpdate()->get()->keyBy('siswa_id');

            foreach ($input as $siswaId => $baris) {
                $siswaId = (int) $siswaId;
                if (! in_array($siswaId, $siswaIds, true)) {
                    continue;
                }
                $n = $ada[$siswaId] ?? null;
                if ($n?->final()) {
                    $final++;
                    continue;
                }

                $nilai = [];
                foreach (self::KOMPONEN as $k) {
                    $nilai[$k] = in_array($k, $aktif, true) ? $this->angka($baris[$k] ?? null) : ($n?->{$k} !== null ? (float) $n->{$k} : null);
                }

                // Ujian: kosong = ikuti otomatis; sama dengan otomatis = otomatis; selain itu diubah guru.
                $auto = $otomatis[$siswaId] ?? null;
                $sumber = null;
                if ($nilai['ujian'] === null && $auto !== null) {
                    $nilai['ujian'] = $auto;
                }
                if ($nilai['ujian'] !== null) {
                    $sumber = ($auto !== null && abs($nilai['ujian'] - $auto) < 0.005) ? 'otomatis' : 'manual';
                }

                // Harian: sama dengan nilai tersimpan = sumber lama dipertahankan; selain itu diubah guru.
                $lama = $n?->harian !== null ? (float) $n->harian : null;
                $sumberHarian = $nilai['harian'] === null ? null : (($lama !== null && abs($nilai['harian'] - $lama) < 0.005) ? $n->harian_sumber : 'manual');

                $hasil = $this->rapor->hitung($nilai, $bobot, $skala);
                $catatan = isset($baris['catatan']) ? mb_substr(trim((string) $baris['catatan']), 0, 500) : $n?->catatan_guru;

                $data = $nilai + [
                    'ujian_sumber' => $sumber, 'harian_sumber' => $sumberHarian, 'catatan_guru' => $catatan ?: null, 'guru_id' => $pelaku->id,
                    'np' => $hasil['np'], 'nk' => $hasil['nk'], 'na' => $hasil['na'], 'predikat' => $hasil['predikat'], 'status' => 'draf',
                ];
                if ($n) {
                    $n->update($data);
                } else {
                    NilaiRapor::create($data + ['siswa_id' => $siswaId, 'mapel_id' => $mapel->id, 'periode_id' => $periode->id, 'kelas_id' => $kelas->id]);
                }
                $disimpan++;
            }
        });

        AuditLog::catat('nilai_rapor.simpan', "mapel {$mapel->id} kelas {$kelas->id} periode {$periode->id}: {$disimpan} siswa", $pelaku);

        return ['disimpan' => $disimpan, 'dilewati_final' => $final];
    }

    /** Kembalikan Ujian ke nilai otomatis untuk semua siswa kelas ini (yang belum final). */
    public function ambilUlangUjian(User $pelaku, Mapel $mapel, Kelas $kelas, Periode $periode): int
    {
        $otomatis = $this->ujianOtomatis($mapel, $kelas, $periode);
        $bobot = $this->rapor->bobot();
        $skala = $this->rapor->skala();
        $n = 0;

        foreach (NilaiRapor::where('mapel_id', $mapel->id)->where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->where('status', 'draf')->get() as $baris) {
            if (! isset($otomatis[$baris->siswa_id])) {
                continue;
            }
            $nilai = collect(self::KOMPONEN)->mapWithKeys(fn ($k) => [$k => $baris->{$k} !== null ? (float) $baris->{$k} : null])->all();
            $nilai['ujian'] = $otomatis[$baris->siswa_id];
            $hasil = $this->rapor->hitung($nilai, $bobot, $skala);
            $baris->update(['ujian' => $nilai['ujian'], 'ujian_sumber' => 'otomatis'] + $hasil);
            $n++;
        }
        AuditLog::catat('nilai_rapor.ambil_ulang_ujian', "mapel {$mapel->id} kelas {$kelas->id}: {$n} siswa", $pelaku);

        return $n;
    }

    /**
     * Rata-rata nilai tugas dan kuis siswa di EduWeb (0-100) untuk mapel dan kelas ini pada rentang semester.
     * Kelas/mapel yang belum terhubung ke EduWeb menghasilkan daftar kosong.
     *
     * @return array<int,float> siswa_id => nilai
     *
     * @throws KesalahanEduweb
     */
    public function harianEduweb(Mapel $mapel, Kelas $kelas, Periode $periode): array
    {
        if (! $mapel->eduweb_id || ! $kelas->eduweb_id) {
            return [];
        }

        $query = ['classroom_id' => $kelas->eduweb_id, 'subject_id' => $mapel->eduweb_id];
        if ($periode->mulai && $periode->selesai) {
            $query += ['from' => $periode->mulai->format('Y-m-d'), 'to' => $periode->selesai->format('Y-m-d')];
        }

        try {
            $jawab = $this->eduweb->get('assignment-scores', $query);
        } catch (KesalahanEduweb $e) {
            if ($e->status === 403) {
                throw new KesalahanEduweb('Kunci EduWeb belum diizinkan membaca nilai tugas. Admin EduWeb menyalakannya di menu Integrasi Ujian ("Akses nilai tugas").', 'tanpa_lingkup', 403);
            }
            throw $e;
        }

        $peta = Siswa::whereIn('id', $this->siswa($kelas)->pluck('id'))->whereNotNull('eduweb_id')->pluck('id', 'eduweb_id');
        $hasil = [];
        foreach ($jawab['data'] ?? [] as $baris) {
            $id = $peta[(int) ($baris['student_id'] ?? 0)] ?? null;
            if ($id !== null && isset($baris['average']) && is_numeric($baris['average'])) {
                $hasil[$id] = round(max(0.0, min(100.0, (float) $baris['average'])), 2);
            }
        }

        return $hasil;
    }

    /**
     * Isi Harian semua siswa kelas ini dari EduWeb (menimpa isian lama yang belum final).
     *
     * @return int jumlah siswa yang terisi
     *
     * @throws KesalahanEduweb
     */
    public function ambilUlangHarian(User $pelaku, Mapel $mapel, Kelas $kelas, Periode $periode): int
    {
        $harian = $this->harianEduweb($mapel, $kelas, $periode);
        $bobot = $this->rapor->bobot();
        $skala = $this->rapor->skala();
        $otomatis = $this->ujianOtomatis($mapel, $kelas, $periode);
        $n = 0;

        DB::transaction(function () use ($pelaku, $mapel, $kelas, $periode, $harian, $bobot, $skala, $otomatis, &$n) {
            $ada = NilaiRapor::where('mapel_id', $mapel->id)->where('periode_id', $periode->id)->where('kelas_id', $kelas->id)->lockForUpdate()->get()->keyBy('siswa_id');

            foreach ($harian as $siswaId => $nilaiHarian) {
                $baris = $ada[$siswaId] ?? null;
                if ($baris?->final()) {
                    continue;
                }
                $nilai = collect(self::KOMPONEN)->mapWithKeys(fn ($k) => [$k => $baris?->{$k} !== null ? (float) $baris->{$k} : null])->all();
                $nilai['harian'] = $nilaiHarian;
                if ($nilai['ujian'] === null && isset($otomatis[$siswaId])) {
                    $nilai['ujian'] = $otomatis[$siswaId];
                }
                $hasil = $this->rapor->hitung($nilai, $bobot, $skala);
                $data = $nilai + ['harian_sumber' => 'otomatis', 'guru_id' => $pelaku->id, 'status' => 'draf'] + $hasil;
                if ($baris) {
                    $baris->update($data);
                } else {
                    NilaiRapor::create($data + ['ujian_sumber' => isset($otomatis[$siswaId]) ? 'otomatis' : null, 'siswa_id' => $siswaId, 'mapel_id' => $mapel->id, 'periode_id' => $periode->id, 'kelas_id' => $kelas->id]);
                }
                $n++;
            }
        });
        AuditLog::catat('nilai_rapor.ambil_ulang_harian', "mapel {$mapel->id} kelas {$kelas->id}: {$n} siswa", $pelaku);

        return $n;
    }

    private function angka(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = str_replace(',', '.', trim((string) $v));

        return is_numeric($v) ? round(max(0.0, min(100.0, (float) $v)), 2) : null;
    }
}
