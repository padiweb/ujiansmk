<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DenahRuang extends Model
{
    protected $table = 'denah_ruang';

    protected $fillable = ['denah_id', 'ruang_id', 'jumlah_meja', 'siswa_per_meja'];

    public function ruang(): BelongsTo
    {
        return $this->belongsTo(Ruang::class);
    }

    public function denah(): BelongsTo
    {
        return $this->belongsTo(Denah::class, 'denah_id');
    }

    public function kapasitas(): int
    {
        return $this->jumlah_meja * $this->siswa_per_meja;
    }
}
