<?php

namespace App\Services;

use App\Models\BankSoal;
use App\Models\LogOtomatis;
use App\Models\PenyediaOtomatis;
use App\Models\User;
use App\Otomatis\KesalahanOtomatis;
use App\Otomatis\PenyaringHasil;
use App\Otomatis\PenyusunSoal;
use App\Otomatis\PermintaanSoal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class LayananOtomatis
{
    public function __construct(private LayananSoal $layananSoal, private PenyaringHasil $penyaring) {}

    public function batasHarian(): int
    {
        return (int) config('ujian.batas_otomatis_harian');
    }

    /** Sisa permintaan hari ini untuk pengguna. */
    public function sisaHariIni(User $user): int
    {
        $terpakai = LogOtomatis::where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())->count();

        return max(0, $this->batasHarian() - $terpakai);
    }

    /**
     * Susun soal dan simpan sebagai draf di $bank.
     *
     * @return int jumlah soal yang tersimpan
     *
     * @throws KesalahanOtomatis pesan aman ditampilkan ke pengguna
     */
    public function susun(User $user, BankSoal $bank, PermintaanSoal $permintaan): int
    {
        $penyedia = PenyediaOtomatis::aktif()
            ?? throw new KesalahanOtomatis('Belum ada penyedia otomatis yang aktif. Minta admin mengaturnya di Pengaturan → Penyedia Otomatis.');

        if ($this->sisaHariIni($user) <= 0) {
            throw new KesalahanOtomatis("Batas {$this->batasHarian()} permintaan per hari sudah tercapai. Coba lagi besok.");
        }

        $kunciLaju = "otomatis:{$user->id}";
        if (RateLimiter::tooManyAttempts($kunciLaju, 5)) {
            throw new KesalahanOtomatis('Terlalu sering meminta soal. Tunggu sebentar, lalu coba lagi.');
        }
        RateLimiter::hit($kunciLaju, 60);

        set_time_limit(180);

        try {
            $mentah = PenyusunSoal::untuk($penyedia)->susun($permintaan);
            $soal = $this->penyaring->saring($mentah, $permintaan);

            if ($soal === []) {
                throw new KesalahanOtomatis('Tidak ada soal yang valid dari penyedia. Coba lagi, atau perjelas topiknya.');
            }
        } catch (KesalahanOtomatis $e) {
            $this->catat($user, $penyedia, $bank, $permintaan, 0, 'gagal', $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->catat($user, $penyedia, $bank, $permintaan, 0, 'gagal', 'Galat tak terduga.');
            throw new KesalahanOtomatis('Terjadi galat tak terduga saat menyusun soal. Coba lagi.');
        }

        DB::transaction(function () use ($soal, $bank) {
            $urutan = (int) ($bank->soal()->withTrashed()->max('urutan') ?? 0);
            foreach ($soal as $s) {
                $baru = $bank->soal()->create([
                    'tipe' => $s['tipe'],
                    'pertanyaan' => $s['pertanyaan'],
                    'bobot' => 1,
                    'rubrik_esai' => $s['rubrik'],
                    'status' => 'draf',
                    'sumber' => 'otomatis',
                    'urutan' => ++$urutan,
                ]);
                if ($s['tipe'] === 'pg') {
                    $this->layananSoal->setOpsi($baru, $s['opsi'], $s['kunci']);
                }
            }
        });

        $this->catat($user, $penyedia, $bank, $permintaan, count($soal), 'berhasil', null);

        return count($soal);
    }

    private function catat(User $user, PenyediaOtomatis $penyedia, BankSoal $bank, PermintaanSoal $p, int $hasil, string $status, ?string $pesan): void
    {
        LogOtomatis::create([
            'user_id' => $user->id,
            'penyedia_id' => $penyedia->id,
            'bank_soal_id' => $bank->id,
            'jumlah_diminta' => $p->jumlah,
            'jumlah_dihasilkan' => $hasil,
            'status' => $status,
            'pesan' => $pesan !== null ? mb_substr($pesan, 0, 250) : null,
        ]);
    }
}
