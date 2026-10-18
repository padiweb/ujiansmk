<?php

namespace App\Services;

use App\Models\AksiPengawas;
use App\Models\JadwalSoal;
use App\Models\JadwalUjian;
use App\Models\Jawaban;
use App\Models\Pelanggaran;
use App\Models\Pengampu;
use App\Models\SesiUjian;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Data monitor pengawas dan tindakannya. Semua tindakan tercatat di aksi_pengawas. */
class LayananMonitor
{
    public const TTL_DATA = 5;

    /** Maksimal menit yang boleh ditambahkan sekali tindakan. */
    public const MAKS_TAMBAH_MENIT = 120;

    public function __construct(private LayananSesi $sesi) {}

    // ── Hak akses ───────────────────────────────────────────────────────────

    /** Jadwal aktif yang boleh dipantau: admin dan pengawas semuanya (semua ruang); guru biasa hanya mapelnya. */
    public function jadwalUntuk(User $user): Builder
    {
        $kueri = JadwalUjian::query()->where('status', JadwalUjian::AKTIF);

        if ($user->role === 'admin' || $user->mengawasi()) {
            return $kueri;
        }

        return $user->role === 'guru' && $user->aktif
            ? $kueri->whereIn('mapel_id', Pengampu::where('guru_id', $user->id)->select('mapel_id'))
            : $kueri->whereRaw('1 = 0');
    }

    public function bolehLihat(User $user, JadwalUjian $jadwal): bool
    {
        return $this->jadwalUntuk($user)->whereKey($jadwal->id)->exists();
    }

    /** Tindakan yang boleh dilakukan guru mapel pada ujiannya (selain melihat): membantu siswa yang terblokir atau macet. */
    public const AKSI_GURU = ['aktifkan', 'selesai'];

    /**
     * Tindakan yang boleh dilakukan pengguna pada jadwal ini. Admin dan pengawas boleh semuanya di semua ruang;
     * guru mapel hanya AKSI_GURU.
     *
     * @return list<string>
     */
    public function aksiBoleh(User $user, JadwalUjian $jadwal): array
    {
        if ($this->bolehBertindak($user, $jadwal)) {
            return ['aktifkan', 'reset', 'waktu', 'selesai'];
        }

        return $user->role === 'guru' && $this->bolehLihat($user, $jadwal) ? self::AKSI_GURU : [];
    }

    /** Admin dan pengawas: seluruh tindakan, token, dan daftar hadir untuk semua ruang pada jadwal aktif. */
    public function bolehBertindak(User $user, JadwalUjian $jadwal): bool
    {
        return ($user->role === 'admin' || $user->mengawasi()) && $this->bolehLihat($user, $jadwal);
    }

    // ── Data ────────────────────────────────────────────────────────────────

    /**
     * Data satu jadwal; di-cache sebentar agar banyak pengawas tidak melipatgandakan beban basis data.
     *
     * @return array<string,mixed>
     */
    public function data(JadwalUjian $jadwal): array
    {
        // Memicu penutupan sesi kedaluwarsa tanpa cron (satu penyapu per 30 detik).
        $this->sesi->sapuKedaluwarsa();

        return Cache::remember($this->kunci($jadwal), self::TTL_DATA, fn () => $this->susun($jadwal));
    }

    /** @param  list<array<string,mixed>>  $baris */
    private function ringkas(array $baris): array
    {
        $hitung = fn (callable $f) => count(array_filter($baris, $f));

        return [
            'mengerjakan' => $hitung(fn ($b) => $b['status'] === SesiUjian::BERLANGSUNG),
            'selesai' => $hitung(fn ($b) => $b['status'] === SesiUjian::SELESAI),
            'melanggar' => $hitung(fn ($b) => $b['pelanggaran'] > 0 && $b['status'] !== SesiUjian::SELESAI),
            'terblokir' => $hitung(fn ($b) => $b['status'] === SesiUjian::DIBLOKIR),
            'belum' => $hitung(fn ($b) => $b['status'] === SesiUjian::BELUM),
            'senyap' => $hitung(fn ($b) => $b['senyap']),
        ];
    }

