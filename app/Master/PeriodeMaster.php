<?php

namespace App\Master;

use App\Models\Periode;
use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class PeriodeMaster extends DefinisiMaster
{
    public function slug(): string
    {
        return 'periode';
    }

    public function judul(): string
    {
        return 'Semester / Periode';
    }

    public function model(): string
    {
        return Periode::class;
    }

    public function dengan(): array
    {
        return ['tahunAjaran'];
    }

    public function urut(): string
    {
        return 'tahun_ajaran_id';
    }

    public function kolom(): array
    {
        return [
            ['tahun', 'Tahun ajaran', fn (Periode $p) => $p->tahunAjaran->nama],
            ['semester', 'Semester', fn (Periode $p) => ucfirst($p->semester)],
            ['tipe', 'Jenis', fn (Periode $p) => $p->tipe === 'ujian_sekolah' ? 'Ujian Sekolah' : 'Reguler'],
            ['aktif', 'Status', fn (Periode $p) => $p->aktif ? 'Aktif' : '—'],
        ];
    }

    public function bidang(?Model $m): array
    {
        return [
            ['name' => 'tahun_ajaran_id', 'label' => 'Tahun ajaran', 'type' => 'select',
                'options' => TahunAjaran::orderByDesc('nama')->pluck('nama', 'id')->all(),
                'rules' => ['required', Rule::exists('tahun_ajaran', 'id')]],
            ['name' => 'semester', 'label' => 'Semester', 'type' => 'select',
                'options' => ['ganjil' => 'Ganjil', 'genap' => 'Genap'], 'rules' => ['required', Rule::in(Periode::SEMESTER)]],
            ['name' => 'tipe', 'label' => 'Jenis', 'type' => 'select',
                'options' => ['reguler' => 'Reguler', 'ujian_sekolah' => 'Ujian Sekolah (kelas XII)'],
                'rules' => ['required', Rule::in(Periode::TIPE),
                    Rule::unique('periode')->where(fn ($q) => $q
                        ->where('tahun_ajaran_id', request('tahun_ajaran_id'))
                        ->where('semester', request('semester')))->ignore($m?->id)]],
            ['name' => 'aktif', 'label' => 'Status', 'type' => 'select', 'nilai' => $m ? (int) $m->aktif : 0,
                'options' => ['1' => 'Aktif', '0' => 'Tidak aktif'], 'rules' => ['required', 'boolean']],
        ];
    }

    public function sesudahSimpan(Model $m, array $data, bool $baru): ?string
    {
        if ($m->aktif) {
            $m->jadikanAktif();
        }

        return null;
    }
}
