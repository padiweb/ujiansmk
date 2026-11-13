<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\NilaiRapor;
use App\Models\Pengampu;
use App\Models\Periode;
use App\Models\Siswa;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Nilai Lampau: nilai rapor semester lama yang tidak diisi lewat aplikasi ini (semester bertanda "rapor tidak diisi di sini").
 * Guru mengisi NP dan NK langsung; hasilnya dibekukan (final) dan dipakai transkrip serta SKL.
 */
class LayananNilaiLampau
{
    public function __construct(private LayananRapor $rapor, private LayananNilaiRapor $nilai) {}

    /** Semester yang boleh diisi sebagai nilai lampau: rapornya tidak diisi di aplikasi ini. */
    public function daftarPeriode(): Collection
    {
        return Periode::with('tahunAjaran')->where('rapor_di_sini', false)->where('tipe', 'reguler')
            ->orderByDesc('tahun_ajaran_id')->orderBy('semester')->get();
    }

    /**
     * Kelas SAAT INI (tahun ajaran aktif, bukan kelas semester lama): nilai lampau diisi untuk siswa yang sekarang duduk di kelas ini.
     *
     * @return Collection<int,Kelas>
     */
    public function daftarKelas(): Collection
    {
        $tahun = Periode::where('aktif', true)->value('tahun_ajaran_id') ?? Kelas::max('tahun_ajaran_id');

        return Kelas::where('tahun_ajaran_id', $tahun)->orderBy('tingkat')->orderBy('nama')->get();
    }

    /** Admin: semua mapel. Guru: mapel yang pernah/sedang diampunya. */
    public function daftarMapel(User $user): Collection
    {
        return Mapel::query()
            ->when($user->role !== 'admin', fn ($q) => $q->whereIn('id', Pengampu::where('guru_id', $user->id)->select('mapel_id')))
            ->orderBy('nama')->get();
    }

    public function boleh(User $user, Mapel $mapel): bool
    {
        return $user->aktif && match ($user->role) {
            'admin' => true,
            'guru' => Pengampu::where('guru_id', $user->id)->where('mapel_id', $mapel->id)->exists(),
            default => false,
        };
    }

    /** Nama kelas pada semester lampau itu (teks bebas yang pernah dicatat untuk kelas ini), bila ada. */
    public function kelasLama(Mapel $mapel, Kelas $kelas, Periode $periode): ?string
    {
        $n = NilaiRapor::where('mapel_id', $mapel->id)->where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->latest('updated_at')->first();

        return ($n?->snapshot['lampau'] ?? false) ? ($n->snapshot['kelas_nama'] ?? null) : null;
    }

    /** @return list<array{siswa:Siswa,np:?int,nk:?int,na:?int,predikat:?string,ada:bool,status:string}> status: kosong | draf | final */
    public function tabel(Mapel $mapel, Kelas $kelas, Periode $periode): array
    {
        $ada = NilaiRapor::where('mapel_id', $mapel->id)->where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->get()->keyBy('siswa_id');

        return $this->nilai->siswa($kelas)->map(function (Siswa $s) use ($ada) {
            $n = $ada[$s->id] ?? null;

            return [
                'siswa' => $s, 'ada' => $n !== null, 'status' => $n === null ? 'kosong' : ($n->status === 'final' ? 'final' : 'draf'),
                'np' => $n?->np, 'nk' => $n?->nk, 'na' => $n?->na, 'predikat' => $n?->predikat,
            ];
        })->all();
    }

    /**
     * @param  array<int|string,array{np?:mixed,nk?:mixed}>  $input  siswa_id => [np, nk]
     * @return array{disimpan:int,dilewati:int,salah:int}
     *
     * @throws DomainException
     */
    public function simpan(User $pelaku, Mapel $mapel, Kelas $kelas, Periode $periode, array $input, ?string $kelasLama = null, bool $catat = true): array
    {
        if ($periode->rapor_di_sini) {
            throw new DomainException('Semester ini rapornya diisi di aplikasi ini. Gunakan menu Input Nilai.');
        }

        $bobot = $this->rapor->bobot();
        $skala = $this->rapor->skala();
        $siswaIds = $this->nilai->siswa($kelas)->pluck('id')->all();
        $disimpan = 0;
        $dilewati = 0;
        $salah = 0;
        $kelasLama = mb_substr(trim((string) $kelasLama), 0, 50) ?: $kelas->nama;

        DB::transaction(function () use ($pelaku, $mapel, $kelas, $periode, $input, $bobot, $skala, $siswaIds, $kelasLama, &$disimpan, &$dilewati, &$salah) {
            $ada = NilaiRapor::where('mapel_id', $mapel->id)->where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->lockForUpdate()->get()->keyBy('siswa_id');

            foreach ($input as $siswaId => $baris) {
                $siswaId = (int) $siswaId;
                if (! in_array($siswaId, $siswaIds, true)) {
                    continue;
                }
                $np = $this->angka($baris['np'] ?? null);
                $nk = $this->angka($baris['nk'] ?? null);
                if ($np === false || $nk === false) {
                    $salah++;

                    continue;
                }
                if ($np === null && $nk === null) {
                    $dilewati++;

                    continue;
                }

                $np = $np === null ? 0 : (int) round($np);
                $nk = $nk === null ? 0 : (int) round($nk);
                $na = match (true) {
                    $nk === 0 && $np > 0 => $np,
                    $np === 0 && $nk > 0 => $nk,
                    default => (int) round(($np * ($bobot['np'] ?? 50) + $nk * ($bobot['nk'] ?? 50)) / 100),
                };

                // Komponen diisi sama dengan NP/NK supaya data konsisten dengan rapor biasa (NP = rata-rata komponen pengetahuan).
                $data = [
                    'ujian' => $np ?: null, 'harian' => $np ?: null, 'tugas_akhir' => $np ?: null, 'praktik' => $nk ?: null, 'adab' => $nk ?: null,
                    'ujian_sumber' => 'manual', 'harian_sumber' => 'manual', 'guru_id' => $pelaku->id,
                    'np' => $np, 'nk' => $nk, 'na' => $na, 'predikat' => $this->rapor->predikat($na, $skala), 'status' => 'final',
                    'snapshot' => ['mapel_nama' => $mapel->nama, 'kelas_nama' => $kelasLama, 'kkm' => $mapel->kkm, 'lampau' => true, 'bobot' => $bobot],
                ];
                $n = $ada[$siswaId] ?? null;
                if ($n) {
                    $n->update($data);
                } else {
                    NilaiRapor::create($data + ['siswa_id' => $siswaId, 'mapel_id' => $mapel->id, 'periode_id' => $periode->id, 'kelas_id' => $kelas->id]);
                }
                $disimpan++;
            }
        });

        if ($catat) {
            AuditLog::catat('nilai_lampau.simpan', "mapel {$mapel->id} kelas {$kelas->id} periode {$periode->id}: {$disimpan} siswa", $pelaku);
        }

        return ['disimpan' => $disimpan, 'dilewati' => $dilewati, 'salah' => $salah];
    }

    /** @return float|null|false null = kosong, false = bukan angka 0–100 */
    private function angka(mixed $v): float|null|false
    {
        $v = str_replace(',', '.', trim((string) $v));
        if ($v === '') {
            return null;
        }
        if (! is_numeric($v) || (float) $v < 0 || (float) $v > 100) {
            return false;
        }

        return (float) $v;
    }
}
