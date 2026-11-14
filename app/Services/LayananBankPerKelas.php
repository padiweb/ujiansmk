<?php

namespace App\Services;

use App\Models\BankSoal;
use App\Models\Kelas;
use App\Models\Pengampu;
use App\Models\User;

/**
 * Menentukan kelas mana yang memakai bank soal mana dalam satu jadwal. Bila bank yang dipilih dibuat oleh dua guru pengampu
 * atau lebih yang mengajar kelas berbeda (mis. dua guru PAI untuk satu angkatan), soal tiap guru hanya dipakai untuk kelas
 * yang diajarnya. Bank buatan admin atau guru yang bukan pengampu kelas jadwal tetap dipakai semua kelas.
 */
class LayananBankPerKelas
{
    /**
     * @param  list<int|string>  $kelasIds
     * @param  list<int|string>  $bankIds
     * @return array<int,?list<int>> bank_id => daftar kelas (null = semua kelas)
     *
     * @throws KesalahanJadwal bila ada kelas yang tidak kebagian bank
     */
    public function petakan(int $mapelId, array $kelasIds, array $bankIds): array
    {
        $kelasIds = array_values(array_unique(array_map('intval', $kelasIds)));
        $bankIds = array_values(array_unique(array_map('intval', $bankIds)));
        $semua = array_fill_keys($bankIds, null);

        $pengampu = Pengampu::where('mapel_id', $mapelId)->whereIn('kelas_id', $kelasIds)->get(['guru_id', 'kelas_id']);
        $guruPengampu = $pengampu->pluck('guru_id')->unique();
        $bank = BankSoal::whereIn('id', $bankIds)->get(['id', 'dibuat_oleh', 'nama'])->keyBy('id');

        $pemilik = $bank->pluck('dibuat_oleh')->unique()->filter(fn ($g) => $guruPengampu->contains($g));
        if ($pemilik->count() < 2) {
            return $semua;
        }

        $hasil = [];
        foreach ($bank as $id => $b) {
            $hasil[$id] = $guruPengampu->contains($b->dibuat_oleh)
                ? $pengampu->where('guru_id', $b->dibuat_oleh)->pluck('kelas_id')->map(fn ($k) => (int) $k)->unique()->values()->all()
                : null; // bank bersama
        }

        foreach ($kelasIds as $k) {
            $dapat = collect($hasil)->contains(fn ($daftar) => $daftar === null || in_array($k, $daftar, true));
            if (! $dapat) {
                $nama = Kelas::whereKey($k)->value('nama');
                $guru = User::whereIn('id', $pengampu->where('kelas_id', $k)->pluck('guru_id'))->pluck('nama')->implode(', ') ?: 'guru pengampunya';

                throw new KesalahanJadwal("Kelas {$nama} belum kebagian bank soal: pilih juga bank buatan {$guru}, atau keluarkan kelas itu dari jadwal.");
            }
        }

        return $hasil;
    }

    /** Kelas yang memakai bank tertentu, sebagai teks untuk tampilan. */
    public function namaKelas(?array $kelasIds): string
    {
        return $kelasIds === null ? 'semua kelas' : Kelas::whereIn('id', $kelasIds)->orderBy('nama')->pluck('nama')->implode(', ');
    }
}
