<?php

namespace App\Impor;

use App\Models\Jurusan;

class JurusanPengimpor extends Pengimpor
{
    public function slug(): string
    {
        return 'jurusan';
    }

    public function judul(): string
    {
        return 'Jurusan';
    }

    public function kolom(): array
    {
        return [
            ['header' => 'Kode', 'key' => 'kode', 'wajib' => true, 'contoh' => 'TKJ'],
            ['header' => 'Nama', 'key' => 'nama', 'wajib' => true, 'contoh' => 'Teknik Komputer dan Jaringan'],
            ['header' => 'Program Keahlian', 'key' => 'program_keahlian', 'wajib' => false, 'contoh' => 'Teknik Jaringan Komputer dan Telekomunikasi'],
        ];
    }

    public function petunjuk(): array
    {
        return ['Kode yang sudah ada akan diperbarui.'];
    }

    protected function aturan(array $b): array
    {
        return [
            'kode' => ['required', 'string', 'max:20'],
            'nama' => ['required', 'string', 'max:100'],
            'program_keahlian' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function kunciBaris(array $b): ?string
    {
        return mb_strtolower($b['kode']);
    }

    protected function terapkan(array $b): string
    {
        $jurusan = Jurusan::firstOrNew(['kode' => $b['kode']]);
        $baru = ! $jurusan->exists;
        $jurusan->fill(['nama' => $b['nama'], 'program_keahlian' => $b['program_keahlian'] ?? $jurusan->program_keahlian])->save();

        return $baru ? 'baru' : 'diperbarui';
    }
}
