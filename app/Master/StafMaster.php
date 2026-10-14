<?php

namespace App\Master;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StafMaster extends DefinisiMaster
{
    public function slug(): string
    {
        return 'staf';
    }

    public function judul(): string
    {
        return 'Pengguna Staf';
    }

    public function model(): string
    {
        return User::class;
    }

    public function urut(): string
    {
        return 'nama';
    }

    public function cari(): array
    {
        return ['nama', 'username'];
    }

    /** Akun staf punya kata sandi lokal, jadi tidak ikut dikunci mode sinkron. */
    public function dikunciSinkron(): bool
    {
        return false;
    }

    public function kolom(): array
    {
        return [
            ['username', 'Nama pengguna'],
            ['nama', 'Nama'],
            ['role', 'Peran', fn (User $u) => ucfirst($u->role)],
            ['aktif', 'Status', fn (User $u) => $u->aktif ? 'Aktif' : 'Nonaktif'],
            ['login_terakhir', 'Login terakhir', fn (User $u) => $u->login_terakhir?->format('d/m/Y H:i') ?? '—'],
        ];
    }

    public function filter(): array
    {
        return [[
            'name' => 'role',
            'label' => 'Semua peran',
            'options' => ['admin' => 'Admin', 'guru' => 'Guru', 'pengawas' => 'Pengawas'],
            'terapkan' => fn ($q, $v) => $q->where('role', $v),
        ]];
    }

    public function bidang(?Model $m): array
    {
        return [
            ['name' => 'nama', 'label' => 'Nama lengkap', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
            ['name' => 'username', 'label' => 'Nama pengguna', 'type' => 'text',
                'bantuan' => 'Huruf, angka, titik, strip, dan garis bawah.',
                'rules' => ['required', 'regex:'.User::POLA_USERNAME, 'max:50', Rule::unique('users', 'username')->ignore($m?->id)]],
            ['name' => 'role', 'label' => 'Peran', 'type' => 'select',
                'options' => ['admin' => 'Admin', 'guru' => 'Guru', 'pengawas' => 'Pengawas'],
                'rules' => ['required', Rule::in(User::PERAN)]],
            ['name' => 'aktif', 'label' => 'Status akun', 'type' => 'select', 'nilai' => $m ? (int) $m->aktif : 1,
                'options' => ['1' => 'Aktif', '0' => 'Nonaktif'], 'rules' => ['required', 'boolean']],
            ['name' => 'password', 'label' => $m ? 'Kata sandi baru' : 'Kata sandi', 'type' => 'password', 'nilai' => '',
                'bantuan' => $m ? 'Kosongkan bila tidak diganti.' : 'Minimal 10 karakter.',
                'rules' => [$m ? 'nullable' : 'required', 'string', 'min:10', 'max:100']],
        ];
    }

    public function siapkan(array $data, ?Model $m): array
    {
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        // Cegah admin mengunci dirinya sendiri atau menghapus admin terakhir.
        if ($m && $m->is(auth('web')->user())) {
            if (($data['role'] ?? $m->role) !== User::ADMIN || ! (bool) ($data['aktif'] ?? true)) {
                throw ValidationException::withMessages(['role' => 'Anda tidak bisa menurunkan atau menonaktifkan akun Anda sendiri.']);
            }
        }

        if ($m && $m->role === User::ADMIN && ($data['role'] !== User::ADMIN || ! (bool) $data['aktif'])
            && User::where('role', User::ADMIN)->where('aktif', true)->where('id', '!=', $m->id)->doesntExist()) {
            throw ValidationException::withMessages(['role' => 'Harus tersisa minimal satu admin aktif.']);
        }

        return $data;
    }

    public function tolakHapus(Model $m): ?string
    {
        if ($m->is(auth('web')->user())) {
            return 'Anda tidak bisa menghapus akun Anda sendiri.';
        }

        if ($m->role === User::ADMIN && User::where('role', User::ADMIN)->where('aktif', true)->where('id', '!=', $m->id)->doesntExist()) {
            return 'Harus tersisa minimal satu admin aktif.';
        }

        return null;
    }
}
