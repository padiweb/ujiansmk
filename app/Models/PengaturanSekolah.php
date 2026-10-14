<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PengaturanSekolah extends Model
{
    protected $table = 'pengaturan_sekolah';

    protected $fillable = [
        'nama', 'alamat', 'kota', 'provinsi', 'npsn', 'kepala_sekolah', 'nip_kepala_sekolah',
        'kurikulum', 'mode_sinkron_eduweb',
    ];

    protected function casts(): array
    {
        return ['mode_sinkron_eduweb' => 'boolean'];
    }

    /** Pengaturan selalu satu baris; dibuat bila belum ada. */
    public static function ambil(): self
    {
        return static::query()->first() ?? static::create(['nama' => 'SMK Alhikmah Tanon']);
    }

    public static function sinkronAktif(): bool
    {
        return (bool) static::query()->value('mode_sinkron_eduweb');
    }
}
