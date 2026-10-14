<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class BuatPengguna extends Command
{
    protected $signature = 'pengguna:buat {--peran= : admin | guru | pengawas}';

    protected $description = 'Buat akun staf (admin, guru, atau pengawas). Kata sandi diminta saat dijalankan.';

    public function handle(): int
    {
        $peran = $this->option('peran') ?: select('Peran', User::PERAN, User::ADMIN);

        if (! in_array($peran, User::PERAN, true)) {
            $this->error('Peran tidak dikenal. Pilih: '.implode(', ', User::PERAN));

            return self::FAILURE;
        }

        $nama = text('Nama lengkap', required: true);
        $username = text(
            'Nama pengguna',
            required: true,
            validate: fn (string $v) => Validator::make(['u' => $v], ['u' => ['regex:'.User::POLA_USERNAME, 'max:50', 'unique:users,username']])->fails()
                ? 'Hanya huruf, angka, titik, strip, garis bawah; belum dipakai; maks 50 karakter.'
                : null,
        );
        $sandi = password(
            'Kata sandi (minimal 10 karakter)',
            required: true,
            validate: fn (string $v) => mb_strlen($v) < 10 ? 'Minimal 10 karakter.' : null,
        );

        $user = User::create([
            'nama' => $nama,
            'username' => $username,
            'password' => $sandi,
            'role' => $peran,
            'aktif' => true,
        ]);

        AuditLog::catat('staf.dibuat', "Akun {$peran}: {$username} (lewat konsol)");
        $this->info("Akun {$peran} '{$user->username}' berhasil dibuat.");

        return self::SUCCESS;
    }
}