    public function lupakan(JadwalUjian|int $jadwal): void
    {
        Cache::forget($this->kunci($jadwal));
    }

    /** @return array<string,mixed> */
    private function susun(JadwalUjian $jadwal): array
    {
        $sekarang = now();
        $ambangSenyap = max(60, $this->sesi->selang() * 3);
        $kelasJadwal = $jadwal->kelas()->pluck('kelas.id')->all();

        $sesi = SesiUjian::query()->where('jadwal_id', $jadwal->id)
            ->with(['siswa:id,nama,nisn', 'siswa.kelas:id,nama', 'ruang:id,nama'])
            ->get();
        $ids = $sesi->pluck('id');

        $terakhir = Pelanggaran::whereIn('id', Pelanggaran::query()->selectRaw('max(id)')->whereIn('sesi_id', $ids)->groupBy('sesi_id'))
            ->get()->keyBy('sesi_id');

        $dijawab = Jawaban::query()->whereIn('sesi_id', $ids)
            ->where(fn ($q) => $q->whereNotNull('opsi_label')->orWhere(fn ($q2) => $q2->whereNotNull('teks_esai')->where('teks_esai', '!=', '')))
            ->selectRaw('sesi_id, count(*) as jumlah')->groupBy('sesi_id')->pluck('jumlah', 'sesi_id');

        $baris = $sesi->map(function (SesiUjian $s) use ($terakhir, $dijawab, $sekarang, $ambangSenyap, $kelasJadwal) {
            $kabar = $s->terakhir_aktif ? (int) $s->terakhir_aktif->diffInSeconds($sekarang, true) : null;
            $berjalan = in_array($s->status, [SesiUjian::BERLANGSUNG, SesiUjian::DIBLOKIR], true);
            $p = $terakhir[$s->id] ?? null;

            return [
                'id' => $s->id,
                'nama' => $s->siswa->nama,
                'nisn' => $s->siswa->nisn,
                'kelas' => $s->siswa->kelas->whereIn('id', $kelasJadwal)->pluck('nama')->implode(', '),
                'ruang_id' => $s->ruang_id,
                'ruang' => $s->ruang?->nama,
                'meja' => $s->no_meja,
                'status' => $s->status,
                'senyap' => $s->status === SesiUjian::BERLANGSUNG && $kabar !== null && $kabar > $ambangSenyap,
                'pelanggaran' => (int) $s->jumlah_pelanggaran,
                'p_jenis' => $p ? Pelanggaran::label($p->jenis) : null,
                'p_waktu' => $p?->created_at?->format('H:i:s'),
                'p_id' => $p?->id,
                'dijawab' => (int) ($dijawab[$s->id] ?? 0),
                'kabar' => $berjalan ? $kabar : null,
                'platform' => $s->platform,
                'perangkat_dilepas' => $berjalan && $s->token_perangkat === null,
                'tambahan' => (int) $s->tambahan_menit,
                'batas' => $s->batas_selesai?->format('H:i'),
                'alasan' => $s->alasan_selesai,
            ];
        })->sortBy([['nama', 'asc']])->values()->all();

        return [
            'waktu' => $sekarang->format('H:i:s'),
            'total_soal' => JadwalSoal::where('jadwal_id', $jadwal->id)->count(),
            'ringkasan' => $this->ringkas($baris),
            'peserta' => $baris,
        ];
    }

    private function kunci(JadwalUjian|int $jadwal): string
    {
        return 'monitor:'.($jadwal instanceof JadwalUjian ? $jadwal->id : $jadwal);
    }

    // ── Tindakan ────────────────────────────────────────────────────────────

