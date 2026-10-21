<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkalaPredikat extends Model
{
    protected $table = 'skala_predikat';

    protected $fillable = ['nilai_min', 'predikat', 'keterangan'];

    protected function casts(): array
    {
        return ['nilai_min' => 'integer'];
    }
}
