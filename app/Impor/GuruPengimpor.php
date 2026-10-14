<?php

namespace App\Impor;

use App\Models\User;
use App\Support\KataSandi;
use Illuminate\Validation\Rule;

class GuruPengimpor extends Pengimpor
{
    public function slug(): string
    {
        return 'staf';
    }

    public function judul(): string
    {
        return 'Guru dan Pengawas';
    }

    public function kolom(): array
    {
        return [
            ['header' => 'Nama', 'key' => 'nama', 'wajib' => true, 'contoh' => 'Siti Aminah, S.Pd.'],
            ['header' => 'Username', 'key' => 'username', 'wajib' => true, 'contoh' => 'siti.aminah'],
            ['header' => 'Peran', 'key' => 'peran', 'wajib' => false, 'contoh' => 'guru'],
            ['header' => 'Kata Sandi', 'key' => 'kata_sandi', 'wajib' => false, 'contoh' => ''],
        ];
    }

    public function petunjuk(): array
    {
        return [
            'Peran: guru atau pengawas (kosong = guru). Akun admin tidak dapat dibuat lewat impor.',
            'Username yang sudah ada akan diperbarui (nama dan peran).',
            'Kata sandi kosong: akun baru dibuatkan kata sandi acak (minimal 10 karakter).',
        ];
    }

    protected function aturan(array $b): array
    {
        return [
            'nama' => ['required', 'string', 'max:100'],
            'username' => ['required', 'regex:'.User::POLA_USERNAME, 'max:50'],
            'peran' => ['nullable', Rule::in(['guru', 'pengawas'])],
            'kata_sandi' => ['nullable', 'string', 'min:10', 'max:100'],
        ];
    }

    protected function kunciBaris(array $b): ?string
    {
        return mb_strtolower($b['username']);
    }

    protected function terapkan(array $b): string
    {
        $user = User::where('username', $b['username'])->first();
        $peran = $b['peran'] ?? User::GURU;

        if ($user && $user->role === User::ADMIN) {
            throw new GalatBaris("Username \"{$b['username']}\" adalah akun admin dan tidak bisa diubah lewat impor.");
        }

        if (! $user) {
            $sandi = $b['kata_sandi'] ?? KataSandi::acak(12);
            $user = new User(['nama' => $b['nama'], 'username' => $b['username'], 'role' => $peran, 'aktif' => true]);
            $user->password = $this->sandiUntukDisimpan($sandi);
            $user->save();
            if (blank($b['kata_sandi'] ?? null)) {
                $this->tambahSandi($b['username'], $b['nama'], $sandi);
            }

            return 'baru';
        }

        $user->fill(['nama' => $b['nama'], 'role' => $peran]);
        if (filled($b['kata_sandi'] ?? null)) {
            $user->password = $this->sandiUntukDisimpan($b['kata_sandi']);
        }
        $user->save();

        return 'diperbarui';
    }
}
