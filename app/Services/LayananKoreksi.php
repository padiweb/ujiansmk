<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\JadwalUjian;
use App\Models\Jawaban;
use App\Models\Kelas;
use App\Models\Periode;
use App\Models\Pengampu;
use App\Models\SesiUjian;
use App\Models\Soal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Koreksi esai oleh guru: hak akses, kemajuan, penyimpanan nilai, dan penghitungan ulang skor akhir. */
class LayananKoreksi
{
    public function __construct(private LayananPenilaian $penilaian, private LayananPaket $paket, private LayananSesi $sesi, private LayananKunciNilai $kunci) {}

    // ── Hak akses ───────────────────────────────────────────────────────────

    /** Jadwal (aktif atau selesai) yang boleh ditangani pengguna: admin semua, guru hanya mapel DAN kelas yang diampunya. */
    public function jadwalUntuk(User $user): Builder
    {
        $kueri = JadwalUjian::query()->whereIn('status', [JadwalUjian::AKTIF, JadwalUjian::SELESAI]);

        return match ($user->role) {
            'admin' => $kueri,
            'guru' => $kueri->where(fn ($q) => $q->whereNull('kegiatan_id')->orWhereHas('kegiatan', fn ($k) => $k->where('aktif', true)))->whereExists(fn ($q) => $q->selectRaw('1')->from('pengampu')
                ->whereColumn('pengampu.mapel_id', 'jadwal_ujian.mapel_id')->where('pengampu.guru_id', $user->id)
                ->whereIn('pengampu.kelas_id', fn ($k) => $k->select('kelas_id')->from('jadwal_kelas')->whereColumn('jadwal_kelas.jadwal_id', 'jadwal_ujian.id'))),
            default => $kueri->whereRaw('1 = 0'),
        };
    }

    public function boleh(User $user, JadwalUjian $jadwal): bool
    {
        return $this->jadwalUntuk($user)->whereKey($jadwal->id)->exists();
    }

    /**
     * Kelas jadwal ini yang boleh ditangani pengguna; $pilihan (id kelas) mempersempit ke satu kelas.
     *
     * @return list<int>
     */
    public function lingkup(User $user, JadwalUjian $jadwal, int|string|null $pilihan = null): array
    {
        $kelas = $jadwal->kelas()->pluck('kelas.id')->map(fn ($k) => (int) $k)->all();
        if ($user->role !== 'admin') {
            $diampu = $user->role === 'guru'
                ? Pengampu::where('guru_id', $user->id)->where('mapel_id', $jadwal->mapel_id)->pluck('kelas_id')->map(fn ($k) => (int) $k)->all()
                : [];
            $kelas = array_values(array_intersect($kelas, $diampu));
        }
        if (filled($pilihan)) {
            $kelas = array_values(array_intersect($kelas, [(int) $pilihan]));
        }

        return $kelas;
    }

    /**
     * Pasangan jadwal-kelas yang boleh ditangani pengguna pada semester tertentu, untuk halaman pilihan.
     *
     * @return Collection<int,array{jadwal:JadwalUjian,kelas:Kelas}>
     */
    public function unit(User $user, ?int $periodeId, bool $hanyaEsai = false): Collection
    {
        $jadwal = $this->jadwalUntuk($user)->with('mapel', 'kelas')
            ->when($periodeId, fn ($q) => $q->where('periode_id', $periodeId))
            ->when($hanyaEsai, fn ($q) => $q->whereHas('soalBeku.soal', fn ($s) => $s->where('tipe', Soal::ESAI)->withTrashed()))
            ->orderByRaw("case status when 'aktif' then 0 else 1 end")->orderByDesc('mulai_pada')->get();

        $diampu = $user->role === 'guru'
            ? Pengampu::where('guru_id', $user->id)->get(['mapel_id', 'kelas_id'])->groupBy('mapel_id')->map(fn ($g) => $g->pluck('kelas_id')->map(fn ($k) => (int) $k)->all())
            : null;

        $hasil = collect();
        foreach ($jadwal as $j) {
            foreach ($j->kelas->sortBy('nama') as $k) {
                if ($diampu === null || in_array($k->id, $diampu[$j->mapel_id] ?? [], true)) {
                    $hasil->push(['jadwal' => $j, 'kelas' => $k]);
                }
            }
        }

        return $hasil;
    }

