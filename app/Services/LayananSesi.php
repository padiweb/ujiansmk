<?php

namespace App\Services;

use App\Models\HasilUjian;
use App\Models\JadwalUjian;
use App\Models\Jawaban;
use App\Models\SesiUjian;
use App\Models\Siswa;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LayananSesi
{
    private const MAKS_JAWABAN_PER_SINKRON = 500;

    public function __construct(private LayananPaket $paket, private LayananPenilaian $penilaian) {}

    public function toleransi(): int
    {
        return (int) config('ujian.toleransi_kirim_detik');
    }

    /** Siswa berhak atas jadwal bila jadwal aktif, siswa aktif, dan kelasnya termasuk kelas jadwal. */
    public function berhak(Siswa $siswa, JadwalUjian $jadwal): bool
    {
        return $jadwal->berstatus(JadwalUjian::AKTIF)
            && $siswa->status === 'aktif'
            && $siswa->kelas()->whereIn('kelas.id', $jadwal->kelas()->select('kelas.id'))->exists();
    }

    /** Sesi siswa untuk jadwal. Siswa yang masuk kelas setelah aktivasi dibuatkan sesinya di sini. */
    public function sesiUntuk(Siswa $siswa, JadwalUjian $jadwal): ?SesiUjian
    {
        if (! $this->berhak($siswa, $jadwal)) {
            return null;
        }

        return SesiUjian::firstOrCreate(
            ['jadwal_id' => $jadwal->id, 'siswa_id' => $siswa->id],
            ['status' => SesiUjian::BELUM, 'jumlah_pelanggaran' => 0, 'tambahan_menit' => 0],
        );
    }

    public function perangkatCocok(SesiUjian $sesi, ?string $tokenPolos): bool
    {
        return $sesi->token_perangkat !== null
            && $tokenPolos !== null && $tokenPolos !== ''
            && hash_equals($sesi->token_perangkat, hash('sha256', $tokenPolos));
    }

    /**
     * Mulai atau lanjutkan ujian. Mengembalikan token perangkat polos untuk disimpan di cookie.
     *
     * @throws KesalahanUjian
     */
    public function mulai(SesiUjian $sesi, ?string $cookiePerangkat, Request $request): string
    {
        $token = DB::transaction(function () use ($sesi, $cookiePerangkat, $request) {
            $sesi = SesiUjian::query()->lockForUpdate()->findOrFail($sesi->id);
            $jadwal = $sesi->jadwal;

            if (! $jadwal->berstatus(JadwalUjian::AKTIF)) {
                throw new KesalahanUjian('Ujian ini sedang tidak aktif.', 'tidak_aktif', 403);
            }

            if ($sesi->status === SesiUjian::SELESAI) {
                throw new KesalahanUjian('Anda sudah menyelesaikan ujian ini.', 'selesai');
            }

            if (in_array($sesi->status, [SesiUjian::BERLANGSUNG, SesiUjian::DIBLOKIR], true)) {
                if (! $this->perangkatCocok($sesi, $cookiePerangkat)) {
                    throw new KesalahanUjian('Ujian ini sedang dikerjakan di perangkat lain. Hubungi pengawas bila Anda perlu pindah perangkat.', 'perangkat_lain');
                }
                // Waktu sudah habis: ditutup di luar transaksi ini (pengecualian akan membatalkan transaksi).
                if (now()->gt($sesi->batas_selesai->copy()->addSeconds($this->toleransi()))) {
                    return null;
                }

                return $cookiePerangkat;
            }

            // Sesi baru: harus di dalam rentang waktu buka-tutup jadwal.
            match ($jadwal->jendela()) {
                'belum_dibuka' => throw new KesalahanUjian('Ujian ini belum dibuka. Dibuka pada '.$jadwal->mulai_pada->format('d/m/Y H:i').'.', 'belum_dibuka', 403),
                'ditutup' => throw new KesalahanUjian('Waktu untuk memulai ujian ini sudah ditutup.', 'ditutup', 403),
                default => null,
            };

            $token = bin2hex(random_bytes(32));
            $ua = (string) $request->userAgent();
            $mulai = now();

            $sesi->update([
                'status' => SesiUjian::BERLANGSUNG,
                'mulai_pada' => $mulai,
                'batas_selesai' => $mulai->copy()->addMinutes($jadwal->durasi_menit + $sesi->tambahan_menit),
                'token_perangkat' => hash('sha256', $token),
                'platform' => (str_contains($ua, 'ESMK-CBT') || str_contains($ua, 'UjianSMKAltan')) ? 'aplikasi' : 'peramban',
                'info_peramban' => mb_substr($ua, 0, 300),
                'ip' => $request->ip(),
                'terakhir_aktif' => $mulai,
                'susunan' => $this->paket->susunan($jadwal),
            ]);

            return $token;
        });

        if ($token === null) {
            $this->selesaikan($sesi, 'waktu_habis');

            throw new KesalahanUjian('Waktu ujian Anda sudah habis.', 'selesai');
        }

        return $token;
    }

    /**
     * Simpan jawaban yang berubah dan kembalikan keadaan sesi. Sekaligus berfungsi sebagai detak.
     *
     * @param  array<int|string, mixed>  $jawaban  soal_id => ['o' => label|null, 't' => teks|null, 'w' => ms klien]
     * @return array<string,mixed>
     */
    public function sinkron(SesiUjian $sesi, array $jawaban): array
    {
        if (random_int(1, 25) === 1) {
            $this->sapuKedaluwarsa();
        }

        if ($sesi->status === SesiUjian::SELESAI) {
            return $this->keadaan($sesi);
        }

        $batasToleransi = $sesi->batas_selesai->copy()->addSeconds($this->toleransi());
        if (now()->gt($batasToleransi)) {
            return $this->keadaan($this->selesaikan($sesi, 'waktu_habis')->sesi);
        }

        if ($jawaban !== []) {
            $this->simpanJawaban($sesi, $jawaban, terlambat: now()->gt($sesi->batas_selesai));
        }

        $sesi->forceFill(['terakhir_aktif' => now()])->saveQuietly();

        return $this->keadaan($sesi);
    }

    /** Kirim jawaban terakhir lalu akhiri ujian dan nilai PG. */
    public function kirim(SesiUjian $sesi, array $jawaban): HasilUjian
    {
        if ($sesi->status === SesiUjian::SELESAI) {
            return $sesi->hasil ?? $this->penilaian->nilai($sesi);
        }

        $sekarang = now();
        $dalamToleransi = $sekarang->lte($sesi->batas_selesai->copy()->addSeconds($this->toleransi()));
        if ($dalamToleransi && $jawaban !== []) {
            $this->simpanJawaban($sesi, $jawaban, terlambat: $sekarang->gt($sesi->batas_selesai));
        }

        return $this->selesaikan($sesi, $sekarang->gt($sesi->batas_selesai) ? 'waktu_habis' : 'kirim');
    }

    /** Akhiri sesi dan nilai. Aman dipanggil berulang. */
    public function selesaikan(SesiUjian $sesi, string $alasan): HasilUjian
    {
        return DB::transaction(function () use ($sesi, $alasan) {
            $terkunci = SesiUjian::query()->lockForUpdate()->findOrFail($sesi->id);

            if ($terkunci->status !== SesiUjian::SELESAI) {
                $terkunci->update(['status' => SesiUjian::SELESAI, 'selesai_pada' => now(), 'alasan_selesai' => $alasan]);
            }

            $sesi->setRawAttributes($terkunci->fresh()->getAttributes(), true);

            return $terkunci->hasil ?? $this->penilaian->nilai($terkunci);
        });
    }

    /**
     * Tutup dan nilai sesi yang waktunya habis (tanpa cron). Hanya satu penyapu per 30 detik.
     *
     * @return int jumlah sesi yang ditutup
     */
    public function sapuKedaluwarsa(int $maks = 100): int
    {
        if (! Cache::add('ujian:sapu', 1, 30)) {
            return 0;
        }

        $sesi = SesiUjian::query()
            ->whereIn('status', [SesiUjian::BERLANGSUNG, SesiUjian::DIBLOKIR])
            ->where('batas_selesai', '<', now()->subSeconds($this->toleransi()))
            ->limit($maks)
            ->get();

        foreach ($sesi as $s) {
            $this->selesaikan($s, 'waktu_habis');
        }

        return $sesi->count();
    }

    /** Selang sinkron menurut jumlah siswa yang sedang mengerjakan (detik). */
    public function selang(): int
    {
        $aktif = Cache::remember('ujian:jumlah_aktif', 15, fn () => SesiUjian::where('status', SesiUjian::BERLANGSUNG)->count());

        return match (true) {
            $aktif < 300 => 20,
            $aktif < 800 => 40,
            $aktif < 1500 => 60,
            default => 90,
        };
    }

    /** @return array<string,mixed> */
    public function keadaan(SesiUjian $sesi): array
    {
        return [
            'status' => $sesi->status,
            'waktu_server' => (int) round(microtime(true) * 1000),
            'batas_selesai' => $sesi->batas_selesai?->getTimestampMs(),
            'pelanggaran' => $sesi->jumlah_pelanggaran,
            'selang' => $this->selang(),
        ];
    }

    /** @param  array<int|string,mixed>  $masuk */
    private function simpanJawaban(SesiUjian $sesi, array $masuk, bool $terlambat): void
    {
        $info = collect($this->paket->paket($sesi->jadwal)['soal'])->keyBy('id');
        $sekarang = now();
        $baris = [];

        foreach (array_slice($masuk, 0, self::MAKS_JAWABAN_PER_SINKRON, true) as $soalId => $isi) {
            $soalId = (int) $soalId;
            if (! isset($info[$soalId]) || ! is_array($isi)) {
                continue;
            }

            $label = null;
            $teks = null;
            if ($info[$soalId]['tipe'] === 'pg') {
                $label = $isi['o'] ?? null;
                if ($label !== null && (! is_string($label) || ! array_key_exists($label, (array) $info[$soalId]['opsi']))) {
                    continue; // label bukan opsi soal ini
                }
            } else {
                $teks = isset($isi['t']) && is_string($isi['t']) ? mb_substr($isi['t'], 0, 5000) : null;
            }

            $baris[$soalId] = [
                'sesi_id' => $sesi->id,
                'soal_id' => $soalId,
                'opsi_label' => $label,
                'teks_esai' => $teks,
                'dijawab_klien' => $this->waktuKlien($isi['w'] ?? null, $sekarang),
                'diterima_pada' => $sekarang->toDateTimeString(),
                'terlambat' => $terlambat,
            ];
        }

        if ($baris === []) {
            return;
        }

        // Abaikan kiriman basi: jawaban lama yang tiba terlambat tidak menimpa yang lebih baru.
        $ada = Jawaban::query()->where('sesi_id', $sesi->id)->whereIn('soal_id', array_keys($baris))->pluck('dijawab_klien', 'soal_id');
        foreach ($baris as $soalId => $b) {
            $lama = $ada[$soalId] ?? null;
            if ($lama && $b['dijawab_klien'] && Carbon::parse($lama)->gt(Carbon::parse($b['dijawab_klien']))) {
                unset($baris[$soalId]);
            }
        }

        if ($baris !== []) {
            Jawaban::upsert(array_values($baris), ['sesi_id', 'soal_id'], ['opsi_label', 'teks_esai', 'dijawab_klien', 'diterima_pada', 'terlambat']);
        }
    }

    /** Waktu dari peramban hanya untuk mengurutkan kiriman; dibatasi agar tidak ngawur. */
    private function waktuKlien(mixed $ms, Carbon $sekarang): ?string
    {
        if (! is_numeric($ms)) {
            return null;
        }

        $waktu = Carbon::createFromTimestampMs((int) $ms);

        return $waktu->between($sekarang->copy()->subDay(), $sekarang->copy()->addMinute()) ? $waktu->toDateTimeString() : null;
    }
}
