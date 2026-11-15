<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hasil ujian CBT dari aplikasi lama; arsip hanya baca. */
class HasilUjianLama extends Model
{
    protected $table = 'hasil_ujian_lama';

    protected $fillable = ['id_lama', 'siswa_id', 'periode_id', 'mapel_nama', 'kelas_nama', 'benar', 'salah', 'skor_pg', 'skor_esai', 'skor_akhir', 'sudah_dikoreksi', 'selesai_pada'];

    protected function casts(): array
    {
        return ['sudah_dikoreksi' => 'boolean', 'selesai_pada' => 'datetime'];
    }

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }
}
