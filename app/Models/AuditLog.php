<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_log';

    protected $fillable = ['pelaku_tipe', 'pelaku_id', 'aksi', 'detail', 'ip'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * Catat satu aksi. $pelaku boleh User, Siswa, atau null (sistem / belum login).
     */
    public static function catat(string $aksi, ?string $detail = null, User|Siswa|null $pelaku = null): self
    {
        return static::create([
            'pelaku_tipe' => match (true) {
                $pelaku instanceof User => 'staf',
                $pelaku instanceof Siswa => 'siswa',
                default => 'sistem',
            },
            'pelaku_id' => $pelaku?->getKey(),
            'aksi' => $aksi,
            'detail' => $detail !== null ? mb_substr($detail, 0, 1000) : null,
            'ip' => request()?->ip(),
        ]);
    }
}
