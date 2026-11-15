<?php

namespace App\Console\Commands;

use App\Migrasi\PemindahDataLama;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrasiLama extends Command
{
    protected $signature = 'migrasi:lama
        {--bagian=semua : nilai, rapor, cbt, us, soal, atau semua}
        {--simpan : benar-benar simpan (tanpa ini hanya uji coba: semua dibatalkan di akhir)}
        {--admin= : id pengguna admin yang tercatat sebagai pembuat bank soal (bawaan: admin pertama)}';

    protected $description = 'Pindahkan nilai rapor lama, absensi dan catatan wali, hasil ujian CBT, nilai US, dan bank soal dari database aplikasi lama (hanya dibaca). Bawaan: uji coba.';

    public function handle(PemindahDataLama $pemindah): int
    {
        $bagian = (string) $this->option('bagian');
        if (! in_array($bagian, ['nilai', 'rapor', 'cbt', 'us', 'soal', 'semua'], true)) {
            $this->error('Pilihan --bagian: nilai, rapor, cbt, us, soal, atau semua.');

            return self::INVALID;
        }
        if ((string) config('database.connections.lama.database') === '') {
            $this->error('LAMA_DB_DATABASE belum diisi di .env. Isi LAMA_DB_* untuk database aplikasi lama.');

            return self::FAILURE;
        }
        $admin = $this->option('admin') ? User::find((int) $this->option('admin')) : User::where('role', 'admin')->orderBy('id')->first();
        if (! $admin) {
            $this->error('Pengguna admin tidak ditemukan.');

            return self::FAILURE;
        }

        $simpan = (bool) $this->option('simpan');
        $this->info($simpan ? 'MODE SIMPAN: perubahan akan disimpan.' : 'MODE UJI COBA: tidak ada yang disimpan (tambahkan --simpan untuk menyimpan).');

        DB::beginTransaction();
        try {
            if (in_array($bagian, ['nilai', 'semua'], true)) {
                $this->line('Memindahkan nilai rapor…');
                $pemindah->nilaiRapor($admin);
            }
            if (in_array($bagian, ['rapor', 'semua'], true)) {
                $this->line('Memindahkan absensi, catatan wali, dan keputusan…');
                $pemindah->dataRapor();
            }
            if (in_array($bagian, ['cbt', 'semua'], true)) {
                $this->line('Memindahkan hasil ujian CBT…');
                $pemindah->hasilCbt();
            }
            if (in_array($bagian, ['us', 'semua'], true)) {
                $this->line('Memindahkan nilai Ujian Sekolah…');
                $pemindah->nilaiUs();
            }
            if (in_array($bagian, ['soal', 'semua'], true)) {
                $this->line('Memindahkan bank soal…');
                $pemindah->bankSoal($admin);
            }
            $simpan ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Gagal, semua perubahan dibatalkan: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Hasil', 'Jumlah'], collect($pemindah->hitung)->map(fn ($n, $k) => [$k, $n])->values()->all());
        foreach ($pemindah->catatan as $c) {
            $this->warn($c);
        }

        return self::SUCCESS;
    }
}
