<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DenahPenempatan extends Model
{
    public $timestamps = false;

    protected $table = 'denah_penempatan';

    protected $fillable = ['denah_id', 'siswa_id', 'ruang_id', 'meja', 'posisi'];

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }

    public function ruang(): BelongsTo
    {
        return $this->belongsTo(Ruang::class);
    }
}
