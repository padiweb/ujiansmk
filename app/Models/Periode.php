<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Periode extends Model
{
    protected $table = 'periode';

    public const SEMESTER = ['ganjil', 'genap'];
    public const TIPE = ['reguler', 'ujian_sekolah'];

    protected $fillable = ['tahun_ajaran_id', 'semester', 'tipe', 'aktif'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function label(): string
    {
        $tipe = $this->tipe === 'ujian_sekolah' ? ' (Ujian Sekolah)' : '';

        return ucfirst($this->semester).' '.$this->tahunAjaran?->nama.$tipe;
    }

    public function jadikanAktif(): void
    {
        static::where('id', '!=', $this->id)->update(['aktif' => false]);
        $this->update(['aktif' => true]);
    }
}
