<?php

namespace App\Master;

use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class TahunAjaranMaster extends DefinisiMaster
{
    public function slug(): string
    {
        return 'tahun-ajaran';
    }

    public function judul(): string
    {
        return 'Tahun Ajaran';
    }

    public function model(): string
    {
        return TahunAjaran::class;
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
            ['nama', 'Tahun ajaran'],
            ['aktif', 'Status', fn (TahunAjaran $t) => $t->aktif ? 'Aktif' : '—'],
        ];
    }

    public function bidang(?Model $m): array
    {
        return [
            ['name' => 'nama', 'label' => 'Tahun ajaran', 'type' => 'text', 'bantuan' => 'Contoh: 2025/2026',
                'rules' => ['required', 'regex:/^\d{4}\/\d{4}$/', Rule::unique('tahun_ajaran', 'nama')->ignore($m?->id)]],
            ['name' => 'aktif', 'label' => 'Status', 'type' => 'select', 'nilai' => $m ? (int) $m->aktif : 0,
                'options' => ['1' => 'Aktif', '0' => 'Tidak aktif'],
                'bantuan' => 'Hanya satu tahun ajaran yang boleh aktif; yang lain otomatis dinonaktifkan.',
                'rules' => ['required', 'boolean']],
        ];
    }

    public function sesudahSimpan(Model $m, array $data, bool $baru): ?string
    {
        if ($m->aktif) {
            $m->jadikanAktif();
        }

        return null;
    }

    public function tolakHapus(Model $m): ?string
    {
        if ($m->kelas()->exists() || $m->periode()->exists()) {
            return 'Tahun ajaran ini masih memiliki kelas atau periode.';
        }

        return null;
    }
}
