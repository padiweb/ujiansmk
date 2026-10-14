<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Jawaban extends Model
{
    public $timestamps = false;

    protected $table = 'jawaban';

    protected $fillable = [
        'sesi_id', 'soal_id', 'opsi_label', 'teks_esai', 'dijawab_klien', 'diterima_pada', 'terlambat',
        'nilai_esai', 'dikoreksi_oleh', 'dikoreksi_pada',
    ];

    protected function casts(): array
    {
        return [
            'dijawab_klien' => 'datetime',
            'diterima_pada' => 'datetime',
            'dikoreksi_pada' => 'datetime',
            'terlambat' => 'boolean',
            'nilai_esai' => 'decimal:2',
        ];
    }

    public function sesi(): BelongsTo
    {
        return $this->belongsTo(SesiUjian::class, 'sesi_id');
    }

    public function soal(): BelongsTo
    {
        return $this->belongsTo(Soal::class)->withTrashed();
    }
}
