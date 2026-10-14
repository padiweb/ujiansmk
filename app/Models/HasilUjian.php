<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HasilUjian extends Model
{
    protected $table = 'hasil_ujian';

    protected $fillable = [
        'sesi_id', 'benar', 'salah', 'kosong', 'skor_pg', 'skor_esai', 'skor_akhir', 'status_koreksi',
        'mapel_nama', 'kelas_nama', 'jadwal_nama',
    ];

    protected function casts(): array
    {
        return ['skor_pg' => 'decimal:2', 'skor_esai' => 'decimal:2', 'skor_akhir' => 'decimal:2'];
    }

    public function sesi(): BelongsTo
    {
        return $this->belongsTo(SesiUjian::class, 'sesi_id');
    }
}
