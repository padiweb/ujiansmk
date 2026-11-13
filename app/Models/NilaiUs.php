<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NilaiUs extends Model
{
    protected $table = 'nilai_us';

    protected $fillable = ['siswa_id', 'mapel_id', 'nilai', 'nilai_cbt', 'keterangan', 'masuk_xii2', 'guru_id'];

    protected function casts(): array
    {
        return ['masuk_xii2' => 'boolean'];
    }

    /** Nilai yang dipakai: isian manual bila ada, selain itu hasil CBT. */
    public function dipakai(): ?float
    {
        $v = $this->nilai ?? $this->nilai_cbt;

        return $v === null ? null : (float) $v;
    }

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }

    public function mapel(): BelongsTo
    {
        return $this->belongsTo(Mapel::class);
    }
}
