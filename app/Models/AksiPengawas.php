<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AksiPengawas extends Model
{
    public $timestamps = false;

    protected $table = 'aksi_pengawas';

    public const AKTIFKAN_KEMBALI = 'aktifkan_kembali';
    public const RESET_PERANGKAT = 'reset_perangkat';
    public const TAMBAH_WAKTU = 'tambah_waktu';
    public const PAKSA_SELESAI = 'paksa_selesai';

    public const LABEL = [
        self::AKTIFKAN_KEMBALI => 'Aktifkan kembali',
        self::RESET_PERANGKAT => 'Reset perangkat',
        self::TAMBAH_WAKTU => 'Tambah waktu',
        self::PAKSA_SELESAI => 'Paksa selesai',
    ];

    protected $fillable = ['sesi_id', 'user_id', 'aksi', 'detail', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function sesi(): BelongsTo
    {
        return $this->belongsTo(SesiUjian::class, 'sesi_id');
    }

    public function pelaku(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
