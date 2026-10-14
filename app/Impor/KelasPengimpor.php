<?php

namespace App\Impor;

use App\Models\Jurusan;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Validation\Rule;

class KelasPengimpor extends Pengimpor
{
    private ?int $tahun = null;

    /** @var array<string,int> */
    private array $jurusan = [];

    /** @var array<string,int> */
    private array $guru = [];

    public function slug(): string
    {
        return 'kelas';
    }

    public function judul(): string
    {
        return 'Kelas';
    }

    public function kolom(): array
    {
        return [
            ['header' => 'Nama Kelas', 'key' => 'nama', 'wajib' => true, 'contoh' => 'X TKJ 1'],
            ['header' => 'Tingkat', 'key' => 'tingkat', 'wajib' => true, 'contoh' => 'X'],
            ['header' => 'Kode Jurusan', 'key' => 'jurusan', 'wajib' => false, 'contoh' => 'TKJ'],
            ['header' => 'Username Wali Kelas', 'key' => 'wali', 'wajib' => false, 'contoh' => 'siti.aminah'],
        ];
    }

    public function petunjuk(): array
    {
        return [
            'Kelas dibuat pada tahun ajaran yang sedang aktif.',
            'Tingkat: X, XI, atau XII.',
            'Kode Jurusan dan Username Wali Kelas harus sudah ada (impor jurusan dan guru terlebih dahulu).',
        ];
    }

    protected function siapkan(): void
    {
        $this->tahun = TahunAjaran::where('aktif', true)->value('id');
        $this->jurusan = Jurusan::pluck('id', 'kode')->mapWithKeys(fn ($id, $k) => [mb_strtolower($k) => $id])->all();
        $this->guru = User::where('role', User::GURU)->pluck('id', 'username')->mapWithKeys(fn ($id, $u) => [mb_strtolower($u) => $id])->all();
    }

    protected function aturan(array $b): array
    {
        return [
            'nama' => ['required', 'string', 'max:30'],
            'tingkat' => ['required', Rule::in(Kelas::TINGKAT)],
            'jurusan' => ['nullable', 'string', 'max:20'],
            'wali' => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function kunciBaris(array $b): ?string
    {
        return mb_strtolower($b['nama']);
    }

    protected function terapkan(array $b): string
    {
        if (! $this->tahun) {
            throw new GalatBaris('Belum ada tahun ajaran aktif. Buat dan aktifkan tahun ajaran terlebih dahulu.');
        }

        $idJurusan = null;
        if (filled($b['jurusan'] ?? null)) {
            $idJurusan = $this->jurusan[mb_strtolower($b['jurusan'])]
                ?? throw new GalatBaris("Kode jurusan \"{$b['jurusan']}\" tidak ditemukan.");
        }

        $idWali = null;
        if (filled($b['wali'] ?? null)) {
            $idWali = $this->guru[mb_strtolower($b['wali'])]
                ?? throw new GalatBaris("Wali kelas \"{$b['wali']}\" tidak ditemukan sebagai guru.");
        }

        $kelas = Kelas::firstOrNew(['tahun_ajaran_id' => $this->tahun, 'nama' => $b['nama']]);
        $baru = ! $kelas->exists;
        $kelas->fill([
            'tingkat' => $b['tingkat'],
            'jurusan_id' => $idJurusan ?? $kelas->jurusan_id,
            'wali_kelas_id' => $idWali ?? $kelas->wali_kelas_id,
        ])->save();

        return $baru ? 'baru' : 'diperbarui';
    }
}
