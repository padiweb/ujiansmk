<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogOtomatis extends Model
{
    public $timestamps = false;

    protected $table = 'log_otomatis';

    protected $fillable = ['user_id', 'penyedia_id', 'bank_soal_id', 'jumlah_diminta', 'jumlah_dihasilkan', 'status', 'pesan', 'created_at'];

    protected static function booted(): void
    {
        // Jam aplikasi (zona waktu sekolah), bukan jam basis data: batas harian dihitung dari awal hari aplikasi.
        static::creating(fn (self $m) => $m->created_at ??= now());
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function penyedia(): BelongsTo
    {
        return $this->belongsTo(PenyediaOtomatis::class, 'penyedia_id');
    }
}
