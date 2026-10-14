<?php

namespace App\Impor;

use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Pengampu;
use App\Models\TahunAjaran;
use App\Models\User;

class PengampuPengimpor extends Pengimpor
{
    private ?int $tahun = null;

    /** @var array<string,int> */
    private array $guru = [];

    /** @var array<string,int> */
    private array $mapel = [];

    /** @var array<string,int> */
    private array $kelas = [];

    public function slug(): string
    {
        return 'pengampu';
    }

    public function judul(): string
    {
        return 'Guru Pengampu';
    }

    public function kolom(): array
    {
        return [
            ['header' => 'Username Guru', 'key' => 'guru', 'wajib' => true, 'contoh' => 'siti.aminah'],
            ['header' => 'Kode Mapel', 'key' => 'mapel', 'wajib' => true, 'contoh' => 'MTK'],
            ['header' => 'Nama Kelas', 'key' => 'kelas', 'wajib' => true, 'contoh' => 'X TKJ 1'],
        ];
    }

    public function petunjuk(): array
    {
        return [
            'Guru, mapel, dan kelas harus sudah ada (impor terlebih dahulu).',
            'Kelas dicari pada tahun ajaran aktif. Pasangan yang sudah ada dilewati.',
        ];
    }

    protected function siapkan(): void
    {
        $this->tahun = TahunAjaran::where('aktif', true)->value('id');
        $kecil = fn ($koleksi) => $koleksi->mapWithKeys(fn ($id, $k) => [mb_strtolower($k) => $id])->all();
        $this->guru = $kecil(User::where('role', User::GURU)->pluck('id', 'username'));
        $this->mapel = $kecil(Mapel::pluck('id', 'kode'));
        $this->kelas = $this->tahun ? $kecil(Kelas::where('tahun_ajaran_id', $this->tahun)->pluck('id', 'nama')) : [];
    }

    protected function aturan(array $b): array
    {
        return [
            'guru' => ['required', 'string', 'max:50'],
            'mapel' => ['required', 'string', 'max:20'],
            'kelas' => ['required', 'string', 'max:30'],
        ];
    }

    protected function kunciBaris(array $b): ?string
    {
        return mb_strtolower("{$b['guru']}|{$b['mapel']}|{$b['kelas']}");
    }

    protected function terapkan(array $b): string
    {
        if (! $this->tahun) {
            throw new GalatBaris('Belum ada tahun ajaran aktif.');
        }

        $guru = $this->guru[mb_strtolower($b['guru'])] ?? throw new GalatBaris("Guru \"{$b['guru']}\" tidak ditemukan.");
        $mapel = $this->mapel[mb_strtolower($b['mapel'])] ?? throw new GalatBaris("Kode mapel \"{$b['mapel']}\" tidak ditemukan.");
        $kelas = $this->kelas[mb_strtolower($b['kelas'])] ?? throw new GalatBaris("Kelas \"{$b['kelas']}\" tidak ditemukan pada tahun ajaran aktif.");

        $pengampu = Pengampu::firstOrCreate(['guru_id' => $guru, 'mapel_id' => $mapel, 'kelas_id' => $kelas]);

        return $pengampu->wasRecentlyCreated ? 'baru' : 'diperbarui';
    }
}
