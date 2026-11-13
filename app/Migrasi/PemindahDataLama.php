<?php

namespace App\Migrasi;

use App\Models\BankSoal;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\NilaiRapor;
use App\Models\NilaiUs;
use App\Models\Periode;
use App\Models\Siswa;
use App\Models\Soal;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\LayananNilaiLampau;
use App\Services\LayananSoal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Memindahkan data dari database aplikasi lama (koneksi "lama", hanya dibaca): nilai rapor semester lama, nilai Ujian Sekolah,
 * dan bank soal. Siswa dicocokkan lewat NISN dan mapel lewat nama, jadi data induk harus sudah ada (dari EduWeb).
 * Aman diulang: nilai ditimpa pada siswa+semester+mapel yang sama, soal yang sama persis dilewati.
 */
class PemindahDataLama
{
    /** @var array<string,int> */
    public array $hitung = [];

    /** @var list<string> */
    public array $catatan = [];

    /** @var array<int,?Siswa> */
    private array $petaSiswa = [];

    /** @var array<int,?Mapel> */
    private array $petaMapel = [];

    public function __construct(private LayananNilaiLampau $lampau, private LayananSoal $soal, private string $koneksi = 'lama') {}

    private function lama(): \Illuminate\Database\Connection
    {
        return DB::connection($this->koneksi);
    }

    private function tambah(string $kunci, int $n = 1): void
    {
        $this->hitung[$kunci] = ($this->hitung[$kunci] ?? 0) + $n;
    }

    private function catat(string $pesan): void
    {
        if (count($this->catatan) < 200) {
            $this->catatan[] = $pesan;
        }
    }

    // ── Pemetaan ────────────────────────────────────────────────────────────

    private function siswa(int $idLama): ?Siswa
    {
        if (! array_key_exists($idLama, $this->petaSiswa)) {
            $nisn = $this->lama()->table('tbl_siswa')->where('id_siswa', $idLama)->value('nisn');
            $this->petaSiswa[$idLama] = $nisn ? Siswa::where('nisn', trim((string) $nisn))->first() : null;
        }

        return $this->petaSiswa[$idLama];
    }

    private function mapel(int $idLama): ?Mapel
    {
        if (! array_key_exists($idLama, $this->petaMapel)) {
            $nama = trim((string) $this->lama()->table('tbl_mapel')->where('id_mapel', $idLama)->value('nama_mapel'));
            $this->petaMapel[$idLama] = $nama === '' ? null : Mapel::whereRaw('lower(nama) = ?', [mb_strtolower($nama)])->first();
        }

        return $this->petaMapel[$idLama];
    }

    /** Semester lampau yang setara dengan semester lama (dibuat bila belum ada). */
    private function periode(object $t): ?Periode
    {
        $tahun = trim((string) $t->nama_tahun);
        $genap = stripos((string) $t->semester, 'enap') !== false;
        if ($tahun === '') {
            return null;
        }
        $ta = TahunAjaran::firstOrCreate(['nama' => $tahun], ['aktif' => false]);

        return Periode::firstOrCreate(
            ['tahun_ajaran_id' => $ta->id, 'semester' => $genap ? 'genap' : 'ganjil', 'tipe' => 'reguler'],
            ['aktif' => false, 'rapor_di_sini' => false],
        );
    }

    // ── Nilai rapor ─────────────────────────────────────────────────────────

    /** Rumus aplikasi lama: nilai_manual menimpa; NP = 20% ujian + 60% harian + 20% tugas akhir; NK = 70% praktek + 30% adab. */
    public static function hitungLama(object $r): array
    {
        $f = fn ($k) => (float) ($r->$k ?? 0);
        $ujian = $f('nilai_ujian');
        $harian = $f('nilai_harian');
        $tugas = $f('nilai_tugas_akhir');

        if ($f('nilai_manual') > 0 && $harian == 0 && $tugas == 0) {
            return ['np' => (int) round($f('nilai_manual')), 'nk' => 0];
        }
        $np = $f('nilai_np_manual') > 0 ? (int) round($f('nilai_np_manual')) : (int) round($ujian * 0.2 + $harian * 0.6 + $tugas * 0.2);
        $nk = $f('nilai_nk_manual') > 0 ? (int) round($f('nilai_nk_manual')) : (($f('nilai_praktek') + $f('nilai_adab')) > 0 ? (int) round($f('nilai_praktek') * 0.7 + $f('nilai_adab') * 0.3) : 0);

        return ['np' => $np, 'nk' => $nk];
    }

