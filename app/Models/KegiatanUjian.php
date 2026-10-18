<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Nama kegiatan ujian pada satu semester, mis. "Asesmen Semester Gasal". Jadwal per mapel dibuat di bawahnya. */
class KegiatanUjian extends Model
{
    protected $table = 'kegiatan_ujian';

    protected $fillable = ['periode_id', 'nama', 'aktif'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }

    public function jadwal(): HasMany
    {
        return $this->hasMany(JadwalUjian::class, 'kegiatan_id');
    }

    /** Nama jadwal yang tampil di semua halaman: kegiatan + mata pelajaran. */
    public function namaJadwal(Mapel $mapel): string
    {
        return $this->nama.' – '.$mapel->nama;
    }
}
