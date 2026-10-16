<?php

namespace App\Eduweb;

use App\Models\JadwalUjian;
use App\Models\PengaturanSekolah;
use App\Models\SesiUjian;
use Illuminate\Support\Facades\Cache;

/**
 * Menjalankan "ikuti kejadian" secara otomatis tanpa cron: dipicu webhook EduWeb dan, sebagai cadangan,
 * pengecekan berkala saat halaman dibuka. Berhenti sementara selama ujian berlangsung.
 */
class SinkronOtomatis
{
    private const KUNCI_PERLU = 'eduweb:perlu';

    private const KUNCI_CEK = 'eduweb:cek';

    private const MAKS_ULANG = 3;

    public function __construct(private PenyinkronEduweb $penyinkron, private KlienEduweb $klien) {}

    public function aktif(): bool
    {
        return (bool) config('eduweb.otomatis') && $this->klien->terkonfigurasi();
    }

    /** Dipanggil setelah webhook sah diterima. Menandai ada pekerjaan, lalu mencoba menjalankannya. */
    public function dariWebhook(): void
    {
        Cache::put(self::KUNCI_PERLU, true, now()->addHour());
        $this->jalankan();
    }

    /** Cadangan bila webhook terlewat: paling sering sekali per EDUWEB_CEK_MENIT, dipanggil setelah respons terkirim. */
    public function cobaBilaPerlu(): void
    {
        if (! (bool) config('eduweb.otomatis') || Cache::has(self::KUNCI_CEK)) {
            return;
        }
        if (! $this->klien->terkonfigurasi() || PengaturanSekolah::ambil()->eduweb_sinkron_penuh_pada === null) {
            return;
        }
        if (! Cache::add(self::KUNCI_CEK, true, now()->addMinutes(max(1, (int) config('eduweb.cek_menit'))))) {
            return;
        }

        Cache::put(self::KUNCI_PERLU, true, now()->addHour());
        $this->jalankan();
    }

    /** True bila ada ujian yang sedang atau akan segera berlangsung; data peserta tidak boleh berubah saat itu. */
    public function ujianBerlangsung(): bool
    {
        $sekarang = now();

        if (SesiUjian::where('status', SesiUjian::BERLANGSUNG)->where('batas_selesai', '>=', $sekarang)->exists()) {
            return true;
        }

        // Jadwal aktif yang jendelanya pendek (ujian sungguhan); tugas daring berhari-hari tidak menahan sinkron.
        return JadwalUjian::where('status', 'aktif')
            ->where('mulai_pada', '<=', $sekarang->copy()->addMinutes(10))->where('tutup_pada', '>=', $sekarang)
            ->get(['mulai_pada', 'tutup_pada'])
            ->contains(fn ($j) => $j->mulai_pada->diffInHours($j->tutup_pada) <= 12);
    }

    /**
     * Menjalankan sinkron kejadian selama ada penanda pekerjaan (maks 3 putaran). Penanda dibaca ulang setelah
     * tiap putaran supaya sinyal yang datang saat sinkron berjalan tidak hilang.
     */
    private function jalankan(): void
    {
        if (! $this->aktif()) {
            return;
        }

        $pengaturan = PengaturanSekolah::ambil();
        if ($pengaturan->eduweb_sinkron_penuh_pada === null) {
            return;
        }

        for ($i = 0; $i < self::MAKS_ULANG && Cache::get(self::KUNCI_PERLU); $i++) {
            if ($this->ujianBerlangsung()) {
                $pengaturan->update(['eduweb_kejadian_tertahan' => true]);

                return; // penanda dibiarkan: diterapkan otomatis setelah ujian selesai
            }

            Cache::forget(self::KUNCI_PERLU);
            try {
                $this->penyinkron->kejadian(null, true);
                $pengaturan->update(['eduweb_cek_terakhir' => now(), 'eduweb_kejadian_tertahan' => false]);
            } catch (KesalahanEduweb $e) {
                if ($e->kode === 'sibuk') {
                    Cache::put(self::KUNCI_PERLU, true, now()->addHour()); // proses lain sedang jalan: ia akan membaca penanda ini

                    return;
                }
                $pengaturan->update(['eduweb_cek_terakhir' => now()]); // galat sudah tercatat di riwayat; jangan menghujani EduWeb

                return;
            } catch (\Throwable $e) {
                report($e);

                return;
            }
        }
    }
}
