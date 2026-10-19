<?php

namespace App\Master;

use App\Models\Denah;
use App\Models\DenahPenempatan;
use App\Models\Kelas;
use App\Models\Ruang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Support\KataSandi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class SiswaMaster extends DefinisiMaster
{
    private ?int $idTahunAktif = null;

    private bool $tahunDimuat = false;

    private ?string $sandiBaru = null;

    /** @var array<int,string>|null siswa_id => "Nama ruang · meja N" (penempatan tetap dari menu Ruang Ujian) */
    private ?array $petaRuang = null;

    public function slug(): string
    {
        return 'siswa';
    }

    public function judul(): string
    {
        return 'Siswa';
    }

    public function model(): string
    {
        return Siswa::class;
    }

    public function urut(): string
    {
        return 'nama';
    }

    public function cari(): array
    {
        return ['nisn', 'nis', 'nama'];
    }

    public function dengan(): array
    {
        return ['kelas'];
    }

    public function kolom(): array
    {
        return [
            ['nisn', 'NISN'],
            ['nama', 'Nama'],
            ['jenis_kelamin', 'L/P'],
            ['kelas_aktif', 'Kelas', fn (Siswa $s) => $s->kelas->firstWhere('tahun_ajaran_id', $this->tahunAktif())?->nama ?? '—'],
            ['ruang', 'Ruang', fn (Siswa $s) => $this->ruangSiswa()[$s->id] ?? '—'],
            ['status', 'Status', fn (Siswa $s) => ucfirst($s->status)],
        ];
    }

    public function filter(): array
    {
        return [
            [
                'name' => 'kelas',
                'label' => 'Semua kelas',
                'options' => Kelas::where('tahun_ajaran_id', $this->tahunAktif())->orderBy('nama')->pluck('nama', 'id')->all(),
                'terapkan' => fn (Builder $q, $v) => $q->whereHas('kelas', fn ($k) => $k->where('kelas.id', $v)),
            ],
            [
                'name' => 'ruang',
                'label' => 'Semua ruang',
                'options' => ['tanpa' => 'Belum punya ruang'] + Ruang::orderBy('nama')->pluck('nama', 'id')->all(),
                'terapkan' => fn (Builder $q, $v) => $v === 'tanpa'
                    ? $q->where('status', 'aktif')->whereNotIn('id', $this->kueriPenempatan()->select('siswa_id'))
                    : $q->whereIn('id', $this->kueriPenempatan()->where('ruang_id', (int) $v)->select('siswa_id')),
            ],
            [
                'name' => 'status',
                'label' => 'Semua status',
                'options' => ['aktif' => 'Aktif', 'lulus' => 'Lulus', 'keluar' => 'Keluar'],
                'terapkan' => fn (Builder $q, $v) => $q->where('status', $v),
            ],
        ];
    }

    public function bidang(?Model $m): array
    {
        $kelasAktif = $m?->kelas->firstWhere('tahun_ajaran_id', $this->tahunAktif())?->id;

        return [
            ['name' => 'nisn', 'label' => 'NISN', 'type' => 'text', 'bantuan' => 'Dipakai siswa sebagai nama pengguna.',
                'rules' => ['required', 'digits_between:5,20', Rule::unique('siswa', 'nisn')->ignore($m?->id)]],
            ['name' => 'nama', 'label' => 'Nama lengkap', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
            ['name' => 'nis', 'label' => 'NIS', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:30']],
            ['name' => 'jenis_kelamin', 'label' => 'Jenis kelamin', 'type' => 'select',
                'options' => ['L' => 'Laki-laki', 'P' => 'Perempuan'], 'rules' => ['nullable', Rule::in(['L', 'P'])]],
            ['name' => 'tempat_lahir', 'label' => 'Tempat lahir', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:60']],
            ['name' => 'tgl_lahir', 'label' => 'Tanggal lahir', 'type' => 'date', 'rules' => ['nullable', 'date']],
            ['name' => 'nama_wali', 'label' => 'Nama wali', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:100']],
            ['name' => 'no_peserta_us', 'label' => 'No. peserta ujian sekolah', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:50']],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select',
                'options' => ['aktif' => 'Aktif', 'lulus' => 'Lulus', 'keluar' => 'Keluar'],
                'rules' => ['required', Rule::in(['aktif', 'lulus', 'keluar'])]],
            ['name' => 'kelas_id', 'label' => 'Kelas (tahun ajaran aktif)', 'type' => 'select', 'kosong' => 'Belum ada kelas',
                'options' => Kelas::where('tahun_ajaran_id', $this->tahunAktif())->orderBy('nama')->pluck('nama', 'id')->all(),
                'nilai' => $kelasAktif,
                'rules' => ['nullable', Rule::exists('kelas', 'id')->where('tahun_ajaran_id', $this->tahunAktif())]],
            ['name' => 'password', 'label' => $m ? 'Kata sandi baru' : 'Kata sandi', 'type' => 'password', 'nilai' => '',
                'bantuan' => $m ? 'Kosongkan bila tidak diganti.' : 'Kosongkan agar dibuatkan otomatis (ditampilkan sekali setelah disimpan).',
                'rules' => ['nullable', 'string', 'min:8', 'max:100']],
        ];
    }

    public function siapkan(array $data, ?Model $m): array
    {
        $this->sandiBaru = null;

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
            if (! $m) {
                $this->sandiBaru = KataSandi::acak();
                $data['password'] = $this->sandiBaru;
            }
        }

        return $data;
    }

    public function sesudahSimpan(Model $m, array $data, bool $baru): ?string
    {
        $tahun = $this->tahunAktif();
        if ($tahun) {
            $idKelasTahunIni = Kelas::where('tahun_ajaran_id', $tahun)->pluck('id');
            $m->kelas()->detach($idKelasTahunIni);
            if (! empty($data['kelas_id'])) {
                $m->kelas()->attach($data['kelas_id'], ['status' => 'aktif']);
            }
        }

        return $this->sandiBaru ? "Kata sandi sementara untuk {$m->nama}: {$this->sandiBaru} (hanya tampil sekali, catat sekarang)." : null;
    }

    private function kueriPenempatan()
    {
        $denah = Denah::where('utama', true)->value('id');

        return DenahPenempatan::query()->where('denah_id', $denah ?? 0);
    }

    /** @return array<int,string> */
    private function ruangSiswa(): array
    {
        return $this->petaRuang ??= $this->kueriPenempatan()->with('ruang:id,nama')->get()
            ->mapWithKeys(fn ($p) => [$p->siswa_id => "{$p->ruang->nama} · meja {$p->meja}"])->all();
    }

    private function tahunAktif(): ?int
    {
        if (! $this->tahunDimuat) {
            $this->idTahunAktif = TahunAjaran::where('aktif', true)->value('id');
            $this->tahunDimuat = true;
        }

        return $this->idTahunAktif;
    }
}
