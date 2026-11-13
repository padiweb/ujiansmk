<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Soal extends Model
{
    use SoftDeletes;

    protected $table = 'soal';

    public const PG = 'pg';
    public const ESAI = 'esai';

    protected $fillable = ['bank_soal_id', 'bacaan_id', 'tipe', 'pertanyaan', 'gambar', 'bobot', 'rubrik_esai', 'status', 'sumber', 'urutan'];

    protected function casts(): array
    {
        return ['bobot' => 'decimal:2'];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(BankSoal::class, 'bank_soal_id');
    }

    public function bacaan(): BelongsTo
    {
        return $this->belongsTo(BacaanSoal::class, 'bacaan_id');
    }

    public function opsi(): HasMany
    {
        return $this->hasMany(OpsiSoal::class)->orderBy('label');
    }

    public function kunci(): ?string
    {
        return $this->opsi->firstWhere('benar', true)?->label;
    }
}
