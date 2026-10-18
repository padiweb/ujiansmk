<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kunci nilai satu jadwal untuk satu kelas. Ada baris = nilai kelas itu terkunci. */
class KunciNilai extends Model
{
    protected $table = 'kunci_nilai';

    public $timestamps = false;

    protected $fillable = ['jadwal_id', 'kelas_id', 'dikunci_pada', 'dikunci_oleh'];

    protected function casts(): array
    {
        return ['dikunci_pada' => 'datetime'];
    }

    public function pengunci(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dikunci_oleh');
    }
}
