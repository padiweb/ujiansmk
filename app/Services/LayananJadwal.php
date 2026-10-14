<?php

namespace App\Services;

use App\Models\JadwalSoal;
use App\Models\JadwalUjian;
use App\Models\SesiUjian;
use App\Models\Siswa;
use App\Models\Soal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LayananJadwal
{
    private const ALFABET_TOKEN = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private LayananGambarUjian $gambar,
        private LayananPaket $paket,
        private LayananSesi $sesi,
    ) {}

    /** Peserta yang berhak: siswa aktif di kelas-kelas jadwal. @return Collection<int,int> id siswa */
    public function pesertaBerhak(JadwalUjian $jadwal): Collection
    {
        return Siswa::query()
            ->where('status', 'aktif')
            ->whereHas('kelas', fn ($q) => $q->whereIn('kelas.id', $jadwal->kelas()->pluck('kelas.id')))
            ->pluck('siswa.id');
    }

    /**
     * Pilih soal dari bank-bank jadwal tanpa menyimpan apa pun.
     *
     * @return array{pg: Collection<int,Soal>, esai: Collection<int,Soal>}
     *
     * @throws KesalahanJadwal
     */
    public function pilihSoal(JadwalUjian $jadwal): array
    {
        $hasil = ['pg' => collect(), 'esai' => collect()];

        foreach ($jadwal->bank()->with('bankSoal')->get() as $jb) {
            $bank = $jb->bankSoal;
            if (! $bank || $bank->trashed()) {
                throw new KesalahanJadwal('Salah satu bank soal sudah dihapus. Ubah jadwal dan pilih bank yang masih ada.');
            }

            $permintaan = [
                'pg' => [Soal::PG, $jb->jumlah_pg, 'PG'],
                'esai' => [Soal::ESAI, $jb->jumlah_esai, 'esai'],
            ];

            foreach ($permintaan as $kunci => [$tipe, $diminta, $label]) {
                $siap = $bank->soal()->where('tipe', $tipe)->where('status', 'siap')->get();

                if ($diminta !== null) {
                    if ($siap->count() < $diminta) {
                        throw new KesalahanJadwal("Bank \"{$bank->nama}\" hanya punya {$siap->count()} soal {$label} yang siap, sedangkan diminta {$diminta}.");
                    }
                    $siap = $siap->shuffle()->take($diminta)->sortBy([['urutan', 'asc'], ['id', 'asc']])->values();
                }

                $hasil[$kunci] = $hasil[$kunci]->concat($siap);
            }
        }

        return ['pg' => $hasil['pg']->values(), 'esai' => $hasil['esai']->values()];
    }

    /**
     * Aktifkan jadwal: periksa kelayakan, bekukan soal, buat token dan sesi peserta.
     *
     * @return array{pg:int,esai:int,peserta:int}
     *
     * @throws KesalahanJadwal
     */
    public function aktifkan(JadwalUjian $jadwal): array
    {
        if (! $jadwal->berstatus(JadwalUjian::DRAF)) {
            throw new KesalahanJadwal('Hanya jadwal berstatus draf yang bisa diaktifkan.');
        }
        if ($jadwal->tutup_pada->lte(now())) {
            throw new KesalahanJadwal('Waktu tutup sudah lewat. Ubah waktu tutup ke waktu mendatang.');
        }
        if ($jadwal->kelas()->doesntExist()) {
            throw new KesalahanJadwal('Pilih minimal satu kelas peserta.');
        }
        if ($jadwal->bank()->doesntExist()) {
            throw new KesalahanJadwal('Pilih minimal satu bank soal.');
        }

        $soal = $this->pilihSoal($jadwal);
        if ($soal['pg']->isEmpty() && $soal['esai']->isEmpty()) {
            throw new KesalahanJadwal('Bank yang dipilih belum punya soal berstatus siap.');
        }
        if ($soal['esai']->isEmpty() && $jadwal->bobot_pg < 100) {
            throw new KesalahanJadwal("Bobot esai diatur {$jadwal->bobotEsai()}% padahal tidak ada soal esai. Ubah bobot PG menjadi 100.");
        }

        $peserta = $this->pesertaBerhak($jadwal);
        if ($peserta->isEmpty()) {
            throw new KesalahanJadwal('Tidak ada siswa aktif di kelas yang dipilih.');
        }

        DB::transaction(function () use ($jadwal, $soal, $peserta) {
            // PG dulu, lalu esai. Nomor urut dari indeks (fungsi panah menangkap variabel dengan salinan).
            $baris = $soal['pg']->concat($soal['esai'])->values()->map(fn (Soal $s, int $i) => [
                'jadwal_id' => $jadwal->id, 'soal_id' => $s->id, 'urutan' => $i + 1, 'bobot' => $s->bobot,
            ]);
            foreach ($baris->chunk(500) as $potong) {
                JadwalSoal::insert($potong->all());
            }

            $sekarang = now();
            foreach ($peserta->chunk(500) as $potong) {
                SesiUjian::insert($potong->map(fn ($id) => [
                    'jadwal_id' => $jadwal->id, 'siswa_id' => $id, 'status' => SesiUjian::BELUM,
                    'jumlah_pelanggaran' => 0, 'tambahan_menit' => 0, 'created_at' => $sekarang, 'updated_at' => $sekarang,
                ])->all());
            }

            $jadwal->update(['status' => JadwalUjian::AKTIF, 'token' => $this->tokenBaru(), 'diaktifkan_pada' => $sekarang]);
        });

        // Di luar transaksi: menyalin berkas bukan bagian dari basis data. Paket soal dibangun saat dibutuhkan.
        $this->paket->lupakan($jadwal);
        $this->gambar->salinKePublik($jadwal->refresh());

        return ['pg' => $soal['pg']->count(), 'esai' => $soal['esai']->count(), 'peserta' => $peserta->count()];
    }

    /** Batalkan aktivasi selama belum ada siswa yang mulai: kembali ke draf, soal dan sesi dilepas. */
    public function batalkanAktivasi(JadwalUjian $jadwal): void
    {
        if (! $jadwal->berstatus(JadwalUjian::AKTIF)) {
            throw new KesalahanJadwal('Jadwal ini tidak sedang aktif.');
        }
        if ($jadwal->sesi()->where('status', '!=', SesiUjian::BELUM)->exists()) {
            throw new KesalahanJadwal('Sudah ada siswa yang memulai ujian, aktivasi tidak bisa dibatalkan.');
        }

        $this->gambar->bersihkan($jadwal);
        $this->paket->lupakan($jadwal);

        DB::transaction(function () use ($jadwal) {
            $jadwal->sesi()->delete();
            $jadwal->soalBeku()->delete();
            $jadwal->update(['status' => JadwalUjian::DRAF, 'token' => null, 'media_kode' => null, 'diaktifkan_pada' => null]);
        });
    }

    public function regenerasiToken(JadwalUjian $jadwal): string
    {
        if (! $jadwal->berstatus(JadwalUjian::AKTIF)) {
            throw new KesalahanJadwal('Token hanya ada untuk jadwal yang aktif.');
        }

        $jadwal->update(['token' => $this->tokenBaru()]);

        return $jadwal->token;
    }

    /**
     * Akhiri jadwal. Sesi yang masih berlangsung atau terblokir dipaksa selesai dan dinilai dari jawaban
     * yang sudah tersinkron. Sesi yang belum pernah dimulai tetap berstatus belum.
     *
     * @return int jumlah sesi yang dipaksa selesai
     */
    public function selesaikan(JadwalUjian $jadwal): int
    {
        if (! $jadwal->berstatus(JadwalUjian::AKTIF)) {
            throw new KesalahanJadwal('Jadwal ini tidak sedang aktif.');
        }

        $dipaksa = 0;
        $jadwal->sesi()->whereIn('status', [SesiUjian::BERLANGSUNG, SesiUjian::DIBLOKIR])->each(function (SesiUjian $s) use (&$dipaksa) {
            $this->sesi->selesaikan($s, 'dipaksa');
            $dipaksa++;
        });

        $jadwal->update(['status' => JadwalUjian::SELESAI]);
        $this->gambar->bersihkan($jadwal);
        $this->paket->lupakan($jadwal);

        return $dipaksa;
    }

    public function tokenBaru(): string
    {
        $maks = strlen(self::ALFABET_TOKEN) - 1;

        do {
            $token = '';
            for ($i = 0; $i < 5; $i++) {
                $token .= self::ALFABET_TOKEN[random_int(0, $maks)];
            }
        } while (JadwalUjian::where('token', $token)->exists());

        return $token;
    }
}
