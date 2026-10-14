<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\JadwalUjian;
use App\Models\Jawaban;
use App\Models\Pengampu;
use App\Models\SesiUjian;
use App\Models\Soal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Koreksi esai oleh guru: hak akses, kemajuan, penyimpanan nilai, dan penghitungan ulang skor akhir. */
class LayananKoreksi
{
    public function __construct(private LayananPenilaian $penilaian, private LayananPaket $paket, private LayananSesi $sesi) {}

    // ── Hak akses ───────────────────────────────────────────────────────────

    /** Jadwal (aktif atau selesai) yang boleh dikoreksi pengguna: admin semua, guru mapel yang diampunya. */
    public function jadwalUntuk(User $user): Builder
    {
        $kueri = JadwalUjian::query()->whereIn('status', [JadwalUjian::AKTIF, JadwalUjian::SELESAI]);

        return match ($user->role) {
            'admin' => $kueri,
            'guru' => $kueri->whereIn('mapel_id', Pengampu::where('guru_id', $user->id)->select('mapel_id')),
            default => $kueri->whereRaw('1 = 0'),
        };
    }

    public function boleh(User $user, JadwalUjian $jadwal): bool
    {
        return $this->jadwalUntuk($user)->whereKey($jadwal->id)->exists();
    }

    // ── Data ────────────────────────────────────────────────────────────────

    /**
     * Soal esai jadwal beserta kemajuan koreksinya.
     *
     * @return list<array{soal:Soal,bobot:float,total:int,dinilai:int,kosong:int}>
     */
    public function soalEsai(JadwalUjian $jadwal): array
    {
        $this->sesi->sapuKedaluwarsa();

        $bobot = $this->paket->kunci($jadwal)['esai'];
        if ($bobot === []) {
            return [];
        }

        $selesai = SesiUjian::where('jadwal_id', $jadwal->id)->where('status', SesiUjian::SELESAI)->pluck('id');
        $soal = Soal::withTrashed()->whereIn('id', array_keys($bobot))->get()->keyBy('id');

        $terisi = $this->terisi()->whereIn('sesi_id', $selesai)->selectRaw('soal_id, count(*) as jumlah, count(nilai_esai) as dinilai')
            ->whereIn('soal_id', array_keys($bobot))->groupBy('soal_id')->get()->keyBy('soal_id');

        $hasil = [];
        foreach ($bobot as $soalId => $b) {
            $row = $terisi[$soalId] ?? null;
            $total = (int) ($row->jumlah ?? 0);
            $hasil[] = [
                'soal' => $soal[$soalId],
                'bobot' => $b,
                'total' => $total,
                'dinilai' => (int) ($row->dinilai ?? 0),
                'kosong' => $selesai->count() - $total,
            ];
        }

        return $hasil;
    }

    /** Jawaban esai yang berisi (bukan kosong) dari sesi yang sudah selesai untuk satu soal. */
    public function jawabanSoal(JadwalUjian $jadwal, Soal $soal): Builder
    {
        return $this->terisi()->where('soal_id', $soal->id)
            ->whereIn('sesi_id', SesiUjian::where('jadwal_id', $jadwal->id)->where('status', SesiUjian::SELESAI)->select('id'))
            ->with('sesi.siswa:id,nama,nisn')->orderBy('id');
    }

    /** Apakah soal ini soal esai milik jadwal (bukan soal jadwal lain). */
    public function soalMilik(JadwalUjian $jadwal, Soal $soal): bool
    {
        return array_key_exists($soal->id, $this->paket->kunci($jadwal)['esai']);
    }

    public function jawabanMilik(JadwalUjian $jadwal, Jawaban $jawaban): bool
    {
        return $this->soalMilik($jadwal, $jawaban->soal)
            && SesiUjian::where('id', $jawaban->sesi_id)->where('jadwal_id', $jadwal->id)->where('status', SesiUjian::SELESAI)->exists();
    }

    /** @return array{total:int,dinilai:int} kemajuan satu soal */
    public function kemajuan(JadwalUjian $jadwal, Soal $soal): array
    {
        $q = $this->jawabanSoal($jadwal, $soal)->reorder();

        return ['total' => (clone $q)->count(), 'dinilai' => (clone $q)->whereNotNull('nilai_esai')->count()];
    }

    // ── Tindakan ────────────────────────────────────────────────────────────

    /**
     * Simpan (atau hapus, bila $nilai null) nilai esai satu jawaban lalu hitung ulang skor akhir siswa.
     *
     * @throws KesalahanUjian
     */
    public function simpanNilai(Jawaban $jawaban, ?float $nilai, User $pengoreksi): Jawaban
    {
        if ($nilai !== null && ($nilai < 0 || $nilai > 100)) {
            throw new KesalahanUjian('Nilai harus antara 0 sampai 100.', 'nilai_salah', 422);
        }

        return DB::transaction(function () use ($jawaban, $nilai, $pengoreksi) {
            $terkunci = Jawaban::query()->lockForUpdate()->findOrFail($jawaban->id);
            $sesi = SesiUjian::findOrFail($terkunci->sesi_id);

            if ($sesi->status !== SesiUjian::SELESAI) {
                throw new KesalahanUjian('Jawaban ini belum bisa dikoreksi karena siswa belum selesai mengerjakan.', 'belum_selesai');
            }
            if (trim((string) $terkunci->teks_esai) === '') {
                throw new KesalahanUjian('Jawaban kosong otomatis bernilai 0.', 'kosong', 422);
            }

            $terkunci->update([
                'nilai_esai' => $nilai,
                'dikoreksi_oleh' => $nilai === null ? null : $pengoreksi->id,
                'dikoreksi_pada' => $nilai === null ? null : now(),
            ]);

            $this->penilaian->hitungEsai($sesi);
            AuditLog::catat('koreksi.nilai', "jawaban {$terkunci->id} sesi {$sesi->id}: ".($nilai ?? 'dihapus'), $pengoreksi);

            return $terkunci->fresh();
        });
    }

    /** Simpan saran "Otomatis" (tidak menyentuh nilai resmi). */
    public function simpanSaran(Jawaban $jawaban, float $nilai, ?string $catatan): void
    {
        $jawaban->update(['saran_nilai' => $nilai, 'saran_catatan' => $catatan]);
    }

    private function terisi(): Builder
    {
        return Jawaban::query()->whereNotNull('teks_esai')->whereRaw("trim(teks_esai) != ''");
    }
}
