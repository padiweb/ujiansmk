<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DenahRuang extends Model
{
    protected $table = 'denah_ruang';

    protected $fillable = ['denah_id', 'ruang_id', 'jumlah_meja', 'siswa_per_meja', 'kapasitas'];

    public function ruang(): BelongsTo
    {
        return $this->belongsTo(Ruang::class);
    }

    public function denah(): BelongsTo
    {
        return $this->belongsTo(Denah::class, 'denah_id');
    }

    /** Jumlah siswa maksimal di ruang ini. */
    public function kapasitas(): int
    {
        return (int) ($this->attributes['kapasitas'] ?? null ?: $this->jumlah_meja * $this->siswa_per_meja);
    }

    /** Meja dan posisi untuk urutan ke-$i (mulai 0) menurut siswa per meja ruang ini. @return array{0:int,1:int} */
    public function tempatKe(int $i): array
    {
        $per = max(1, (int) $this->siswa_per_meja);

        return [intdiv($i, $per) + 1, $i % $per + 1];
    }
}
