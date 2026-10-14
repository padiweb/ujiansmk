<?php

namespace App\Console\Commands;

use App\Eduweb\HasilSinkron;
use App\Eduweb\KesalahanEduweb;
use App\Eduweb\PenyinkronEduweb;
use Illuminate\Console\Command;

class EduwebSinkron extends Command
{
    protected $signature = 'eduweb:sinkron {--kejadian : Hanya ikuti kejadian (kenaikan kelas, lulus, pindah) tanpa sinkron penuh}';

    protected $description = 'Tarik data master dari EduWeb (sinkron penuh) atau ikuti kejadian terbaru';

    public function handle(PenyinkronEduweb $penyinkron): int
    {
        try {
            $h = $this->option('kejadian') ? $penyinkron->kejadian() : $penyinkron->penuh();
        } catch (KesalahanEduweb $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Sinkron selesai.');
        $this->tampilkan($h);

        return self::SUCCESS;
    }

    private function tampilkan(HasilSinkron $h): void
    {
        if ($h->entitas) {
            $this->table(['Data', 'Baru', 'Diperbarui', 'Dilewati'], collect($h->entitas)->map(fn ($v, $k) => [$k, $v['baru'], $v['diperbarui'], $v['dilewati']])->values()->all());
        }
        foreach ($h->lain as $k => $n) {
            $this->line(str_replace('_', ' ', $k).': '.$n);
        }
        foreach ($h->peringatan as $p) {
            $this->warn($p);
        }
        if ($h->peringatanTerpotong > 0) {
            $this->warn("... dan {$h->peringatanTerpotong} peringatan lain.");
        }
        if ($h->akunBaru) {
            $this->newLine();
            $this->warn('Akun guru baru (sandi hanya tampil sekali, catat sekarang):');
            $this->table(['Nama pengguna', 'Nama', 'Sandi sementara'], array_map(fn ($a) => [$a['username'], $a['nama'], $a['sandi']], $h->akunBaru));
        }
    }
}
