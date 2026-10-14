<?php

namespace App\Impor;

use App\Models\Ruang;

class RuangPengimpor extends Pengimpor
{
    public function slug(): string
    {
        return 'ruang';
    }

    public function judul(): string
    {
        return 'Ruang Ujian';
    }

    public function kolom(): array
    {
        return [['header' => 'Nama Ruang', 'key' => 'nama', 'wajib' => true, 'contoh' => 'Lab Komputer 1']];
    }

    protected function aturan(array $b): array
    {
        return ['nama' => ['required', 'string', 'max:50']];
    }

    protected function kunciBaris(array $b): ?string
    {
        return mb_strtolower($b['nama']);
    }

    protected function terapkan(array $b): string
    {
        $ruang = Ruang::firstOrCreate(['nama' => $b['nama']]);

        return $ruang->wasRecentlyCreated ? 'baru' : 'diperbarui';
    }
}
