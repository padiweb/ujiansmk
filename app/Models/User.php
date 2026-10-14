<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public const ADMIN = 'admin';
    public const GURU = 'guru';
    public const PENGAWAS = 'pengawas';

    public const PERAN = [self::ADMIN, self::GURU, self::PENGAWAS];

    /** Huruf, angka, titik, strip, dan garis bawah (mis. siti.aminah). */
    public const POLA_USERNAME = '/^[A-Za-z0-9._-]+$/';

    protected $table = 'users';

    protected $fillable = ['eduweb_id', 'nama', 'username', 'password', 'role', 'aktif', 'login_terakhir'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'aktif' => 'boolean',
            'login_terakhir' => 'datetime',
        ];
    }

    public function punyaPeran(string ...$peran): bool
    {
        return in_array($this->role, $peran, true);
    }
}
