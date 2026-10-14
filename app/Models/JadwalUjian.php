<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JadwalUjian extends Model
{
    protected $table = 'jadwal_ujian';

    public const DRAF = 'draf';
    public const AKTIF = 'aktif';
    public const SELESAI = 'selesai';

    protected $fillable = [
        'periode_id', 'mapel_id', 'nama', 'durasi_menit', 'mulai_pada', 'tutup_pada', 'token', 'media_kode', 'status',
        'acak_soal', 'acak_opsi', 'batas_pelanggaran', 'bobot_pg', 'tampilkan_hasil', 'dibuat_oleh', 'diaktifkan_pada', 'nilai_dikunci_pada', 'nilai_dikunci_oleh', 'denah_id',
    ];

    protected function casts(): array
    {
        return [
            'mulai_pada' => 'datetime',
            'tutup_pada' => 'datetime',
            'diaktifkan_pada' => 'datetime',
            'nilai_dikunci_pada' => 'datetime',
            'acak_soal' => 'boolean',
            'acak_opsi' => 'boolean',
        ];
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }

    public function mapel(): BelongsTo
    {
        return $this->belongsTo(Mapel::class);
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function denah(): BelongsTo
    {
        return $this->belongsTo(Denah::class, 'denah_id');
    }

    public function pengunci(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nilai_dikunci_oleh');
    }

    public function kelas(): BelongsToMany
    {
        return $this->belongsToMany(Kelas::class, 'jadwal_kelas', 'jadwal_id', 'kelas_id');
    }

    public function bank(): HasMany
    {
        return $this->hasMany(JadwalBank::class, 'jadwal_id');
    }

    public function soalBeku(): HasMany
    {
        return $this->hasMany(JadwalSoal::class, 'jadwal_id')->orderBy('urutan');
    }

    public function pengawas(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'jadwal_pengawas', 'jadwal_id', 'user_id')->withPivot('ruang_id');
    }

    public function sesi(): HasMany
    {
        return $this->hasMany(SesiUjian::class, 'jadwal_id');
    }

    public function berstatus(string ...$status): bool
    {
        return in_array($this->status, $status, true);
    }

    /** Keadaan jendela waktu: belum_dibuka | dibuka | ditutup (dihitung dari jam server). */
    public function jendela(): string
    {
        $sekarang = now();

        return match (true) {
            $sekarang->lt($this->mulai_pada) => 'belum_dibuka',
            $sekarang->gt($this->tutup_pada) => 'ditutup',
            default => 'dibuka',
        };
    }

    public function nilaiDikunci(): bool
    {
        return $this->nilai_dikunci_pada !== null;
    }

    public function bobotEsai(): int
    {
        return 100 - (int) $this->bobot_pg;
    }
}
