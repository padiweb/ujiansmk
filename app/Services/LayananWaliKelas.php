<?php

namespace App\Services;

use App\Eduweb\KesalahanEduweb;
use App\Eduweb\KlienEduweb;
use App\Models\AuditLog;
use App\Models\Kelas;
use App\Models\NilaiRapor;
use App\Models\Pengampu;
use App\Models\Periode;
use App\Models\RaporSiswa;
use App\Models\Siswa;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Menu Wali Kelas: kelengkapan nilai, absensi, catatan, keputusan, serta finalisasi rapor satu kelas. */
class LayananWaliKelas
{
    public const KEPUTUSAN = ['naik' => 'Naik kelas', 'tinggal' => 'Tinggal kelas', 'lulus' => 'Lulus'];

    public function __construct(private LayananRapor $rapor, private LayananNilaiRapor $nilai, private KlienEduweb $eduweb) {}

    // ── Hak akses ───────────────────────────────────────────────────────────

    /** Admin boleh semua kelas; guru hanya kelas yang diwalikannya. */
    public function boleh(User $user, Kelas $kelas): bool
    {
        if (! $user->aktif) {
            return false;
        }

        return match ($user->role) {
            'admin' => true,
            'guru' => $kelas->wali_kelas_id === $user->id,
            default => false,
        };
    }

    /** @return Collection<int,Kelas> kelas pada tahun ajaran semester ini yang boleh dikelola */
    public function kelas(User $user, Periode $periode): Collection
    {
        if (! $periode->rapor_di_sini) {
            return collect();
        }

        return Kelas::query()->with('waliKelas:id,nama')->where('tahun_ajaran_id', $periode->tahun_ajaran_id)
            ->when($user->role !== 'admin', fn ($q) => $q->where('wali_kelas_id', $user->id))
            ->orderBy('nama')->get()
            ->filter(fn (Kelas $k) => $this->boleh($user, $k))->values();
    }

    // ── Ringkasan ───────────────────────────────────────────────────────────

    /**
     * Kelengkapan nilai tiap mata pelajaran yang diampu di kelas ini.
     *
     * @return list<array{mapel:\App\Models\Mapel,guru:?string,siswa:int,lengkap:int,final:bool}>
     */
    public function kelengkapan(Kelas $kelas, Periode $periode): array
    {
        $aktif = $this->rapor->komponenAktif();
        $jumlahSiswa = $this->nilai->siswa($kelas)->count();
        $nilai = NilaiRapor::where('kelas_id', $kelas->id)->where('periode_id', $periode->id)
            ->whereIn('siswa_id', $this->nilai->siswa($kelas)->pluck('id'))->get()->groupBy('mapel_id');

        return Pengampu::with('mapel', 'guru:id,nama')->where('kelas_id', $kelas->id)->get()
            ->unique('mapel_id')->sortBy(fn ($p) => $p->mapel->nama)->map(function ($p) use ($nilai, $aktif, $jumlahSiswa) {
                $baris = $nilai[$p->mapel_id] ?? collect();
                $lengkap = $baris->filter(fn ($n) => collect($aktif)->every(fn ($k) => $n->{$k} !== null))->count();

                return [
                    'mapel' => $p->mapel, 'guru' => $p->guru?->nama, 'siswa' => $jumlahSiswa, 'lengkap' => $lengkap,
                    'final' => $baris->isNotEmpty() && $baris->every(fn ($n) => $n->final()),
                ];
            })->values()->all();
    }

    /**
     * Baris tabel wali kelas: data rapor tiap siswa dan ringkasan nilainya.
     *
     * @return list<array<string,mixed>>
     */
    public function baris(Kelas $kelas, Periode $periode): array
    {
        $siswa = $this->nilai->siswa($kelas);
        $rapor = RaporSiswa::where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->get()->keyBy('siswa_id');
        $nilai = NilaiRapor::where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->get()->groupBy('siswa_id');
        $jumlahMapel = Pengampu::where('kelas_id', $kelas->id)->distinct()->count('mapel_id');
        $aktif = $this->rapor->komponenAktif();

        return $siswa->map(function (Siswa $s) use ($rapor, $nilai, $jumlahMapel, $aktif) {
            $n = $nilai[$s->id] ?? collect();
            $lengkap = $n->filter(fn ($x) => collect($aktif)->every(fn ($k) => $x->{$k} !== null))->count();
            $r = $rapor[$s->id] ?? null;

            return [
                'siswa' => $s, 'rapor' => $r, 'mapel_lengkap' => $lengkap, 'mapel_total' => $jumlahMapel,
                'rata' => $n->isEmpty() ? null : round($n->avg('na'), 1),
                'final' => $r?->status === 'final',
            ];
        })->all();
    }

