<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JadwalSoal extends Model
{
    public $timestamps = false;

    protected $table = 'jadwal_soal';

    protected $fillable = ['jadwal_id', 'soal_id', 'urutan', 'bobot', 'untuk_kelas'];

    protected function casts(): array
    {
        return ['bobot' => 'decimal:2', 'untuk_kelas' => 'array'];
    }

    public function soal(): BelongsTo
    {
        return $this->belongsTo(Soal::class)->withTrashed();
    }
}
