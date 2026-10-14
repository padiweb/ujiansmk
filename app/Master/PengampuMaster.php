<?php

namespace App\Master;

use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Pengampu;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class PengampuMaster extends DefinisiMaster
{
    public function slug(): string
    {
        return 'pengampu';
    }

    public function judul(): string
    {
        return 'Guru Pengampu';
    }

    public function model(): string
    {
        return Pengampu::class;
    }

    public function dengan(): array
    {
        return ['guru', 'mapel', 'kelas.tahunAjaran'];
    }

    public function kolom(): array
    {
        return [
            ['guru', 'Guru', fn (Pengampu $p) => $p->guru->nama],
            ['mapel', 'Mata pelajaran', fn (Pengampu $p) => $p->mapel->nama],
            ['kelas', 'Kelas', fn (Pengampu $p) => $p->kelas->nama.' ('.$p->kelas->tahunAjaran->nama.')'],
        ];
    }

    public function filter(): array
    {
        return [[
            'name' => 'guru',
            'label' => 'Semua guru',
            'options' => User::where('role', User::GURU)->orderBy('nama')->pluck('nama', 'id')->all(),
            'terapkan' => fn ($q, $v) => $q->where('guru_id', $v),
        ]];
    }

    public function bidang(?Model $m): array
    {
        return [
            ['name' => 'guru_id', 'label' => 'Guru', 'type' => 'select',
                'options' => User::where('role', User::GURU)->where('aktif', true)->orderBy('nama')->pluck('nama', 'id')->all(),
                'rules' => ['required', Rule::exists('users', 'id')->where('role', User::GURU)]],
            ['name' => 'mapel_id', 'label' => 'Mata pelajaran', 'type' => 'select',
                'options' => Mapel::orderBy('nama')->pluck('nama', 'id')->all(), 'rules' => ['required', Rule::exists('mapel', 'id')]],
            ['name' => 'kelas_id', 'label' => 'Kelas', 'type' => 'select',
                'options' => Kelas::with('tahunAjaran')->orderByDesc('tahun_ajaran_id')->orderBy('nama')->get()
                    ->mapWithKeys(fn (Kelas $k) => [$k->id => $k->nama.' ('.$k->tahunAjaran->nama.')'])->all(),
                'rules' => ['required', Rule::exists('kelas', 'id'),
                    Rule::unique('pengampu')->where(fn ($q) => $q
                        ->where('guru_id', request('guru_id'))
                        ->where('mapel_id', request('mapel_id')))->ignore($m?->id)]],
        ];
    }
}
