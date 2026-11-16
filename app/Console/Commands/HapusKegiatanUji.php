<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\KegiatanUjian;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HapusKegiatanUji extends Command
{
    protected $signature = 'ujian:hapus-kegiatan
        {kegiatan : ID kegiatan ujian yang isinya akan dihapus}
        {--yakin= : nama kegiatan persis; tanpa ini hanya menampilkan apa yang akan dihapus}
        {--dengan-kegiatan : ikut menghapus kegiatannya setelah jadwalnya kosong}';

    protected $description = 'Hapus permanen semua jadwal, sesi, jawaban, hasil, dan pelanggaran sebuah kegiatan ujian (untuk kegiatan uji coba). Bank soal tidak dihapus. Bawaan: hanya menampilkan isinya.';

    public function handle(): int
    {
        $kegiatan = KegiatanUjian::with('periode.tahunAjaran')->find((int) $this->argument('kegiatan'));
        if (! $kegiatan) {
            $this->error('Kegiatan dengan ID itu tidak ditemukan.');

            return self::FAILURE;
        }

        $jadwalIds = DB::table('jadwal_ujian')->where('kegiatan_id', $kegiatan->id)->pluck('id');
        $sesiIds = DB::table('sesi_ujian')->whereIn('jadwal_id', $jadwalIds)->pluck('id');
        $hitung = [
            ['Jadwal ujian', $jadwalIds->count()],
            ['Sesi siswa', $sesiIds->count()],
            ['Jawaban', DB::table('jawaban')->whereIn('sesi_id', $sesiIds)->count()],
            ['Hasil ujian', DB::table('hasil_ujian')->whereIn('sesi_id', $sesiIds)->count()],
            ['Pelanggaran', DB::table('pelanggaran')->whereIn('sesi_id', $sesiIds)->count()],
        ];

        $this->info('Kegiatan: '.$kegiatan->labelLengkap().' (ID '.$kegiatan->id.')');
        $this->table(['Yang akan dihapus', 'Jumlah'], $hitung);

        if ($kegiatan->untuk_rapor) {
            $this->error('Kegiatan ini bertanda "untuk rapor", jadi nilainya bisa dipakai rapor. Perintah ini hanya untuk kegiatan uji coba, dan tidak dijalankan.');

            return self::FAILURE;
        }

        $yakin = (string) $this->option('yakin');
        if ($yakin === '') {
            $this->line('Hanya tampilan. Untuk menghapus, jalankan lagi dengan --yakin="'.$kegiatan->nama.'"');

            return self::SUCCESS;
        }
        if ($yakin !== $kegiatan->nama) {
            $this->error('Nilai --yakin tidak sama dengan nama kegiatan. Tidak ada yang dihapus.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($kegiatan, $jadwalIds) {
            // Sesi, jawaban, hasil, pelanggaran, kunci nilai, dan tautan kelas/bank ikut terhapus lewat kaitan cascade.
            DB::table('jadwal_ujian')->whereIn('id', $jadwalIds)->delete();
            if ($this->option('dengan-kegiatan')) {
                $kegiatan->delete();
            }
        });
        AuditLog::catat('kegiatan.hapus_isi', "kegiatan {$kegiatan->id} ({$kegiatan->nama}): {$jadwalIds->count()} jadwal".($this->option('dengan-kegiatan') ? ', kegiatan ikut dihapus' : ''));

        $this->info('Selesai. '.$jadwalIds->count().' jadwal beserta seluruh isinya dihapus'.($this->option('dengan-kegiatan') ? ', kegiatan juga dihapus.' : '.'));

        return self::SUCCESS;
    }
}
