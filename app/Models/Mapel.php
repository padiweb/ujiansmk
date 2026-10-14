<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mapel extends Model
{
    protected $table = 'mapel';

    public const KELOMPOK = ['A', 'B', 'C1', 'C2', 'C3', 'ML'];

    protected $fillable = ['eduweb_id', 'kode', 'nama', 'nama_skl', 'kelompok', 'kkm'];
}
