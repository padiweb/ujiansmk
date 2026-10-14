<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TahunAjaran extends Model
{
    protected $table = 'tahun_ajaran';

    protected $fillable = ['eduweb_id', 'nama', 'aktif'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    public function periode(): HasMany
    {
        return $this->hasMany(Periode::class);
    }

    public function kelas(): HasMany
    {
        return $this->hasMany(Kelas::class);
    }

    public static function aktif(): ?self
    {
        return static::where('aktif', true)->first();
    }

    /** Hanya satu tahun ajaran boleh aktif. */
    public function jadikanAktif(): void
    {
        static::where('id', '!=', $this->id)->update(['aktif' => false]);
        $this->update(['aktif' => true]);
    }
}
