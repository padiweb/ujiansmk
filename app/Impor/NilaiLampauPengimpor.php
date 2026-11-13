<?php

namespace App\Impor;

use App\Models\Mapel;
use App\Models\NilaiRapor;
use App\Models\Periode;
use App\Models\Siswa;
use App\Services\LayananNilaiLampau;

/** Impor nilai rapor semester lama (NP dan NK per siswa, semester, dan mapel). Hanya semester bertanda "rapor tidak diisi di sini". */
class NilaiLampauPengimpor extends Pengimpor
{
    /** @var array<string,int> */
    private array $siswa = [];

    /** @var array<string,int> */
    private array $mapel = [];

    /** @var array<string,int> */
    private array $periode = [];

    public function slug(): string
    {
        return 'nilai-lampau';
    }

    public function judul(): string
    {
        return 'Nilai Lampau';
    }

    public function kolom(): array
    {
        return [
            ['header' => 'NISN', 'key' => 'nisn', 'wajib' => true, 'contoh' => '0012345678'],
            ['header' => 'Tahun Ajaran', 'key' => 'tahun', 'wajib' => true, 'contoh' => '2023/2024'],
            ['header' => 'Semester', 'key' => 'semester', 'wajib' => true, 'contoh' => 'Ganjil'],
            ['header' => 'Kode Mapel', 'key' => 'mapel', 'wajib' => true, 'contoh' => 'MTK'],
            ['header' => 'NP', 'key' => 'np', 'wajib' => false, 'contoh' => '85'],
            ['header' => 'NK', 'key' => 'nk', 'wajib' => false, 'contoh' => '80'],
            ['header' => 'Nama Kelas Saat Itu', 'key' => 'kelas_lama', 'wajib' => false, 'contoh' => 'X TKJ 1'],
        ];
    }

    public function petunjuk(): array
    {
        return [
            'Siswa, mapel, tahun ajaran, dan semester harus sudah ada. Semester harus sudah ditandai "rapor tidak diisi di sini" di Pengaturan Rapor.',
            'Isi NP dan/atau NK (0–100). Nilai yang sudah ada untuk siswa, semester, dan mapel yang sama akan ditimpa.',
            'Nama Kelas Saat Itu hanya teks untuk transkrip (boleh kosong).',
        ];
    }

    protected function siapkan(): void
    {
        $this->siswa = Siswa::pluck('id', 'nisn')->all();
        $this->mapel = Mapel::pluck('id', 'kode')->mapWithKeys(fn ($id, $k) => [mb_strtolower($k) => $id])->all();
        $this->periode = Periode::with('tahunAjaran')->where('tipe', 'reguler')->where('rapor_di_sini', false)->get()
            ->mapWithKeys(fn (Periode $p) => [mb_strtolower($p->tahunAjaran->nama.'|'.$p->semester) => $p->id])->all();
    }

    protected function aturan(array $b): array
    {
        return [
            'nisn' => ['required', 'string', 'max:20'], 'tahun' => ['required', 'string', 'max:20'],
            'semester' => ['required', 'in:ganjil,genap,Ganjil,Genap,GANJIL,GENAP'], 'mapel' => ['required', 'string', 'max:20'],
            'np' => ['nullable', 'numeric', 'between:0,100'], 'nk' => ['nullable', 'numeric', 'between:0,100'], 'kelas_lama' => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function kunciBaris(array $b): ?string
    {
        return mb_strtolower("{$b['nisn']}|{$b['tahun']}|{$b['semester']}|{$b['mapel']}");
    }

    protected function terapkan(array $b): string
    {
        if (($b['np'] ?? null) === null && ($b['nk'] ?? null) === null) {
            throw new GalatBaris('Isi NP atau NK.');
        }

        $siswaId = $this->siswa[$b['nisn']] ?? throw new GalatBaris("NISN \"{$b['nisn']}\" tidak ditemukan.");
        $mapelId = $this->mapel[mb_strtolower($b['mapel'])] ?? throw new GalatBaris("Kode mapel \"{$b['mapel']}\" tidak ditemukan.");
        $periodeId = $this->periode[mb_strtolower($b['tahun'].'|'.$b['semester'])]
            ?? throw new GalatBaris("Semester {$b['semester']} {$b['tahun']} tidak ada atau rapornya diisi di aplikasi ini (bukan semester lampau).");

        $siswa = Siswa::with('kelas')->find($siswaId);
        $kelas = $siswa->kelas->sortByDesc(fn ($k) => $k->tahun_ajaran_id.'-'.$k->id)->first()
            ?? throw new GalatBaris("Siswa \"{$siswa->nama}\" belum terdaftar di kelas mana pun.");
        $mapel = Mapel::find($mapelId);
        $periode = Periode::find($periodeId);

        $ada = NilaiRapor::where('siswa_id', $siswaId)->where('mapel_id', $mapelId)->where('periode_id', $periodeId)->exists();
        $h = app(LayananNilaiLampau::class)->simpan(
            auth('web')->user(), $mapel, $kelas, $periode, [$siswaId => ['np' => $b['np'], 'nk' => $b['nk']]], $b['kelas_lama'] ?: null, false,
        );
        if ($h['disimpan'] !== 1) {
            throw new GalatBaris("Siswa \"{$siswa->nama}\" tidak termasuk kelas {$kelas->nama} saat ini sehingga nilainya tidak bisa disimpan.");
        }

        return $ada ? 'diperbarui' : 'baru';
    }
}
