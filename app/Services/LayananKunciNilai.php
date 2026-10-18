<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\JadwalUjian;
use App\Models\KunciNilai;
use App\Models\SesiUjian;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Kunci nilai per jadwal dan kelas. Mengubah nilai pada kelas yang terkunci membukanya otomatis (tercatat). */
class LayananKunciNilai
{
    /** Kelas peserta (di antara kelas jadwal) tempat siswa sesi ini berada. */
    public function kelasSesi(SesiUjian $sesi): ?int
    {
        $id = DB::table('keanggotaan_kelas')->where('siswa_id', $sesi->siswa_id)
            ->whereIn('kelas_id', DB::table('jadwal_kelas')->where('jadwal_id', $sesi->jadwal_id)->select('kelas_id'))
            ->min('kelas_id');

        return $id === null ? null : (int) $id;
    }

    public function terkunci(SesiUjian $sesi): bool
    {
        $kelas = $this->kelasSesi($sesi);

        return $kelas !== null && KunciNilai::where('jadwal_id', $sesi->jadwal_id)->where('kelas_id', $kelas)->exists();
    }

    /** Membuka kunci kelas sesi ini karena nilai diubah. @return bool true bila kunci memang terbuka karenanya */
    public function bukaOtomatis(SesiUjian $sesi, User $pelaku): bool
    {
        $kelas = $this->kelasSesi($sesi);
        if ($kelas === null || ! KunciNilai::where('jadwal_id', $sesi->jadwal_id)->where('kelas_id', $kelas)->delete()) {
            return false;
        }

        AuditLog::catat('rekap.buka_kunci_otomatis', "jadwal {$sesi->jadwal_id} kelas {$kelas} (nilai diubah)", $pelaku);

        return true;
    }

    /** @param  list<int>  $kelas  @return list<int> kelas yang sedang terkunci */
    public function kelasTerkunci(JadwalUjian $jadwal, array $kelas): array
    {
        return KunciNilai::where('jadwal_id', $jadwal->id)->whereIn('kelas_id', $kelas)->pluck('kelas_id')->map(fn ($k) => (int) $k)->all();
    }
}
