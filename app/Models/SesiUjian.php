<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SesiUjian extends Model
{
    protected $table = 'sesi_ujian';

    public const BELUM = 'belum';
    public const BERLANGSUNG = 'berlangsung';
    public const SELESAI = 'selesai';
    public const DIBLOKIR = 'diblokir';

    protected $fillable = [
        'jadwal_id', 'siswa_id', 'ruang_id', 'no_meja', 'status', 'mulai_pada', 'batas_selesai', 'selesai_pada', 'alasan_selesai',
        'jumlah_pelanggaran', 'token_perangkat', 'platform', 'info_peramban', 'ip', 'terakhir_aktif', 'susunan', 'tambahan_menit',
    ];

    protected $hidden = ['token_perangkat'];

    protected function casts(): array
    {
        return [
            'mulai_pada' => 'datetime',
            'batas_selesai' => 'datetime',
            'selesai_pada' => 'datetime',
            'terakhir_aktif' => 'datetime',
            'susunan' => 'array',
        ];
    }

    public function hasil(): HasOne
    {
        return $this->hasOne(HasilUjian::class, 'sesi_id');
    }

    public function jawaban(): HasMany
    {
        return $this->hasMany(Jawaban::class, 'sesi_id');
    }

    public function jadwal(): BelongsTo
    {
        return $this->belongsTo(JadwalUjian::class, 'jadwal_id');
    }

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }

    public function ruang(): BelongsTo
    {
        return $this->belongsTo(Ruang::class);
    }
}
