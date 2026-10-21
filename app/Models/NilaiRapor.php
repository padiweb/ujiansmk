<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NilaiRapor extends Model
{
    protected $table = 'nilai_rapor';

    protected $fillable = [
        'siswa_id', 'mapel_id', 'periode_id', 'kelas_id', 'guru_id',
        'ujian', 'ujian_sumber', 'tugas', 'tugas_sumber', 'harian', 'tugas_akhir', 'praktik', 'adab', 'catatan_guru',
        'np', 'nk', 'na', 'predikat', 'status', 'snapshot',
    ];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }

    public function mapel(): BelongsTo
    {
        return $this->belongsTo(Mapel::class);
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }

    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
    }

    public function final(): bool
    {
        return $this->status === 'final';
    }
}
