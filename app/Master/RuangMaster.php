<?php

namespace App\Master;

use App\Models\Ruang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class RuangMaster extends DefinisiMaster
{
    public function slug(): string
    {
        return 'ruang';
    }

    public function judul(): string
    {
        return 'Ruang Ujian';
    }

    public function model(): string
    {
        return Ruang::class;
    }

    public function urut(): string
    {
        return 'nama';
    }

    public function cari(): array
    {
        return ['nama'];
    }

    /** Ruang ujian hanya ada di aplikasi ini, tidak datang dari EduWeb. */
    public function dikunciSinkron(): bool
    {
        return false;
    }

    public function kolom(): array
    {
        return [['nama', 'Nama ruang']];
    }

    public function bidang(?Model $m): array
    {
        return [
            ['name' => 'nama', 'label' => 'Nama ruang', 'type' => 'text', 'bantuan' => 'Contoh: Lab Komputer 1',
                'rules' => ['required', 'string', 'max:50', Rule::unique('ruang', 'nama')->ignore($m?->id)]],
        ];
    }
}
