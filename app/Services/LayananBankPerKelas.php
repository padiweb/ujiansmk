<?php

namespace App\Services;

use App\Models\BankSoal;
use App\Models\KegiatanUjian;
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

    /** Semua kelas pada tingkat tertentu di tahun ajaran milik kegiatan itu. */
    public function kelasTingkat(int $kegiatanId, string $tingkat): array
    {
        $tahun = KegiatanUjian::with('periode:id,tahun_ajaran_id')->find($kegiatanId)?->periode?->tahun_ajaran_id;

        return $tahun ? Kelas::where('tahun_ajaran_id', $tahun)->where('tingkat', $tingkat)->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
    }

    /**
     * Bank soal yang dipakai otomatis untuk mapel + tingkat pada satu kegiatan: bank yang berisi soal siap, milik guru pengampu mapel itu
     * di kelas peserta (atau milik admin), dan terkait kegiatan ini atau bank umum. Bila seorang guru punya bank untuk kegiatan ini,
     * bank umumnya tidak ikut dipakai.
     *
     * @param  list<int|string>  $kelasIds
     * @return \Illuminate\Support\Collection<int,BankSoal>
     */
    public function otomatis(int $kegiatanId, int $mapelId, string $tingkat, array $kelasIds): \Illuminate\Support\Collection
    {
        $guruPengampu = Pengampu::where('mapel_id', $mapelId)->whereIn('kelas_id', array_map('intval', $kelasIds))->pluck('guru_id')->unique();

        $bank = BankSoal::with('pembuat:id,nama,role')
            ->where('mapel_id', $mapelId)->where('tingkat', $tingkat)
            ->where(fn ($q) => $q->where('kegiatan_id', $kegiatanId)->orWhereNull('kegiatan_id'))
            ->withCount([
                'soal as pg_siap' => fn ($q) => $q->where('tipe', 'pg')->where('status', 'siap'),
                'soal as esai_siap' => fn ($q) => $q->where('tipe', 'esai')->where('status', 'siap'),
            ])->orderBy('nama')->get()
            ->filter(fn ($b) => $b->pg_siap + $b->esai_siap > 0)
            ->filter(fn ($b) => $b->pembuat?->role === User::ADMIN || $guruPengampu->isEmpty() || $guruPengampu->contains($b->dibuat_oleh));

        $punyaKegiatan = $bank->where('kegiatan_id', $kegiatanId)->pluck('dibuat_oleh')->unique();

        return $bank->reject(fn ($b) => $b->kegiatan_id === null && $punyaKegiatan->contains($b->dibuat_oleh))->values();
    }

    /** Kelas yang memakai bank tertentu, sebagai teks untuk tampilan. */
    public function namaKelas(?array $kelasIds): string
    {
        return $kelasIds === null ? 'semua kelas' : Kelas::whereIn('id', $kelasIds)->orderBy('nama')->pluck('nama')->implode(', ');
    }
}