    /** Jumlah siswa kelas ini yang sudah dinaikkan/ditinggalkan/diluluskan (di EduWeb) padahal rapornya belum final. */
    public function sudahPindah(Kelas $kelas, Periode $periode): int
    {
        if ($this->final($kelas, $periode)) {
            return 0;
        }

        return DB::table('keanggotaan_kelas')->where('kelas_id', $kelas->id)->whereIn('status', ['naik', 'tinggal', 'lulus'])->count();
    }

    public function final(Kelas $kelas, Periode $periode): bool
    {
        $rapor = RaporSiswa::where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->pluck('status');

        return $rapor->isNotEmpty() && $rapor->every(fn ($s) => $s === 'final');
    }

    // ── Simpan ──────────────────────────────────────────────────────────────

    /**
     * Simpan absensi, catatan, dan keputusan. Rapor yang sudah final tidak disentuh.
     *
     * @param  array<int|string,array<string,mixed>>  $input  siswa_id => [sakit, izin, alpa, catatan, keputusan]
     * @return array{disimpan:int,dilewati_final:int}
     */
    public function simpan(User $pelaku, Kelas $kelas, Periode $periode, array $input): array
    {
        $ids = $this->nilai->siswa($kelas)->pluck('id')->all();
        $disimpan = 0;
        $final = 0;

        DB::transaction(function () use ($kelas, $periode, $input, $ids, &$disimpan, &$final) {
            $ada = RaporSiswa::where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->lockForUpdate()->get()->keyBy('siswa_id');

            foreach ($input as $siswaId => $baris) {
                $siswaId = (int) $siswaId;
                if (! in_array($siswaId, $ids, true)) {
                    continue;
                }
                $r = $ada[$siswaId] ?? null;
                if ($r?->status === 'final') {
                    $final++;
                    continue;
                }

                $hari = [];
                foreach (['sakit', 'izin', 'alpa'] as $k) {
                    $hari[$k] = $this->hari($baris[$k] ?? null);
                }
                $berubah = $r === null ? array_sum($hari) > 0 : collect($hari)->contains(fn ($v, $k) => $v !== (int) $r->{$k});
                $keputusan = isset($baris['keputusan']) && array_key_exists($baris['keputusan'], self::KEPUTUSAN) ? $baris['keputusan'] : null;
                $catatan = isset($baris['catatan']) ? mb_substr(trim((string) $baris['catatan']), 0, 500) : $r?->catatan_wali;

                $data = $hari + [
                    'sumber_absensi' => $berubah ? 'manual' : $r?->sumber_absensi,
                    'catatan_wali' => $catatan ?: null, 'keputusan' => $keputusan,
                ];
                if ($r) {
                    $r->update($data);
                } else {
                    RaporSiswa::create($data + ['siswa_id' => $siswaId, 'periode_id' => $periode->id, 'kelas_id' => $kelas->id, 'kelas_nama' => $kelas->nama, 'status' => 'draf']);
                }
                $disimpan++;
            }
        });

        AuditLog::catat('wali_kelas.simpan', "kelas {$kelas->id} periode {$periode->id}: {$disimpan} siswa", $pelaku);

        return ['disimpan' => $disimpan, 'dilewati_final' => $final];
    }

