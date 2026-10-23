<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Kunci akses API baca-saja rapor. Kunci asli hanya terlihat sekali saat dibuat; yang disimpan hash-nya. */
class KlienApi extends Model
{
    protected $table = 'klien_api';

    protected $fillable = ['nama', 'awalan', 'hash', 'terakhir_dipakai', 'ip_terakhir', 'dicabut_pada', 'dibuat_oleh'];

    protected $hidden = ['hash'];

    protected function casts(): array
    {
        return ['terakhir_dipakai' => 'datetime', 'dicabut_pada' => 'datetime'];
    }

    public static function hash(string $kunci): string
    {
        return hash('sha256', $kunci);
    }

    /** @return array{0:self,1:string} klien dan kunci asli (tampilkan sekali) */
    public static function terbitkan(string $nama, ?int $pembuat): array
    {
        $kunci = 'rpr_'.Str::random(40);
        $klien = static::create(['nama' => $nama, 'awalan' => substr($kunci, 0, 8), 'hash' => static::hash($kunci), 'dibuat_oleh' => $pembuat]);

        return [$klien, $kunci];
    }

    public function aktif(): bool
    {
        return $this->dicabut_pada === null;
    }
}
