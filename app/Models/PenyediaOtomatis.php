<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Penyedia layanan otomatis. Tidak lagi diatur lewat layar admin: satu-satunya penyedia dibaca dari
 * config('ujian.otomatis') (diisi .env). Kelas ini tetap Eloquent hanya agar relasi riwayat lama tetap berfungsi;
 * instance dari aktif() tidak pernah disimpan ke basis data.
 */
class PenyediaOtomatis extends Model
{
    protected $table = 'penyedia_otomatis';

    public const DRIVER = [
        'gemini' => 'Google Gemini',
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic Claude',
    ];

    protected $fillable = ['nama', 'driver', 'model', 'kunci', 'aktif'];

    /** Kunci tidak pernah ikut ke JSON/array agar tidak bocor lewat log atau tampilan. */
    protected $hidden = ['kunci'];

    protected function casts(): array
    {
        return [
            'kunci' => 'encrypted',
            'aktif' => 'boolean',
        ];
    }

    /** Penyedia dari .env, atau null bila belum lengkap/tidak valid (fitur otomatis lalu disembunyikan). */
    public static function aktif(): ?self
    {
        $c = (array) config('ujian.otomatis');
        $driver = (string) ($c['driver'] ?? '');
        $model = (string) ($c['model'] ?? '');
        $kunci = (string) ($c['kunci'] ?? '');

        // Nama model masuk ke alamat permintaan (Gemini), jadi dibatasi ke karakter aman.
        if (! isset(self::DRIVER[$driver]) || preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $model) !== 1 || strlen($kunci) < 10) {
            return null;
        }

        return new static(['nama' => self::DRIVER[$driver], 'driver' => $driver, 'model' => $model, 'kunci' => $kunci, 'aktif' => true]);
    }

    public static function tersedia(): bool
    {
        return static::aktif() !== null;
    }

    public function namaDriver(): string
    {
        return self::DRIVER[$this->driver] ?? $this->driver;
    }
}