    public function nilaiRapor(User $pelaku): void
    {
        $tahun = $this->lama()->table('tbl_tahun_akademik')->where(fn ($q) => $q->whereNull('tipe')->orWhere('tipe', '!=', 'US'))->get()->keyBy('id_tahun');

        foreach ($this->lama()->table('tbl_nilai_rapor')->orderBy('id_siswa')->cursor() as $r) {
            $t = $tahun[$r->id_tahun] ?? null;
            if (! $t) {
                continue; // semester US (nilainya dibawa lewat bagian "us") atau semester tak dikenal
            }
            $hasil = self::hitungLama($r);
            if ($hasil['np'] <= 0 && $hasil['nk'] <= 0) {
                $this->tambah('rapor_kosong_dilewati');

                continue;
            }
            $siswa = $this->siswa((int) $r->id_siswa);
            $mapel = $this->mapel((int) $r->id_mapel);
            if (! $siswa) {
                $this->tambah('rapor_siswa_tak_ditemukan');
                $this->catat("Siswa lama #{$r->id_siswa} tidak ditemukan berdasarkan NISN.");

                continue;
            }
            if (! $mapel) {
                $this->tambah('rapor_mapel_tak_ditemukan');
                $this->catat("Mapel lama #{$r->id_mapel} tidak ditemukan berdasarkan nama.");

                continue;
            }
            $periode = $this->periode($t);
            $kelas = $siswa->kelas()->get()->sortByDesc(fn ($k) => $k->tahun_ajaran_id.'-'.$k->id)->first();
            if (! $periode || ! $kelas) {
                $this->tambah('rapor_siswa_tanpa_kelas');

                continue;
            }
            if ($periode->rapor_di_sini) {
                $this->tambah('rapor_semester_diisi_di_sini_dilewati'); // jangan menimpa semester yang aktif dipakai

                continue;
            }

            $ada = NilaiRapor::where('siswa_id', $siswa->id)->where('mapel_id', $mapel->id)->where('periode_id', $periode->id)->exists();
            $kelasLama = trim((string) ($this->lama()->table('tbl_siswa')->where('id_siswa', $r->id_siswa)->value('kelas') ?? '')) ?: null;
            $h = $this->lampau->simpan($pelaku, $mapel, $kelas, $periode, [$siswa->id => $hasil], $kelasLama, false);
            $this->tambah($h['disimpan'] === 1 ? ($ada ? 'rapor_diperbarui' : 'rapor_baru') : 'rapor_gagal_simpan');
        }
    }

    // ── Nilai Ujian Sekolah ─────────────────────────────────────────────────

    public function nilaiUs(): void
    {
        // Mapel yang pernah ditandai "masuk XII-2" (catatan_guru = 'US' pada rapor lama).
        $xii2 = [];
        if (Schema::connection($this->koneksi)->hasColumn('tbl_nilai_rapor', 'catatan_guru')) {
            foreach ($this->lama()->table('tbl_nilai_rapor')->where('catatan_guru', 'US')->select('id_siswa', 'id_mapel')->get() as $x) {
                $xii2[$x->id_siswa.'-'.$x->id_mapel] = true;
            }
        }

        foreach ($this->lama()->table('tbl_nilai_us')->cursor() as $u) {
            $nilai = (float) ($u->nilai_us ?? 0);
            $cbt = (float) ($u->nilai_cbt ?? 0);
            if ($nilai <= 0 && $cbt <= 0) {
                continue;
            }
            $siswa = $this->siswa((int) $u->id_siswa);
            $mapel = $this->mapel((int) $u->id_mapel);
            if (! $siswa || ! $mapel) {
                $this->tambah('us_siswa_atau_mapel_tak_ditemukan');

                continue;
            }
            $n = NilaiUs::updateOrCreate(['siswa_id' => $siswa->id, 'mapel_id' => $mapel->id], [
                'nilai' => $nilai > 0 ? round($nilai, 2) : null, 'nilai_cbt' => $cbt > 0 ? round($cbt, 2) : null,
                'keterangan' => ($u->keterangan ?? null) ? mb_substr(trim((string) $u->keterangan), 0, 255) : null,
                'masuk_xii2' => isset($xii2[$u->id_siswa.'-'.$u->id_mapel]),
            ]);
            $this->tambah($n->wasRecentlyCreated ? 'us_baru' : 'us_diperbarui');
        }
    }

