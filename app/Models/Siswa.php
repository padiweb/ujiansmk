<?php

namespace App\Models;

use Database\Factories\SiswaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Siswa extends Authenticatable
{
    /** @use HasFactory<SiswaFactory> */
    use HasFactory;

    protected $table = 'siswa';

    protected $fillable = [
        'eduweb_id', 'nisn', 'username', 'eduweb_hash_sandi', 'nis', 'nama', 'jenis_kelamin', 'tempat_lahir',
        'tgl_lahir', 'nama_wali', 'no_peserta_us', 'status', 'password', 'foto_eduweb',
    ];

    protected $hidden = ['password', 'eduweb_hash_sandi', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'tgl_lahir' => 'date',
        ];
    }

    public function kelas(): BelongsToMany
    {
        return $this->belongsToMany(Kelas::class, 'keanggotaan_kelas')->withPivot('status')->withTimestamps();
    }

    public function aktif(): bool
    {
        return $this->status === 'aktif';
    }
    /** Foto siswa dari EduWeb (alamat penuh), atau null bila belum ada. */
    public function fotoUrl(): ?string
    {
        return \App\Support\FotoEduweb::url($this->foto_eduweb);
    }
}
