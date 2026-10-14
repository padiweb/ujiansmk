<?php

namespace App\Master;

use App\Models\Jurusan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class JurusanMaster extends DefinisiMaster
{
    public function slug(): string
    {
        return 'jurusan';
    }

    public function judul(): string
    {
        return 'Jurusan';
    }

    public function model(): string
    {
        return Jurusan::class;
    }

    public function urut(): string
    {
        return 'kode';
    }

    public function cari(): array
    {
        return ['kode', 'nama', 'program_keahlian'];
    }

    public function kolom(): array
    {
        return [['kode', 'Kode'], ['nama', 'Nama jurusan'], ['program_keahlian', 'Program keahlian']];
    }

    public function bidang(?Model $m): array
    {
        return [
            ['name' => 'kode', 'label' => 'Kode', 'type' => 'text', 'bantuan' => 'Contoh: TKJ',
                'rules' => ['required', 'string', 'max:20', Rule::unique('jurusan', 'kode')->ignore($m?->id)]],
            ['name' => 'nama', 'label' => 'Nama jurusan', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
            ['name' => 'program_keahlian', 'label' => 'Program keahlian', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:100']],
        ];
    }

    public function tolakHapus(Model $m): ?string
    {
        return $m->kelas()->exists() ? 'Jurusan ini masih dipakai oleh kelas.' : null;
    }
}
