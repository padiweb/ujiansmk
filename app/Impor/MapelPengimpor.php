<?php

namespace App\Impor;

use App\Models\Mapel;
use Illuminate\Validation\Rule;

class MapelPengimpor extends Pengimpor
{
    public function slug(): string
    {
        return 'mapel';
    }

    public function judul(): string
    {
        return 'Mata Pelajaran';
    }

    public function kolom(): array
    {
        return [
            ['header' => 'Kode', 'key' => 'kode', 'wajib' => true, 'contoh' => 'MTK'],
            ['header' => 'Nama', 'key' => 'nama', 'wajib' => true, 'contoh' => 'Matematika'],
            ['header' => 'Nama SKL', 'key' => 'nama_skl', 'wajib' => false, 'contoh' => ''],
            ['header' => 'Kelompok', 'key' => 'kelompok', 'wajib' => false, 'contoh' => 'A'],
            ['header' => 'KKM', 'key' => 'kkm', 'wajib' => false, 'contoh' => '75'],
        ];
    }

    public function petunjuk(): array
    {
        return [
            'Kode yang sudah ada akan diperbarui.',
            'Kelompok: A, B, C1, C2, C3, atau ML (kosong = A). KKM 0-100 (kosong = 75).',
        ];
    }

    protected function aturan(array $b): array
    {
        return [
            'kode' => ['required', 'string', 'max:20'],
            'nama' => ['required', 'string', 'max:100'],
            'nama_skl' => ['nullable', 'string', 'max:200'],
            'kelompok' => ['nullable', Rule::in(Mapel::KELOMPOK)],
            'kkm' => ['nullable', 'integer', 'between:0,100'],
        ];
    }

    protected function kunciBaris(array $b): ?string
    {
        return mb_strtolower($b['kode']);
    }

    protected function terapkan(array $b): string
    {
        $mapel = Mapel::firstOrNew(['kode' => $b['kode']]);
        $baru = ! $mapel->exists;
        $mapel->fill([
            'nama' => $b['nama'],
            'nama_skl' => $b['nama_skl'] ?? $mapel->nama_skl,
            'kelompok' => $b['kelompok'] ?? ($mapel->kelompok ?: 'A'),
            'kkm' => isset($b['kkm']) ? (int) $b['kkm'] : ($mapel->kkm ?: 75),
        ])->save();

        return $baru ? 'baru' : 'diperbarui';
    }
}