    /** Terblokir → berlangsung. Hitungan pelanggaran diatur ulang ke 0 (riwayat pelanggaran tetap tercatat). */
    public function aktifkanKembali(SesiUjian $sesi, User $pelaku): SesiUjian
    {
        return $this->tindak($sesi, $pelaku, AksiPengawas::AKTIFKAN_KEMBALI, function (SesiUjian $s) {
            if ($s->status !== SesiUjian::DIBLOKIR) {
                throw new KesalahanUjian('Siswa ini tidak sedang terblokir.', 'bukan_blokir');
            }
            $s->update([
                'status' => SesiUjian::BERLANGSUNG,
                'jumlah_pelanggaran' => 0,
            ]);

            return 'hitungan pelanggaran diatur ulang ke 0';
        });
    }

    /** Melepas perangkat terdaftar; perangkat berikutnya yang memasukkan token melanjutkan sesi ini. */
    public function resetPerangkat(SesiUjian $sesi, User $pelaku): SesiUjian
    {
        return $this->tindak($sesi, $pelaku, AksiPengawas::RESET_PERANGKAT, function (SesiUjian $s) {
            if (! in_array($s->status, [SesiUjian::BERLANGSUNG, SesiUjian::DIBLOKIR], true)) {
                throw new KesalahanUjian('Perangkat hanya bisa direset untuk siswa yang sedang mengerjakan.', 'bukan_berjalan');
            }
            $s->update(['token_perangkat' => null]);

            return 'perangkat dilepas';
        });
    }

    public function tambahWaktu(SesiUjian $sesi, User $pelaku, int $menit): SesiUjian
    {
        if ($menit < 1 || $menit > self::MAKS_TAMBAH_MENIT) {
            throw new KesalahanUjian('Tambahan waktu harus 1 sampai '.self::MAKS_TAMBAH_MENIT.' menit.', 'menit_salah', 422);
        }

        return $this->tindak($sesi, $pelaku, AksiPengawas::TAMBAH_WAKTU, function (SesiUjian $s) use ($menit) {
            if ($s->status === SesiUjian::SELESAI) {
                throw new KesalahanUjian('Siswa ini sudah selesai mengerjakan.', 'sudah_selesai');
            }
            $perubahan = ['tambahan_menit' => $s->tambahan_menit + $menit];
            if ($s->batas_selesai) { // sesi yang belum mulai memakai tambahan_menit saat siswa mulai
                $perubahan['batas_selesai'] = $s->batas_selesai->copy()->addMinutes($menit);
            }
            $s->update($perubahan);

            return "+{$menit} menit";
        });
    }

    public function paksaSelesai(SesiUjian $sesi, User $pelaku): SesiUjian
    {
        $s = $this->tindak($sesi, $pelaku, AksiPengawas::PAKSA_SELESAI, function (SesiUjian $s) {
            if (! in_array($s->status, [SesiUjian::BERLANGSUNG, SesiUjian::DIBLOKIR], true)) {
                throw new KesalahanUjian('Hanya siswa yang sedang mengerjakan yang bisa dipaksa selesai.', 'bukan_berjalan');
            }

            return 'diselesaikan pengawas';
        });

        $this->sesi->selesaikan($s, 'dipaksa');
        $this->lupakan($s->jadwal_id);

        return $s->fresh();
    }

    /**
     * Ubah sesi dan catat tindakannya dalam satu transaksi.
     *
     * @param  callable(SesiUjian):string  $ubah  mengubah sesi lalu mengembalikan keterangan untuk catatan
     */
    private function tindak(SesiUjian $sesi, User $pelaku, string $aksi, callable $ubah): SesiUjian
    {
        $hasil = DB::transaction(function () use ($sesi, $pelaku, $aksi, $ubah) {
            $s = SesiUjian::query()->with('jadwal')->lockForUpdate()->findOrFail($sesi->id);
            $detail = $ubah($s);

            AksiPengawas::create(['sesi_id' => $s->id, 'user_id' => $pelaku->id, 'aksi' => $aksi, 'detail' => $detail, 'created_at' => now()]);

            return $s->fresh();
        });

        $this->lupakan($hasil->jadwal_id);

        return $hasil;
    }
}
