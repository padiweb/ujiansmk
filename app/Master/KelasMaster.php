<?php

namespace App\Master;

use App\Models\Jurusan;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class KelasMaster extends DefinisiMaster
{
    public function slug(): string
    {
        return 'kelas';
    }

    public function judul(): string
    {
        return 'Kelas';
    }

    public function model(): string
    {
        return Kelas::class;
    }

    public function dengan(): array
    {
        return ['tahunAjaran', 'jurusan', 'waliKelas'];
    }

    public function urut(): string
    {
        return 'nama';
    }

    public function cari(): array
    {
        return ['nama'];
    }

    public function kolom(): array
    {
        return [
            ['nama', 'Kelas'],
            ['tahun', 'Tahun ajaran', fn (Kelas $k) => $k->tahunAjaran->nama],
            ['tingkat', 'Tingkat'],
            ['jurusan', 'Jurusan', fn (Kelas $k) => $k->jurusan?->kode ?? '—'],
            ['wali', 'Wali kelas', fn (Kelas $k) => $k->waliKelas?->nama ?? '—'],
        ];
    }

    public function filter(): array
    {
        return [
            [
                'name' => 'tahun',
                'label' => 'Semua tahun ajaran',
                'options' => TahunAjaran::orderByDesc('nama')->pluck('nama', 'id')->all(),
                'terapkan' => fn ($q, $v) => $q->where('tahun_ajaran_id', $v),
            ],
            [
                'name' => 'tingkat',
                'label' => 'Semua tingkat',
                'options' => array_combine(Kelas::TINGKAT, Kelas::TINGKAT),
                'terapkan' => fn ($q, $v) => $q->where('tingkat', $v),
            ],
        ];
    }

    public function bidang(?Model $m): array
    {
        return [
            ['name' => 'tahun_ajaran_id', 'label' => 'Tahun ajaran', 'type' => 'select',
                'nilai' => $m?->tahun_ajaran_id ?? TahunAjaran::where('aktif', true)->value('id'),
                'options' => TahunAjaran::orderByDesc('nama')->pluck('nama', 'id')->all(),
                'rules' => ['required', Rule::exists('tahun_ajaran', 'id')]],
            ['name' => 'tingkat', 'label' => 'Tingkat', 'type' => 'select',
                'options' => array_combine(Kelas::TINGKAT, Kelas::TINGKAT), 'rules' => ['required', Rule::in(Kelas::TINGKAT)]],
            ['name' => 'nama', 'label' => 'Nama kelas', 'type' => 'text', 'bantuan' => 'Contoh: X TKJ 1',
                'rules' => ['required', 'string', 'max:30',
                    Rule::unique('kelas', 'nama')->where(fn ($q) => $q->where('tahun_ajaran_id', request('tahun_ajaran_id')))->ignore($m?->id)]],
            ['name' => 'jurusan_id', 'label' => 'Jurusan', 'type' => 'select', 'kosong' => 'Tanpa jurusan',
                'options' => Jurusan::orderBy('kode')->pluck('nama', 'id')->all(),
                'rules' => ['nullable', Rule::exists('jurusan', 'id')]],
            ['name' => 'wali_kelas_id', 'label' => 'Wali kelas', 'type' => 'select', 'kosong' => 'Belum ditentukan',
                'options' => User::where('role', User::GURU)->where('aktif', true)->orderBy('nama')->pluck('nama', 'id')->all(),
                'rules' => ['nullable', Rule::exists('users', 'id')->where('role', User::GURU)]],
        ];
    }

    public function tolakHapus(Model $m): ?string
    {
        return $m->siswa()->exists() ? 'Kelas ini masih memiliki siswa.' : null;
    }
}
