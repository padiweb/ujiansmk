<?php

namespace App\Services;

use App\Models\BankSoal;
use App\Models\Soal;
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
    /** Jawaban per permintaan saran nilai. */
    public const MAKS_SARAN = 10;

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
            ?? throw new KesalahanOtomatis('Fitur otomatis belum tersedia di instalasi ini. Hubungi pengelola aplikasi.');

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

    /**
     * Minta saran nilai untuk beberapa jawaban esai satu soal. Hanya mengembalikan saran
     * (id jawaban => [nilai 0-100, catatan]); tidak mengubah nilai resmi.
     *
     * @param  list<array{id:int,teks:string}>  $jawaban  maksimal MAKS_SARAN butir
     * @return array<int,array{nilai:float,catatan:?string}>
     *
     * @throws KesalahanOtomatis
     */
    public function sarankanNilai(User $user, Soal $soal, string $mapel, array $jawaban): array
    {
        $penyedia = PenyediaOtomatis::aktif()
            ?? throw new KesalahanOtomatis('Fitur otomatis belum tersedia di instalasi ini. Hubungi pengelola aplikasi.');

        if ($jawaban === [] || count($jawaban) > self::MAKS_SARAN) {
            throw new KesalahanOtomatis('Pilih 1 sampai '.self::MAKS_SARAN.' jawaban untuk dimintakan saran.');
        }

        if ($this->sisaHariIni($user) <= 0) {
            throw new KesalahanOtomatis("Batas {$this->batasHarian()} permintaan per hari sudah tercapai. Coba lagi besok.");
        }

        $kunciLaju = "otomatis:{$user->id}";
        if (RateLimiter::tooManyAttempts($kunciLaju, 5)) {
            throw new KesalahanOtomatis('Terlalu sering meminta. Tunggu sebentar, lalu coba lagi.');
        }
        RateLimiter::hit($kunciLaju, 60);

        set_time_limit(180);
        $diminta = array_column($jawaban, 'id');

        try {
            $mentah = PenyusunSoal::untuk($penyedia)->nilaiEsai($mapel, $soal->pertanyaan, $soal->rubrik_esai, $jawaban);
            $saran = [];
            foreach ($mentah as $butir) {
                $id = $butir['id'] ?? null;
                $nilai = $butir['nilai'] ?? null;
                if (! is_int($id) && ! (is_string($id) && ctype_digit($id)) || ! in_array((int) $id, $diminta, true) || ! is_numeric($nilai)) {
                    continue;
                }
                $catatan = is_string($butir['catatan'] ?? null) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $butir['catatan']) ?? ''), 0, 300) : null;
                $saran[(int) $id] = ['nilai' => round(max(0.0, min(100.0, (float) $nilai)), 2), 'catatan' => $catatan ?: null];
            }

            if ($saran === []) {
                throw new KesalahanOtomatis('Tidak ada saran yang valid dari penyedia. Coba lagi.');
            }
        } catch (KesalahanOtomatis $e) {
            $this->catatSaran($user, $penyedia, $soal, count($jawaban), 0, 'gagal', $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->catatSaran($user, $penyedia, $soal, count($jawaban), 0, 'gagal', 'Galat tak terduga.');
            throw new KesalahanOtomatis('Terjadi galat tak terduga saat meminta saran. Coba lagi.');
        }

        $this->catatSaran($user, $penyedia, $soal, count($jawaban), count($saran), 'berhasil', null);

        return $saran;
    }

    private function catatSaran(User $user, PenyediaOtomatis $penyedia, Soal $soal, int $diminta, int $hasil, string $status, ?string $pesan): void
    {
        LogOtomatis::create([
            'user_id' => $user->id,
            'penyedia_id' => $penyedia->id,
            'bank_soal_id' => $soal->bank_soal_id,
            'jumlah_diminta' => $diminta,
            'jumlah_dihasilkan' => $hasil,
            'status' => $status,
            'pesan' => $pesan !== null ? mb_substr('[saran nilai] '.$pesan, 0, 250) : '[saran nilai]',
        ]);
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
