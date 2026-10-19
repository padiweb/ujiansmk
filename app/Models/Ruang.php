<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ruang extends Model
{
    protected $table = 'ruang';

    protected $fillable = ['nama', 'kapasitas', 'campur'];

    protected function casts(): array
    {
        return ['campur' => 'boolean'];
    }

    /** Batas siswa dari satu tingkat di ruang campur: 50% kapasitas. */
    public function porsiTingkat(?int $kapasitas = null): int
    {
        return (int) floor(($kapasitas ?? $this->kapasitas) / 2);
    }
}
