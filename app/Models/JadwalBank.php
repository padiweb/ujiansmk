<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JadwalBank extends Model
{
    public $timestamps = false;

    protected $table = 'jadwal_bank';

    protected $fillable = ['jadwal_id', 'bank_soal_id', 'jumlah_pg', 'jumlah_esai', 'kelas_ids'];

    protected function casts(): array
    {
        return ['kelas_ids' => 'array'];
    }

    public function bankSoal(): BelongsTo
    {
        return $this->belongsTo(BankSoal::class)->withTrashed();
    }
}
