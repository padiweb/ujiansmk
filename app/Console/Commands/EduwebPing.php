<?php

namespace App\Console\Commands;

use App\Eduweb\KesalahanEduweb;
use App\Eduweb\KlienEduweb;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class EduwebPing extends Command
{
    protected $signature = 'eduweb:ping {--penuh : Periksa juga setiap endpoint data (hanya membaca)}';

    protected $description = 'Periksa koneksi ke API EduWeb dan kunci yang dipakai';

    public function handle(KlienEduweb $klien): int
    {
        if (! $klien->terkonfigurasi()) {
            $this->error('Penghubung EduWeb belum diatur. Isi EDUWEB_URL dan EDUWEB_KUNCI di .env (lalu php artisan config:clear bila config sudah di-cache).');

            return self::FAILURE;
        }

        try {
            $mulai = microtime(true);
            $ping = $klien->get('ping');
            $ms = (int) round((microtime(true) - $mulai) * 1000);
        } catch (KesalahanEduweb $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Terhubung ke EduWeb.');
        $this->table(['Item', 'Nilai'], [
            ['Sekolah', ($ping['school']['name'] ?? '?').' (NPSN '.($ping['school']['npsn'] ?? '-').')'],
            ['Nama kunci', $ping['client'] ?? '?'],
            ['Versi API', $ping['api_version'] ?? '?'],
            ['Waktu respons', "{$ms} ms"],
            ['Selisih jam server', $this->selisihJam($ping['server_time'] ?? null)],
        ]);

        if (! $this->option('penuh')) {
            $this->line('Gunakan --penuh untuk memeriksa semua endpoint data.');

            return self::SUCCESS;
        }

        $gagal = 0;
        $baris = [];
        $daftar = [
            'academic-years' => [], 'majors' => [], 'classrooms' => [], 'subjects' => [], 'teaching-assignments' => [],
            'teachers' => ['per_page' => 1], 'students' => ['per_page' => 1], 'events' => ['limit' => 1],
        ];
        foreach ($daftar as $jalur => $query) {
            try {
                $j = $klien->get($jalur, $query);
                $jumlah = $j['total'] ?? (isset($j['data']) ? count($j['data']) : '-');
                $baris[] = [$jalur, 'OK', $jumlah.($jalur === 'events' ? ' (kejadian terbaru: '.($j['last_id'] ?? '-').')' : '')];
            } catch (KesalahanEduweb $e) {
                $gagal++;
                $baris[] = [$jalur, 'GAGAL', $e->getMessage()];
            }
        }
        $this->table(['Endpoint', 'Status', 'Jumlah / pesan'], $baris);

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function selisihJam(?string $waktuServer): string
    {
        if (! $waktuServer) {
            return '?';
        }

        try {
            $selisih = (int) Carbon::parse($waktuServer)->diffInSeconds(now(), false);
        } catch (\Throwable) {
            return '?';
        }

        return abs($selisih) <= 5 ? 'wajar (maks. 5 detik)' : "{$selisih} detik (periksa zona waktu/jam server)";
    }
}
