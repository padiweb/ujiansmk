<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Denah extends Model
{
    protected $table = 'denah_ujian';

    protected $fillable = ['nama', 'periode_id', 'kegiatan_id', 'dibuat_oleh'];

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }

    public function kegiatan(): BelongsTo
    {
        return $this->belongsTo(KegiatanUjian::class, 'kegiatan_id');
    }

    public function ruang(): HasMany
    {
        return $this->hasMany(DenahRuang::class, 'denah_id')->orderBy('id');
    }

    public function penempatan(): HasMany
    {
        return $this->hasMany(DenahPenempatan::class, 'denah_id');
    }

    public function jadwal(): HasMany
    {
        return $this->hasMany(JadwalUjian::class, 'denah_id');
    }
}
