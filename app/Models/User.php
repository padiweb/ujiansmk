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

    protected $fillable = ['eduweb_id', 'nama', 'username', 'foto', 'foto_eduweb', 'password', 'eduweb_hash_sandi', 'role', 'aktif', 'bisa_mengawasi', 'login_terakhir'];

    protected $hidden = ['password', 'eduweb_hash_sandi', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'aktif' => 'boolean',
            'bisa_mengawasi' => 'boolean',
            'login_terakhir' => 'datetime',
        ];
    }

    /** Boleh mengawasi semua ruang: akun pengawas, atau guru yang ditandai admin sebagai pengawas. */
    public function mengawasi(): bool
    {
        return $this->aktif && ($this->role === self::PENGAWAS || ($this->role === self::GURU && $this->bisa_mengawasi));
    }

    public function punyaPeran(string ...$peran): bool
    {
        return in_array($this->role, $peran, true);
    }

    /**
     * Alamat foto profil untuk tampilan: foto yang diunggah sendiri lebih diutamakan, lalu foto dari EduWeb; null bila tidak ada.
     * Foto unggahan disajikan lewat rute terautentikasi, foto EduWeb langsung dari server EduWeb.
     */
    public function fotoUrl(): ?string
    {
        if ($this->foto) {
            return route('staf.profil.foto', ['v' => substr(md5($this->foto), 0, 8)]);
        }

        return \App\Support\FotoEduweb::url($this->foto_eduweb);
    }

    /** Apakah foto yang tampil berasal dari EduWeb (bukan unggahan sendiri). */
    public function fotoDariEduweb(): bool
    {
        return ! $this->foto && \App\Support\FotoEduweb::url($this->foto_eduweb) !== null;
    }
}