    /**
     * Data halaman pilihan mapel dan kelas. Semester bawaan: yang sedang aktif ("semua" = tanpa batas semester).
     *
     * @return array<string,mixed>
     */
    public function dataPilihan(User $user, ?string $periode, ?string $mapel, ?string $kelas, bool $hanyaEsai): array
    {
        $periodeId = $periode === 'semua' ? null : (filled($periode) ? (int) $periode : Periode::where('aktif', true)->value('id'));
        $semua = $this->unit($user, $periodeId, $hanyaEsai);

        return [
            'daftarPeriode' => Periode::with('tahunAjaran')->orderByDesc('tahun_ajaran_id')->orderBy('semester')->get(),
            'periodeId' => $periodeId,
            'opsiMapel' => $semua->map(fn ($u) => $u['jadwal']->mapel)->unique('id')->sortBy('nama')->values(),
            'opsiKelas' => $semua->map(fn ($u) => $u['kelas'])->unique('id')->sortBy('nama')->values(),
            'mapelId' => filled($mapel) ? (int) $mapel : null,
            'kelasId' => filled($kelas) ? (int) $kelas : null,
            'unit' => $semua
                ->when(filled($mapel), fn ($c) => $c->filter(fn ($u) => $u['jadwal']->mapel_id === (int) $mapel))
                ->when(filled($kelas), fn ($c) => $c->filter(fn ($u) => $u['kelas']->id === (int) $kelas))
                ->sortBy([fn ($a, $b) => strcmp($a['jadwal']->mapel->nama, $b['jadwal']->mapel->nama), fn ($a, $b) => strnatcmp($a['kelas']->nama, $b['kelas']->nama)])
                ->values(),
        ];
    }

    /** Sesi peserta dari kelas-kelas tertentu pada jadwal (null = semua peserta). @param list<int>|null $kelas */
    public function sesiLingkup(JadwalUjian $jadwal, ?array $kelas): Builder
    {
        return SesiUjian::query()->where('jadwal_id', $jadwal->id)
            ->when($kelas !== null, fn ($q) => $q->whereIn('siswa_id', DB::table('keanggotaan_kelas')->whereIn('kelas_id', $kelas)->select('siswa_id')));
    }

    // ── Data ────────────────────────────────────────────────────────────────

