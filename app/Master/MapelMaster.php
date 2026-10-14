<?php

namespace App\Master;

use App\Models\Mapel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class MapelMaster extends DefinisiMaster
{
    public function slug(): string
    {
        return 'mapel';
    }

    public function judul(): string
    {
        return 'Mata Pelajaran';
    }

    public function model(): string
    {
        return Mapel::class;
    }

    public function urut(): string
    {
        return 'kode';
    }

    public function cari(): array
    {
        return ['kode', 'nama'];
    }

    public function kolom(): array
    {
        return [['kode', 'Kode'], ['nama', 'Mata pelajaran'], ['kelompok', 'Kelompok'], ['kkm', 'KKM']];
    }

    public function bidang(?Model $m): array
    {
        return [
            ['name' => 'kode', 'label' => 'Kode', 'type' => 'text',
                'rules' => ['required', 'string', 'max:20', Rule::unique('mapel', 'kode')->ignore($m?->id)]],
            ['name' => 'nama', 'label' => 'Nama mata pelajaran', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
            ['name' => 'nama_skl', 'label' => 'Nama di SKL', 'type' => 'text',
                'bantuan' => 'Kosongkan bila sama dengan nama mata pelajaran.', 'rules' => ['nullable', 'string', 'max:200']],
            ['name' => 'kelompok', 'label' => 'Kelompok', 'type' => 'select',
                'options' => ['A' => 'A (Umum)', 'B' => 'B (Umum)', 'C1' => 'C1 (Kejuruan)', 'C2' => 'C2 (Kejuruan)',
                    'C3' => 'C3 (Kejuruan)', 'ML' => 'ML (Muatan Lokal)'],
                'rules' => ['required', Rule::in(Mapel::KELOMPOK)]],
            ['name' => 'kkm', 'label' => 'KKM', 'type' => 'number', 'nilai' => $m?->kkm ?? 75, 'rules' => ['required', 'integer', 'between:0,100']],
        ];
    }
}
