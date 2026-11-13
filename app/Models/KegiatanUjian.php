<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Nama kegiatan ujian pada satu semester, mis. "Asesmen Semester Gasal". Jadwal per mapel dibuat di bawahnya. */
class KegiatanUjian extends Model
{
    protected $table = 'kegiatan_ujian';

    protected $fillable = ['periode_id', 'nama', 'aktif', 'untuk_rapor'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean', 'untuk_rapor' => 'boolean'];
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }

    public function jadwal(): HasMany
    {
        return $this->hasMany(JadwalUjian::class, 'kegiatan_id');
    }

    public function denah(): HasOne
    {
        return $this->hasOne(Denah::class, 'kegiatan_id');
    }

    /**
     * Peringatan bila namanya menyiratkan Ujian Sekolah (US/USBN/Ujian Sekolah) tetapi semesternya reguler:
     * nilainya bisa ikut masuk rapor semester. Null bila tidak ada masalah.
     */
    public function peringatanUjianSekolah(): ?string
    {
        $periode = $this->periode ?? $this->periode()->first();
        if (! $periode || $periode->tipe !== 'reguler') {
            return null;
        }
        if (! preg_match('/\bujian\s+sekolah\b|\bUSBN\b|\bUS\b/iu', (string) $this->nama)) {
            return null;
        }

        return 'Kegiatan "'.$this->nama.'" tampak seperti Ujian Sekolah, tetapi berada di semester reguler'
            .($this->untuk_rapor ? ' dan bertanda "untuk rapor", sehingga nilainya bisa ikut masuk rapor' : '')
            .'. Untuk Ujian Sekolah, buat kegiatan pada semester Ujian Sekolah (tambahkan di Pengaturan Rapor).';
    }

    /** Nama jadwal yang tampil di semua halaman: kegiatan + mata pelajaran. */
    public function namaJadwal(Mapel $mapel): string
    {
        return $this->nama.' – '.$mapel->nama;
    }
}
