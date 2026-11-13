<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PengaturanSekolah extends Model
{
    protected $table = 'pengaturan_sekolah';

    protected $fillable = [
        'nama', 'nama_induk', 'alamat', 'kota', 'provinsi', 'telepon', 'website', 'email', 'npsn', 'kepala_sekolah', 'nip_kepala_sekolah',
        'kurikulum', 'bobot_ujian', 'bobot_harian', 'bobot_tugas_akhir', 'bobot_praktik', 'bobot_adab', 'bobot_np', 'bobot_nk', 'mode_sinkron_eduweb', 'eduweb_kejadian_terakhir', 'eduweb_sinkron_penuh_pada',
        'eduweb_cek_terakhir', 'eduweb_webhook_terakhir', 'eduweb_kejadian_tertahan', 'eduweb_kredensial',
    ];

    protected function casts(): array
    {
        return [
            'mode_sinkron_eduweb' => 'boolean', 'eduweb_sinkron_penuh_pada' => 'datetime', 'eduweb_cek_terakhir' => 'datetime',
            'eduweb_webhook_terakhir' => 'datetime', 'eduweb_kejadian_tertahan' => 'boolean',
        ];
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
