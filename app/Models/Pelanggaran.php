<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pelanggaran extends Model
{
    public $timestamps = false;

    protected $table = 'pelanggaran';

    /**
     * Jenis yang dikenal peramban: kode => [label, dihitung]. Yang tidak dihitung hanya menjadi
     * informasi bagi pengawas (sinyalnya terlalu mudah salah untuk dijadikan dasar blokir).
     */
    public const JENIS = [
        'keluar_layar_penuh' => ['Keluar layar penuh', true],
        'pindah_tab' => ['Pindah aplikasi atau tab', true],
        'aplikasi_mengambang' => ['Aplikasi atau jendela mengambang', true],
        'split_screen' => ['Layar terbagi (split screen)', true],
        'viewport_menyempit' => ['Area tampilan menyempit', true],
        'halaman_beku' => ['Halaman terhenti lama', true],
        'tertutup_overlay' => ['Soal tertutup lapisan lain', true],
        'pip' => ['Picture-in-Picture', true],
        'tombol_kembali' => ['Menekan tombol kembali', true],
        'screenshot' => ['Mencoba tangkap layar', true],
        'tab_ganda' => ['Tab atau jendela ganda', true],
        'aplikasi_android' => ['Dilaporkan aplikasi Android', true],
        'devtools' => ['Alat pengembang terdeteksi', true],
        'halaman_ditinggalkan' => ['Halaman ditutup atau dimuat ulang', true],
        // Hanya informasi: sematan_dilepas selalu menyertai pindah_tab yang sudah dihitung (akan terhitung dua kali untuk satu kejadian),
        // dan koneksi_putus bukan perbuatan siswa (jaringan yang buruk tidak boleh memblokir ujian).
        'sematan_dilepas' => ['Sematan layar kemungkinan dilepas', false],
        'koneksi_putus' => ['Koneksi terputus', false],
    ];

    protected $fillable = ['sesi_id', 'jenis', 'keterangan', 'dihitung', 'info', 'created_at'];

    protected function casts(): array
    {
        return ['dihitung' => 'boolean', 'info' => 'array', 'created_at' => 'datetime'];
    }

    public function sesi(): BelongsTo
    {
        return $this->belongsTo(SesiUjian::class, 'sesi_id');
    }

    public static function label(string $jenis): string
    {
        return self::JENIS[$jenis][0] ?? $jenis;
    }
}
