<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\JadwalUjian;
use App\Models\KunciNilai;
use App\Models\SesiUjian;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Rekap nilai satu jadwal, kunci nilai, dan penahanan hasil. */
class LayananRekap
{
    public function __construct(private LayananSesi $sesi) {}

    /**
     * @param  list<int>|null  $kelas  batasi ke kelas-kelas ini (null = semua peserta jadwal)
     * @return array{baris: list<array<string,mixed>>, ringkasan: array<string,mixed>, kkm:int, kunci: array<string,mixed>}
     */
    public function data(JadwalUjian $jadwal, ?array $kelas = null): array
    {
        $this->sesi->sapuKedaluwarsa(); // sesi yang waktunya habis ditutup dan dinilai dulu (tanpa cron)

        $kelasJadwal = $jadwal->kelas()->pluck('kelas.id')->all();
        $kkm = (int) $jadwal->mapel->kkm;
        $selesaiJadwal = $jadwal->status === JadwalUjian::SELESAI || $jadwal->jendela() === 'ditutup';

        $sesi = SesiUjian::query()->where('jadwal_id', $jadwal->id)
            ->when($kelas !== null, fn ($q) => $q->whereIn('siswa_id', DB::table('keanggotaan_kelas')->whereIn('kelas_id', $kelas)->select('siswa_id')))
            ->with(['siswa:id,nama,nisn', 'siswa.kelas:id,nama', 'ruang:id,nama', 'hasil'])->get();
        $kelasKunci = $kelas ?? $kelasJadwal;
        $kunci = KunciNilai::where('jadwal_id', $jadwal->id)->whereIn('kelas_id', $kelasKunci)->with('pengunci:id,nama')->orderByDesc('dikunci_pada')->get();

        $baris = $sesi->map(function (SesiUjian $s) use ($kelasJadwal, $kkm, $selesaiJadwal) {
            $h = $s->hasil;
            $nilai = $h?->skor_akhir !== null ? (float) $h->skor_akhir : null;

            $keterangan = match (true) {
                $s->status === SesiUjian::SELESAI && $h?->status_koreksi === 'belum_dikoreksi' => 'Belum dikoreksi',
                $nilai !== null => $nilai >= $kkm ? 'Tuntas' : 'Belum tuntas',
                $s->status === SesiUjian::BELUM => $selesaiJadwal ? 'Tidak mengikuti' : 'Belum mulai',
                default => 'Sedang mengerjakan',
            };

            return [
                'sesi_id' => $s->id,
                'nama' => $s->siswa->nama,
                'nisn' => $s->siswa->nisn,
                'kelas' => $s->siswa->kelas->whereIn('id', $kelasJadwal)->pluck('nama')->implode(', '),
                'ruang' => $s->ruang?->nama,
                'status' => $s->status,
                'alasan' => $s->alasan_selesai,
                'pelanggaran' => (int) $s->jumlah_pelanggaran,
                'benar' => $h?->benar,
                'salah' => $h?->salah,
                'kosong' => $h?->kosong,
                'skor_pg' => $h?->skor_pg !== null ? (float) $h->skor_pg : null,
                'skor_esai' => $h?->skor_esai !== null ? (float) $h->skor_esai : null,
                'skor_akhir' => $nilai,
                'koreksi' => $h?->status_koreksi,
                'keterangan' => $keterangan,
            ];
        })->sortBy([['kelas', 'asc'], ['nama', 'asc']])->values()->all();

        $nilai = array_values(array_filter(array_column($baris, 'skor_akhir'), fn ($n) => $n !== null));
        $hitung = fn (string $k) => count(array_filter($baris, fn ($b) => $b['keterangan'] === $k));

        return [
            'kkm' => $kkm,
            'kunci' => [
                'terkunci' => $kelasKunci !== [] && $kunci->count() === count(array_unique($kelasKunci)),
                'sebagian' => $kunci->isNotEmpty() && $kunci->count() < count(array_unique($kelasKunci)),
                'pada' => $kunci->first()?->dikunci_pada,
                'oleh' => $kunci->first()?->pengunci?->nama,
            ],
            'baris' => $baris,
            'ringkasan' => [
                'peserta' => count($baris),
                'selesai' => count(array_filter($baris, fn ($b) => $b['status'] === SesiUjian::SELESAI)),
                'tidak_mengikuti' => $hitung('Tidak mengikuti'),
                'sedang' => count(array_filter($baris, fn ($b) => in_array($b['status'], [SesiUjian::BERLANGSUNG, SesiUjian::DIBLOKIR], true))),
                'belum_dikoreksi' => $hitung('Belum dikoreksi'),
                'dinilai' => count($nilai),
                'rata_rata' => $nilai ? round(array_sum($nilai) / count($nilai), 2) : null,
                'tertinggi' => $nilai ? max($nilai) : null,
                'terendah' => $nilai ? min($nilai) : null,
                'di_bawah_kkm' => count(array_filter($nilai, fn ($n) => $n < $kkm)),
            ],
        ];
    }

