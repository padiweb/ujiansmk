<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaporSiswa extends Model
{
    protected $table = 'rapor_siswa';

    protected $fillable = [
        'siswa_id', 'periode_id', 'kelas_id', 'kelas_nama', 'sakit', 'izin', 'alpa', 'sumber_absensi',
        'catatan_wali', 'keputusan', 'status', 'difinalkan_oleh', 'difinalkan_pada',
    ];

    protected function casts(): array
    {
        return ['difinalkan_pada' => 'datetime'];
    }

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }

    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
    }
}