    /**
     * Isi sakit/izin/alpa dari absensi EduWeb pada rentang semester (menimpa isian yang belum final).
     *
     * @return int jumlah siswa yang terisi
     *
     * @throws KesalahanEduweb
     * @throws DomainException
     */
    public function ambilAbsensi(User $pelaku, Kelas $kelas, Periode $periode): int
    {
        if (! $kelas->eduweb_id) {
            throw new DomainException('Kelas ini belum terhubung ke EduWeb.');
        }
        if (! $periode->mulai || ! $periode->selesai) {
            throw new DomainException('Rentang tanggal semester belum diatur. Admin mengisinya di Pengaturan Rapor.');
        }

        $jawab = $this->eduweb->get('attendance-summary', [
            'classroom_id' => $kelas->eduweb_id,
            'from' => $periode->mulai->format('Y-m-d'), 'to' => $periode->selesai->format('Y-m-d'),
        ]);

        $peta = Siswa::whereIn('id', $this->nilai->siswa($kelas)->pluck('id'))->whereNotNull('eduweb_id')->pluck('id', 'eduweb_id');
        $n = 0;

        DB::transaction(function () use ($jawab, $peta, $kelas, $periode, &$n) {
            $ada = RaporSiswa::where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->lockForUpdate()->get()->keyBy('siswa_id');

            foreach ($jawab['data'] ?? [] as $baris) {
                $id = $peta[(int) ($baris['student_id'] ?? 0)] ?? null;
                if ($id === null || ($ada[$id] ?? null)?->status === 'final') {
                    continue;
                }
                $data = [
                    'sakit' => $this->hari($baris['sakit'] ?? 0), 'izin' => $this->hari($baris['izin'] ?? 0), 'alpa' => $this->hari($baris['alpa'] ?? 0),
                    'sumber_absensi' => 'eduweb',
                ];
                if ($r = $ada[$id] ?? null) {
                    $r->update($data);
                } else {
                    RaporSiswa::create($data + ['siswa_id' => $id, 'periode_id' => $periode->id, 'kelas_id' => $kelas->id, 'kelas_nama' => $kelas->nama, 'status' => 'draf']);
                }
                $n++;
            }
        });

        AuditLog::catat('wali_kelas.absensi_eduweb', "kelas {$kelas->id} periode {$periode->id}: {$n} siswa", $pelaku);

        return $n;
    }

    /**
     * Isi nama dan nilai ekskul dari EduWeb untuk semester ini (menimpa isian yang belum final).
     *
     * @return int jumlah siswa yang punya nilai ekskul
     *
     * @throws KesalahanEduweb
     * @throws DomainException
     */
    public function ambilEkskul(User $pelaku, Kelas $kelas, Periode $periode): int
    {
        if (! $kelas->eduweb_id) {
            throw new DomainException('Kelas ini belum terhubung ke EduWeb.');
        }

        $jawab = $this->eduweb->get('extracurricular-grades', [
            'classroom_id' => $kelas->eduweb_id,
            'year_name' => $periode->tahunAjaran->nama,
            'semester' => $periode->semester === 'genap' ? 2 : 1,
        ]);

        $peta = Siswa::whereIn('id', $this->nilai->siswa($kelas)->pluck('id'))->whereNotNull('eduweb_id')->pluck('id', 'eduweb_id');
        $perSiswa = [];
        foreach ($jawab['data'] ?? [] as $baris) {
            $id = $peta[(int) ($baris['student_id'] ?? 0)] ?? null;
            if ($id === null || trim((string) ($baris['extracurricular'] ?? '')) === '') {
                continue;
            }
            $perSiswa[$id][] = [
                'nama' => mb_substr(trim((string) ($baris['extracurricular'] ?? '')), 0, 100),
                'nilai' => is_numeric($baris['score'] ?? null) ? (int) $baris['score'] : null,
                'predikat' => mb_substr(trim((string) ($baris['predicate'] ?? '')), 0, 10),
            ];
        }

        DB::transaction(function () use ($peta, $perSiswa, $kelas, $periode) {
            $ada = RaporSiswa::where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->lockForUpdate()->get()->keyBy('siswa_id');

            foreach ($peta as $id) {
                if (($ada[$id] ?? null)?->status === 'final') {
                    continue;
                }
                $data = ['ekskul' => $perSiswa[$id] ?? []];
                if ($r = $ada[$id] ?? null) {
                    $r->update($data);
                } else {
                    RaporSiswa::create($data + ['siswa_id' => $id, 'periode_id' => $periode->id, 'kelas_id' => $kelas->id, 'kelas_nama' => $kelas->nama, 'status' => 'draf']);
                }
            }
        });

        AuditLog::catat('wali_kelas.ekskul_eduweb', "kelas {$kelas->id} periode {$periode->id}: ".count($perSiswa).' siswa', $pelaku);

        return count($perSiswa);
    }

    // ── Finalisasi ──────────────────────────────────────────────────────────

