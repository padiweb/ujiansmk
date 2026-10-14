<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public static function aktif(): ?self
    {
        return static::where('aktif', true)->first();
    }

    /** Hanya satu penyedia yang aktif pada satu waktu. */
    public function jadikanAktif(): void
    {
        static::where('id', '!=', $this->id)->update(['aktif' => false]);
        $this->update(['aktif' => true]);
    }

    /** Empat karakter terakhir kunci untuk dikenali admin tanpa menampilkan kuncinya. */
    public function petunjukKunci(): string
    {
        $kunci = (string) $this->kunci;

        return '••••'.substr($kunci, -4);
    }

    public function namaDriver(): string
    {
        return self::DRIVER[$this->driver] ?? $this->driver;
    }
}