    /**
     * Soal esai jadwal beserta kemajuan koreksinya.
     *
     * @return list<array{soal:Soal,bobot:float,total:int,dinilai:int,kosong:int}>
     */
    public function soalEsai(JadwalUjian $jadwal, ?array $kelas = null): array
    {
        $this->sesi->sapuKedaluwarsa();

        $bobot = $this->paket->kunci($jadwal)['esai'];
        $untuk = \App\Models\JadwalSoal::where('jadwal_id', $jadwal->id)->whereIn('soal_id', array_keys($bobot) ?: [0])->pluck('untuk_kelas', 'soal_id');
        if ($kelas !== null) {
            // Soal khusus kelas lain (bank guru pengampu lain) tidak ditampilkan untuk kelas ini.
            $bobot = array_filter($bobot, fn ($b, $id) => empty($untuk[$id]) || array_intersect($kelas, array_map('intval', (array) $untuk[$id])) !== [], ARRAY_FILTER_USE_BOTH);
        }
        if ($bobot === []) {
            return [];
        }

        $selesai = $this->sesiLingkup($jadwal, $kelas)->where('status', SesiUjian::SELESAI)->pluck('id');
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
                'kosong' => (empty($untuk[$soalId]) ? $selesai->count() : $this->sesiLingkup($jadwal, array_values(array_intersect($kelas ?? array_map('intval', (array) $untuk[$soalId]), array_map('intval', (array) $untuk[$soalId]))))->where('status', SesiUjian::SELESAI)->count()) - $total,
            ];
        }

        return $hasil;
    }

    /** Jawaban esai yang berisi (bukan kosong) dari sesi yang sudah selesai untuk satu soal. */
    public function jawabanSoal(JadwalUjian $jadwal, Soal $soal, ?array $kelas = null): Builder
    {
        return $this->terisi()->where('soal_id', $soal->id)
            ->whereIn('sesi_id', $this->sesiLingkup($jadwal, $kelas)->where('status', SesiUjian::SELESAI)->select('id'))
            ->with('sesi.siswa:id,nama,nisn', 'sesi.siswa.kelas:id,nama')
            ->orderBy(SesiUjian::query()->join('siswa', 'siswa.id', '=', 'sesi_ujian.siswa_id')->whereColumn('sesi_ujian.id', 'jawaban.sesi_id')->select('siswa.nama'))
            ->orderBy('id');
    }

    /** Apakah soal ini soal esai milik jadwal (bukan soal jadwal lain). */
    public function soalMilik(JadwalUjian $jadwal, Soal $soal): bool
    {
        return array_key_exists($soal->id, $this->paket->kunci($jadwal)['esai']);
    }

    public function jawabanMilik(JadwalUjian $jadwal, Jawaban $jawaban, ?array $kelas = null): bool
    {
        return $this->soalMilik($jadwal, $jawaban->soal)
            && $this->sesiLingkup($jadwal, $kelas)->where('id', $jawaban->sesi_id)->where('status', SesiUjian::SELESAI)->exists();
    }

    /** @return array{total:int,dinilai:int} kemajuan satu soal */
    public function kemajuan(JadwalUjian $jadwal, Soal $soal, ?array $kelas = null): array
    {
        $q = $this->jawabanSoal($jadwal, $soal, $kelas)->reorder();

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
            $sesi = SesiUjian::with('jadwal')->findOrFail($terkunci->sesi_id);

            if ($sesi->status !== SesiUjian::SELESAI) {
                throw new KesalahanUjian('Jawaban ini belum bisa dikoreksi karena siswa belum selesai mengerjakan.', 'belum_selesai');
            }
            if (trim((string) $terkunci->teks_esai) === '') {
                throw new KesalahanUjian('Jawaban kosong otomatis bernilai 0.', 'kosong', 422);
            }

            // Nilai yang diubah pada kelas yang sudah dikunci membuka kuncinya; guru mengunci lagi setelah selesai.
            $this->kunci->bukaOtomatis($sesi, $pengoreksi);

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

    /**
     * Beri nilai yang sama ke jawaban satu soal pada kelas-kelas tertentu. Bawaan hanya mengisi yang belum dinilai.
     *
     * @param  list<int>|null  $kelas
     * @return int jumlah jawaban yang diberi nilai
     *
     * @throws KesalahanUjian
     */
    public function nilaiSemua(JadwalUjian $jadwal, Soal $soal, float $nilai, bool $timpa, ?array $kelas, User $pengoreksi): int
    {
        if ($nilai < 0 || $nilai > 100) {
            throw new KesalahanUjian('Nilai harus antara 0 sampai 100.', 'nilai_salah', 422);
        }

        $kueri = $this->jawabanSoal($jadwal, $soal, $kelas);
        if (! $timpa) {
            $kueri->whereNull('nilai_esai');
        }

        $jumlah = 0;
        foreach ($kueri->get() as $jawaban) {
            $this->simpanNilai($jawaban, $nilai, $pengoreksi);
            $jumlah++;
        }
        if ($jumlah > 0) {
            AuditLog::catat('koreksi.nilai_semua', "jadwal {$jadwal->id} soal {$soal->id}: {$nilai} untuk {$jumlah} jawaban".($timpa ? ' (menimpa)' : ''), $pengoreksi);
        }

        return $jumlah;
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
