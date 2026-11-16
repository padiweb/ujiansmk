<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankSoal extends Model
{
    use SoftDeletes;

    protected $table = 'bank_soal';

    protected $fillable = ['mapel_id', 'tingkat', 'kegiatan_id', 'nama', 'jumlah_opsi_bawaan', 'dibuat_oleh'];

    public function mapel(): BelongsTo
    {
        return $this->belongsTo(Mapel::class);
    }

    /** Kegiatan ujian tujuan bank ini; kosong = bank umum/lama. */
    public function kegiatan(): BelongsTo
    {
        return $this->belongsTo(KegiatanUjian::class, 'kegiatan_id');
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function bacaan(): HasMany
    {
        return $this->hasMany(BacaanSoal::class)->orderBy('id');
    }

    public function soal(): HasMany
    {
        return $this->hasMany(Soal::class)->orderBy('urutan')->orderBy('id');
    }

    /** Admin melihat semua bank; guru hanya bank buatannya sendiri. */
    public function scopeDapatDilihat(Builder $q, User $user): Builder
    {
        return $user->role === User::ADMIN ? $q : $q->where('dibuat_oleh', $user->id);
    }

    public function dapatDikelola(User $user): bool
    {
        return $user->aktif && ($user->role === User::ADMIN || ($user->role === User::GURU && $this->dibuat_oleh === $user->id));
    }
}
