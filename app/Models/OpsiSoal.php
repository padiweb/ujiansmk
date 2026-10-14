<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsiSoal extends Model
{
    public $timestamps = false;

    protected $table = 'opsi_soal';

    protected $fillable = ['soal_id', 'label', 'teks', 'benar'];

    protected function casts(): array
    {
        return ['benar' => 'boolean'];
    }

    public function soal(): BelongsTo
    {
        return $this->belongsTo(Soal::class);
    }
}