    /** @return list<string> alasan rapor kelas ini belum bisa difinalkan (kosong = siap) */
    public function hambatanFinal(Kelas $kelas, Periode $periode): array
    {
        $hambatan = [];
        $siswa = $this->nilai->siswa($kelas);
        if ($siswa->isEmpty()) {
            return ['Kelas ini belum memiliki siswa aktif.'];
        }

        $mapel = $this->kelengkapan($kelas, $periode);
        if ($mapel === []) {
            $hambatan[] = 'Belum ada mata pelajaran yang diampu di kelas ini.';
        }
        foreach ($mapel as $m) {
            if ($m['lengkap'] < $m['siswa']) {
                $hambatan[] = "{$m['mapel']->nama}: nilai {$m['lengkap']} dari {$m['siswa']} siswa lengkap".($m['guru'] ? " (guru: {$m['guru']})" : '').'.';
            }
        }

        if ($periode->semester === 'genap') {
            $belum = RaporSiswa::where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->whereNotNull('keputusan')->whereIn('siswa_id', $siswa->pluck('id'))->count();
            if ($belum < $siswa->count()) {
                $hambatan[] = 'Keputusan naik/tinggal kelas belum diisi untuk '.($siswa->count() - $belum).' siswa.';
            }
        }

        return $hambatan;
    }

    /** Bekukan nilai dan data rapor satu kelas, lengkap dengan snapshot pengaturan saat ini. */
    public function finalkan(User $pelaku, Kelas $kelas, Periode $periode): void
    {
        $hambatan = $this->hambatanFinal($kelas, $periode);
        if ($hambatan !== []) {
            throw new DomainException('Rapor belum bisa difinalkan. '.implode(' ', $hambatan));
        }

        $bobot = $this->rapor->bobot();
        $skala = $this->rapor->skala()->map(fn ($s) => ['min' => $s->nilai_min, 'predikat' => $s->predikat])->values()->all();
        $siswaIds = $this->nilai->siswa($kelas)->pluck('id');

        DB::transaction(function () use ($pelaku, $kelas, $periode, $bobot, $skala, $siswaIds) {
            NilaiRapor::with('mapel:id,nama,kkm')->where('kelas_id', $kelas->id)->where('periode_id', $periode->id)
                ->whereIn('siswa_id', $siswaIds)->lockForUpdate()->get()->each(function (NilaiRapor $n) use ($kelas, $bobot, $skala) {
                    $n->update(['status' => 'final', 'snapshot' => [
                        'mapel_nama' => $n->mapel->nama, 'kelas_nama' => $kelas->nama, 'kkm' => $n->mapel->kkm, 'bobot' => $bobot, 'skala' => $skala,
                    ]]);
                });

            foreach ($siswaIds as $id) {
                RaporSiswa::updateOrCreate(
                    ['siswa_id' => $id, 'periode_id' => $periode->id],
                    ['kelas_id' => $kelas->id, 'kelas_nama' => $kelas->nama, 'status' => 'final', 'difinalkan_oleh' => $pelaku->id, 'difinalkan_pada' => now()],
                );
            }
        });

        AuditLog::catat('rapor.final', "kelas {$kelas->id} periode {$periode->id}: {$siswaIds->count()} siswa", $pelaku);
    }

    /** Admin membuka kembali rapor satu kelas yang sudah final; alasan wajib dan dicatat. */
    public function bukaFinal(User $admin, Kelas $kelas, Periode $periode, string $alasan): void
    {
        $alasan = trim($alasan);
        if ($admin->role !== 'admin') {
            throw new DomainException('Hanya admin yang dapat membuka rapor yang sudah final.');
        }
        if (mb_strlen($alasan) < 5) {
            throw new DomainException('Alasan membuka final wajib diisi (minimal 5 karakter).');
        }

        DB::transaction(function () use ($kelas, $periode) {
            NilaiRapor::where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->update(['status' => 'draf', 'snapshot' => null]);
            RaporSiswa::where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->update(['status' => 'draf', 'difinalkan_oleh' => null, 'difinalkan_pada' => null]);
        });

        AuditLog::catat('rapor.buka_final', "kelas {$kelas->id} periode {$periode->id}: ".mb_substr($alasan, 0, 300), $admin);
    }

    private function hari(mixed $v): int
    {
        return is_numeric($v) ? (int) max(0, min(999, (float) $v)) : 0;
    }
}