    // ── Bank soal ───────────────────────────────────────────────────────────

    public function bankSoal(User $pembuat): void
    {
        $kolomTahun = Schema::connection($this->koneksi)->hasColumn('tbl_soal_pg', 'id_tahun');
        $kelompok = [];
        foreach ($this->lama()->table('tbl_soal_pg')->orderBy('id_soal')->get() as $s) {
            $kelompok[trim((string) $s->mapel).'|'.trim((string) $s->tingkat_kelas)]['pg'][] = $s;
        }
        foreach ($this->lama()->table('tbl_soal_es')->orderBy('id_soal_es')->get() as $s) {
            $kelompok[trim((string) $s->mapel).'|'.trim((string) $s->tingkat_kelas)]['es'][] = $s;
        }

        foreach ($kelompok as $kunci => $isi) {
            [$namaMapel, $tingkat] = explode('|', $kunci);
            $mapel = Mapel::whereRaw('lower(nama) = ?', [mb_strtolower($namaMapel)])->first();
            $tingkat = strtoupper($tingkat);
            if (! $mapel || ! in_array($tingkat, ['X', 'XI', 'XII'], true)) {
                $this->tambah('soal_mapel_atau_tingkat_tak_dikenal', count($isi['pg'] ?? []) + count($isi['es'] ?? []));
                $this->catat("Soal untuk mapel \"{$namaMapel}\" kelas \"{$tingkat}\" dilewati (mapel/tingkat tidak dikenal).");

                continue;
            }

            $bank = BankSoal::firstOrCreate(
                ['mapel_id' => $mapel->id, 'tingkat' => $tingkat, 'nama' => "Migrasi {$mapel->nama} Kelas {$tingkat}"],
                ['jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $pembuat->id],
            );
            $ada = $bank->soal()->pluck('pertanyaan')->map(fn ($p) => md5(trim((string) $p)))->flip()->all();
            $urut = (int) $bank->soal()->max('urutan');

            foreach ($isi['pg'] ?? [] as $s) {
                $tanya = trim((string) $s->pertanyaan);
                $kunciJawab = strtoupper(trim((string) $s->kunci_jawaban));
                if ($tanya === '' || ! in_array($kunciJawab, ['A', 'B', 'C', 'D'], true) || isset($ada[md5($tanya)])) {
                    $this->tambah(isset($ada[md5($tanya)]) ? 'soal_sudah_ada_dilewati' : 'soal_tidak_valid_dilewati');

                    continue;
                }
                $soal = $bank->soal()->create(['tipe' => Soal::PG, 'pertanyaan' => $tanya, 'bobot' => 1, 'status' => 'siap', 'sumber' => 'impor', 'urutan' => ++$urut]);
                $this->soal->setOpsi($soal, ['A' => (string) $s->opsi_a, 'B' => (string) $s->opsi_b, 'C' => (string) $s->opsi_c, 'D' => (string) $s->opsi_d], $kunciJawab);
                $ada[md5($tanya)] = true;
                $this->tambah('soal_pg_baru');
                if (! empty($s->gambar_pg)) {
                    $this->tambah('soal_gambar_tidak_dipindah');
                }
            }
            foreach ($isi['es'] ?? [] as $s) {
                $tanya = trim((string) $s->pertanyaan_es);
                if ($tanya === '' || isset($ada[md5($tanya)])) {
                    $this->tambah($tanya === '' ? 'soal_tidak_valid_dilewati' : 'soal_sudah_ada_dilewati');

                    continue;
                }
                $bank->soal()->create(['tipe' => Soal::ESAI, 'pertanyaan' => $tanya, 'bobot' => 1, 'status' => 'siap', 'sumber' => 'impor', 'urutan' => ++$urut]);
                $ada[md5($tanya)] = true;
                $this->tambah('soal_esai_baru');
                if (! empty($s->gambar_es)) {
                    $this->tambah('soal_gambar_tidak_dipindah');
                }
            }
        }
    }
}
