<?php

namespace App\Impor;

use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Support\KataSandi;
use Illuminate\Validation\Rule;

class SiswaPengimpor extends Pengimpor
{
    private ?int $tahun = null;

    /** @var array<string,int> nama kelas (huruf kecil) => id, tahun ajaran aktif */
    private array $kelas = [];

    public function slug(): string
    {
        return 'siswa';
    }

    public function judul(): string
    {
        return 'Siswa';
    }

    public function kolom(): array
    {
        return [
            ['header' => 'NISN', 'key' => 'nisn', 'wajib' => true, 'contoh' => '0012345678'],
            ['header' => 'Nama', 'key' => 'nama', 'wajib' => true, 'contoh' => 'Ahmad Fauzi'],
            ['header' => 'NIS', 'key' => 'nis', 'wajib' => false, 'contoh' => '2425001'],
            ['header' => 'L/P', 'key' => 'jk', 'wajib' => false, 'contoh' => 'L'],
            ['header' => 'Tempat Lahir', 'key' => 'tempat_lahir', 'wajib' => false, 'contoh' => 'Sragen'],
            ['header' => 'Tanggal Lahir', 'key' => 'tgl_lahir', 'wajib' => false, 'contoh' => '31/12/2008'],
            ['header' => 'Nama Wali', 'key' => 'nama_wali', 'wajib' => false, 'contoh' => 'Budi Santoso'],
            ['header' => 'No Peserta US', 'key' => 'no_peserta_us', 'wajib' => false, 'contoh' => ''],
            ['header' => 'Kelas', 'key' => 'kelas', 'wajib' => false, 'contoh' => 'X TKJ 1'],
            ['header' => 'Kata Sandi', 'key' => 'kata_sandi', 'wajib' => false, 'contoh' => ''],
        ];
    }

    public function petunjuk(): array
    {
        return [
            'NISN dipakai siswa sebagai nama pengguna. Siswa yang NISN-nya sudah ada akan diperbarui.',
            'Kelas harus sudah ada di tahun ajaran aktif (isi persis sesuai nama kelas).',
            'Kata Sandi boleh dikosongkan: siswa baru dibuatkan kata sandi acak (daftarnya dapat diunduh sekali setelah impor).',
            'Untuk siswa yang sudah ada, kata sandi kosong berarti tidak diubah.',
            'Format tanggal: 31/12/2008 atau 2008-12-31.',
        ];
    }

    protected function siapkan(): void
    {
        $this->tahun = TahunAjaran::where('aktif', true)->value('id');
        $this->kelas = $this->tahun
            ? Kelas::where('tahun_ajaran_id', $this->tahun)->pluck('id', 'nama')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id])->all()
            : [];
    }

    protected function aturan(array $b): array
    {
        return [
            'nisn' => ['required', 'digits_between:5,20'],
            'nama' => ['required', 'string', 'max:100'],
            'nis' => ['nullable', 'string', 'max:30'],
            'jk' => ['nullable', Rule::in(['L', 'P', 'l', 'p'])],
            'tempat_lahir' => ['nullable', 'string', 'max:60'],
            'nama_wali' => ['nullable', 'string', 'max:100'],
            'no_peserta_us' => ['nullable', 'string', 'max:50'],
            'kata_sandi' => ['nullable', 'string', 'min:8', 'max:100'],
        ];
    }

    protected function kunciBaris(array $b): ?string
    {
        return $b['nisn'];
    }

    protected function terapkan(array $b): string
    {
        $idKelas = null;
        if (filled($b['kelas'] ?? null)) {
            if (! $this->tahun) {
                throw new GalatBaris('Belum ada tahun ajaran aktif, kelas tidak bisa dipasangkan.');
            }
            $idKelas = $this->kelas[mb_strtolower($b['kelas'])]
                ?? throw new GalatBaris("Kelas \"{$b['kelas']}\" tidak ditemukan pada tahun ajaran aktif.");
        }

        $data = array_filter([
            'nama' => $b['nama'],
            'nis' => $b['nis'] ?? null,
            'jenis_kelamin' => isset($b['jk']) ? strtoupper($b['jk']) : null,
            'tempat_lahir' => $b['tempat_lahir'] ?? null,
            'tgl_lahir' => $this->tanggal($b['tgl_lahir'] ?? null),
            'nama_wali' => $b['nama_wali'] ?? null,
            'no_peserta_us' => $b['no_peserta_us'] ?? null,
        ], fn ($v) => $v !== null);

        $siswa = Siswa::where('nisn', $b['nisn'])->first();
        $baru = $siswa === null;

        if ($baru) {
            $sandi = $b['kata_sandi'] ?? KataSandi::acak();
            $siswa = new Siswa(['nisn' => $b['nisn'], 'status' => 'aktif'] + $data);
            $siswa->password = $this->sandiUntukDisimpan($sandi);
            $siswa->save();
            if (blank($b['kata_sandi'] ?? null)) {
                $this->tambahSandi($b['nisn'], $b['nama'], $sandi);
            }
        } else {
            $siswa->fill($data);
            if (filled($b['kata_sandi'] ?? null)) {
                $siswa->password = $this->sandiUntukDisimpan($b['kata_sandi']);
            }
            $siswa->save();
        }

        if ($idKelas) {
            $siswa->kelas()->detach(array_values($this->kelas));
            $siswa->kelas()->attach($idKelas, ['status' => 'aktif']);
        }

        return $baru ? 'baru' : 'diperbarui';
    }
}