    /**
     * Nilai kelas-kelas dalam lingkup boleh dikunci bila tidak ada yang sedang mengerjakan dan tidak ada esai yang
     * belum dikoreksi (di kelas-kelas itu).
     *
     * @param  list<int>|null  $kelas  null = semua kelas jadwal
     *
     * @throws KesalahanUjian
     */
    public function kunci(JadwalUjian $jadwal, User $pelaku, ?array $kelas = null): void
    {
        DB::transaction(function () use ($jadwal, $pelaku, $kelas) {
            $j = JadwalUjian::query()->lockForUpdate()->findOrFail($jadwal->id);
            $lingkup = array_values(array_unique($kelas ?? $j->kelas()->pluck('kelas.id')->map(fn ($k) => (int) $k)->all()));

            $belumTerkunci = array_values(array_diff($lingkup, KunciNilai::where('jadwal_id', $j->id)->whereIn('kelas_id', $lingkup)->pluck('kelas_id')->map(fn ($k) => (int) $k)->all()));
            if ($belumTerkunci === []) {
                throw new KesalahanUjian('Nilai ini sudah dikunci.', 'sudah_dikunci');
            }

            $peserta = fn () => SesiUjian::where('jadwal_id', $j->id)
                ->whereIn('siswa_id', DB::table('keanggotaan_kelas')->whereIn('kelas_id', $belumTerkunci)->select('siswa_id'));

            $sedang = $peserta()->whereIn('status', [SesiUjian::BERLANGSUNG, SesiUjian::DIBLOKIR])->count();
            if ($sedang > 0) {
                throw new KesalahanUjian("Masih ada {$sedang} siswa yang sedang mengerjakan. Tunggu sampai selesai atau selesaikan lewat monitor.", 'masih_berjalan');
            }

            $belum = $peserta()->whereHas('hasil', fn ($q) => $q->where('status_koreksi', 'belum_dikoreksi'))->count();
            if ($belum > 0) {
                throw new KesalahanUjian("Masih ada {$belum} siswa dengan esai yang belum dikoreksi.", 'belum_dikoreksi');
            }

            foreach ($belumTerkunci as $kelasId) {
                KunciNilai::create(['jadwal_id' => $j->id, 'kelas_id' => $kelasId, 'dikunci_pada' => now(), 'dikunci_oleh' => $pelaku->id]);
            }
            AuditLog::catat('rekap.kunci', "jadwal {$j->id} kelas ".implode(',', $belumTerkunci), $pelaku);
        });
    }

    /**
     * Membuka kunci kelas-kelas dalam lingkup agar nilai bisa diubah/ditambah, lalu dikunci lagi.
     *
     * @param  list<int>|null  $kelas  null = semua kelas jadwal
     *
     * @throws KesalahanUjian
     */
    public function bukaKunci(JadwalUjian $jadwal, User $pelaku, ?array $kelas = null): void
    {
        $lingkup = $kelas ?? $jadwal->kelas()->pluck('kelas.id')->map(fn ($k) => (int) $k)->all();
        $dibuka = KunciNilai::where('jadwal_id', $jadwal->id)->whereIn('kelas_id', $lingkup)->delete();
        if ($dibuka === 0) {
            throw new KesalahanUjian('Nilai ini tidak sedang dikunci.', 'tidak_dikunci');
        }

        AuditLog::catat('rekap.buka_kunci', "jadwal {$jadwal->id} kelas ".implode(',', $lingkup), $pelaku);
    }

    /** Hasil yang ditahan baru terlihat siswa setelah ini. */
    public function tampilkanKeSiswa(JadwalUjian $jadwal, User $pelaku): void
    {
        if ($jadwal->tampilkan_hasil === 'langsung') {
            throw new KesalahanUjian('Hasil jadwal ini sudah tampil kepada siswa.', 'sudah_tampil');
        }

        $jadwal->update(['tampilkan_hasil' => 'langsung']);
        AuditLog::catat('rekap.tampilkan', "jadwal {$jadwal->id}", $pelaku);
    }
}
