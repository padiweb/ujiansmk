<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\NilaiUs;
use App\Models\Pengampu;
use App\Models\Periode;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Input Nilai Ujian Sekolah (US) kelas XII: hasil CBT US otomatis, bisa ditimpa manual, dipakai transkrip dan SKL. */
class LayananUjianSekolah
{
    public function __construct(private LayananNilaiRapor $nilai) {}

    /** Semester bertipe Ujian Sekolah (yang aktif dulu, lalu yang terbaru), bila ada. */
    public function periodeUs(): ?Periode
    {
        return Periode::where('tipe', 'ujian_sekolah')->orderByDesc('aktif')->orderByDesc('id')->first();
    }

    public function boleh(User $user, Mapel $mapel, Kelas $kelas): bool
    {
        if (! $user->aktif || $kelas->tingkat !== 'XII') {
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
     * Pasangan mapel + kelas XII yang boleh diisi, dengan kemajuan pengisian.
     *
     * @return Collection<int,array{mapel:Mapel,kelas:Kelas,guru:?string,siswa:int,terisi:int}>
     */
    public function pasangan(User $user): Collection
    {
        $pengampu = Pengampu::query()->with('mapel', 'kelas', 'guru:id,nama')
            ->whereHas('kelas', fn ($k) => $k->where('tingkat', 'XII'))
            ->when($user->role !== 'admin', fn ($q) => $q->where('guru_id', $user->id))->get()
            ->unique(fn ($p) => $p->mapel_id.'-'.$p->kelas_id);

        return $pengampu->map(function ($p) {
            $ids = $this->nilai->siswa($p->kelas)->pluck('id');

            return [
                'mapel' => $p->mapel, 'kelas' => $p->kelas, 'guru' => $p->guru?->nama, 'siswa' => $ids->count(),
                'terisi' => NilaiUs::where('mapel_id', $p->mapel_id)->whereIn('siswa_id', $ids)->where(fn ($q) => $q->whereNotNull('nilai')->orWhereNotNull('nilai_cbt'))->count(),
            ];
        })->sortBy(fn ($p) => $p['kelas']->nama.$p['mapel']->nama)->values();
    }

    /**
     * @return array{baris:list<array<string,mixed>>,masuk_xii2:bool,ada_cbt:bool}
     */
    public function tabel(Mapel $mapel, Kelas $kelas): array
    {
        $siswa = $this->nilai->siswa($kelas);
        $tersimpan = NilaiUs::where('mapel_id', $mapel->id)->whereIn('siswa_id', $siswa->pluck('id'))->get()->keyBy('siswa_id');
        $cbt = $this->cbt($mapel, $kelas);

        $baris = $siswa->map(function (Siswa $s) use ($tersimpan, $cbt, $mapel) {
            $n = $tersimpan[$s->id] ?? null;
            $c = $cbt[$s->id] ?? null;
            $manual = $n?->nilai !== null ? (float) $n->nilai : null;
            $pakai = $manual ?? $c;

            return [
                'siswa' => $s, 'cbt' => $c, 'manual' => $manual, 'pakai' => $pakai, 'keterangan' => $n?->keterangan,
                'lulus' => $pakai === null ? null : $pakai >= $mapel->kkm,
            ];
        })->all();

        return ['baris' => $baris, 'masuk_xii2' => $tersimpan->contains(fn ($n) => $n->masuk_xii2), 'ada_cbt' => $cbt !== []];
    }

    /**
     * @param  array<int|string,array{nilai?:mixed,keterangan?:mixed}>  $input
     * @return array{disimpan:int,salah:int}
     */
    public function simpan(User $pelaku, Mapel $mapel, Kelas $kelas, array $input, bool $masukXii2): array
    {
        $siswaIds = $this->nilai->siswa($kelas)->pluck('id')->all();
        $cbt = $this->cbt($mapel, $kelas);
        $disimpan = 0;
        $salah = 0;

        DB::transaction(function () use ($pelaku, $mapel, $input, $masukXii2, $siswaIds, $cbt, &$disimpan, &$salah) {
            $ada = NilaiUs::where('mapel_id', $mapel->id)->whereIn('siswa_id', $siswaIds)->lockForUpdate()->get()->keyBy('siswa_id');

            foreach ($siswaIds as $id) {
                $baris = $input[$id] ?? $input[(string) $id] ?? [];
                $v = str_replace(',', '.', trim((string) ($baris['nilai'] ?? '')));
                if ($v !== '' && (! is_numeric($v) || (float) $v < 0 || (float) $v > 100)) {
                    $salah++;

                    continue;
                }
                $nilai = $v === '' ? null : round((float) $v, 2);
                $ket = mb_substr(trim((string) ($baris['keterangan'] ?? '')), 0, 255) ?: null;
                $n = $ada[$id] ?? null;
                $data = ['nilai' => $nilai, 'nilai_cbt' => $cbt[$id] ?? null, 'keterangan' => $ket, 'masuk_xii2' => $masukXii2, 'guru_id' => $pelaku->id];

                if ($n) {
                    $n->update($data);
                } elseif ($nilai !== null || ($cbt[$id] ?? null) !== null || $ket !== null) {
                    NilaiUs::create($data + ['siswa_id' => $id, 'mapel_id' => $mapel->id]);
                } else {
                    continue;
                }
                $disimpan++;
            }
        });

        AuditLog::catat('nilai_us.simpan', "mapel {$mapel->id} kelas {$kelas->id}: {$disimpan} siswa", $pelaku);

        return ['disimpan' => $disimpan, 'salah' => $salah];
    }

    /** @return array<int,float> hasil CBT Ujian Sekolah per siswa (kosong bila belum ada semester US) */
    private function cbt(Mapel $mapel, Kelas $kelas): array
    {
        $periode = $this->periodeUs();

        return $periode ? $this->nilai->ujianOtomatis($mapel, $kelas, $periode) : [];
    }
}
