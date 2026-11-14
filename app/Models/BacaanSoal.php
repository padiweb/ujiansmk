<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bacaan/stimulus yang dipakai bersama beberapa soal dalam satu bank. */
class BacaanSoal extends Model
{
    protected $table = 'bacaan_soal';

    protected $fillable = ['bank_soal_id', 'judul', 'teks', 'gambar', 'sumber'];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(BankSoal::class, 'bank_soal_id');
    }

    public function soal(): HasMany
    {
        return $this->hasMany(Soal::class, 'bacaan_id');
    }

    /** Judul untuk daftar: judul bila ada, selain itu awal teks. */
    public function label(): string
    {
        return $this->judul ?: \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', $this->teks)), 60);
    }
}
