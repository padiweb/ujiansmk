<?php

namespace Tests\Feature;

use App\Migrasi\PemindahDataLama;
use App\Models\BankSoal;
use App\Models\HasilUjianLama;
use App\Models\NilaiRapor;
use App\Models\NilaiUs;
use App\Models\Periode;
use App\Models\RaporSiswa;
use App\Models\Soal;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class MigrasiLamaTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 2);
        config(['database.connections.lama' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('lama');
        $s = Schema::connection('lama');
        $s->create('tbl_siswa', function (Blueprint $t) {
            $t->integer('id_siswa')->primary();
            $t->string('nisn')->nullable();
            $t->string('kelas')->nullable();
        });
        $s->create('tbl_mapel', function (Blueprint $t) {
            $t->integer('id_mapel')->primary();
            $t->string('nama_mapel');
        });
        $s->create('tbl_tahun_akademik', function (Blueprint $t) {
            $t->integer('id_tahun')->primary();
            $t->string('nama_tahun');
            $t->string('semester')->nullable();
            $t->string('tipe')->nullable();
        });
        $s->create('tbl_nilai_rapor', function (Blueprint $t) {
            $t->integer('id_siswa');
            $t->integer('id_mapel');
            $t->integer('id_tahun');
            foreach (['nilai_ujian', 'nilai_harian', 'nilai_tugas_akhir', 'nilai_praktek', 'nilai_adab', 'nilai_manual', 'nilai_np_manual', 'nilai_nk_manual'] as $k) {
                $t->double($k)->nullable();
            }
            $t->string('catatan_guru')->nullable();
        });
        $s->create('tbl_nilai_us', function (Blueprint $t) {
            $t->integer('id_siswa');
            $t->integer('id_mapel');
            $t->integer('id_tahun');
            $t->double('nilai_us')->nullable();
            $t->double('nilai_cbt')->nullable();
            $t->string('keterangan')->nullable();
        });
        $s->create('tbl_rapor_data', function (Blueprint $t) {
            $t->integer('id_siswa');
            $t->integer('id_tahun');
            $t->integer('sakit')->default(0);
            $t->integer('izin')->default(0);
            $t->integer('alpa')->default(0);
            $t->text('catatan_wali_kelas')->nullable();
            $t->string('keputusan')->nullable();
        });
        $s->create('tbl_nilai', function (Blueprint $t) {
            $t->integer('id_nilai')->primary();
            $t->integer('id_siswa');
            $t->string('mapel');
            $t->integer('id_tahun');
            $t->integer('jumlah_benar_pg')->default(0);
            $t->integer('jumlah_salah_pg')->default(0);
            $t->double('skor_pg')->nullable();
            $t->double('skor_es')->nullable();
            $t->double('skor_akhir_komposit')->nullable();
            $t->string('status_koreksi')->nullable();
            $t->string('waktu_selesai')->nullable();
        });
        $s->create('tbl_soal_pg', function (Blueprint $t) {
            $t->integer('id_soal')->primary();
            $t->string('mapel');
            $t->string('tingkat_kelas');
            $t->text('pertanyaan');
            $t->string('gambar_pg')->nullable();
            foreach (['opsi_a', 'opsi_b', 'opsi_c', 'opsi_d', 'kunci_jawaban'] as $k) {
                $t->string($k)->nullable();
            }
        });
        $s->create('tbl_soal_es', function (Blueprint $t) {
            $t->integer('id_soal_es')->primary();
            $t->string('mapel');
            $t->string('tingkat_kelas');
            $t->text('pertanyaan_es');
            $t->string('gambar_es')->nullable();
        });

        $lama = DB::connection('lama');
        $lama->table('tbl_siswa')->insert([['id_siswa' => 1, 'nisn' => $this->siswa[0]->nisn, 'kelas' => 'X TKJ 1'], ['id_siswa' => 2, 'nisn' => 'TIDAKADA', 'kelas' => 'X TKJ 1']]);
        $lama->table('tbl_mapel')->insert([['id_mapel' => 1, 'nama_mapel' => 'matematika'], ['id_mapel' => 2, 'nama_mapel' => 'Mapel Hilang']]);
        $lama->table('tbl_tahun_akademik')->insert([
            ['id_tahun' => 1, 'nama_tahun' => '2022/2023', 'semester' => 'Ganjil', 'tipe' => 'Semester'],
            ['id_tahun' => 2, 'nama_tahun' => '2025/2026', 'semester' => 'Genap', 'tipe' => 'US'],
        ]);
    }

    /** Sisipkan baris satu per satu (kolom tiap baris boleh berbeda). */
    private function isi(string $tabel, array $baris): void
    {
        foreach ($baris as $b) {
            DB::connection('lama')->table($tabel)->insert($b);
        }
    }

    private function isiData(): void
    {
        $lama = DB::connection('lama');
        $this->isi('tbl_nilai_rapor', [
            ['id_siswa' => 1, 'id_mapel' => 1, 'id_tahun' => 1, 'nilai_ujian' => 80, 'nilai_harian' => 90, 'nilai_tugas_akhir' => 70, 'nilai_praktek' => 80, 'nilai_adab' => 60],
            ['id_siswa' => 2, 'id_mapel' => 1, 'id_tahun' => 1, 'nilai_ujian' => 80, 'nilai_harian' => 80, 'nilai_tugas_akhir' => 80],   // NISN tak dikenal
            ['id_siswa' => 1, 'id_mapel' => 2, 'id_tahun' => 1, 'nilai_ujian' => 80, 'nilai_harian' => 80, 'nilai_tugas_akhir' => 80],   // mapel tak dikenal
            ['id_siswa' => 1, 'id_mapel' => 1, 'id_tahun' => 2, 'nilai_ujian' => 99, 'nilai_harian' => 99, 'nilai_tugas_akhir' => 99],   // semester US: bukan rapor
        ]);
        $lama->table('tbl_nilai_us')->insert([['id_siswa' => 1, 'id_mapel' => 1, 'id_tahun' => 2, 'nilai_us' => 92, 'nilai_cbt' => 88, 'keterangan' => 'Ok']]);
        $this->isi('tbl_rapor_data', [
            ['id_siswa' => 1, 'id_tahun' => 1, 'sakit' => 1, 'izin' => 2, 'alpa' => 3, 'catatan_wali_kelas' => 'Tingkatkan lagi.', 'keputusan' => 'Naik Kelas XII TSM'],
            ['id_siswa' => 2, 'id_tahun' => 1, 'sakit' => 9, 'izin' => 9, 'alpa' => 9],   // NISN tak dikenal
            ['id_siswa' => 1, 'id_tahun' => 2, 'sakit' => 5, 'izin' => 5, 'alpa' => 5],   // semester US: dilewati
        ]);
        $this->isi('tbl_nilai', [
            ['id_nilai' => 10, 'id_siswa' => 1, 'mapel' => 'Matematika', 'id_tahun' => 1, 'jumlah_benar_pg' => 18, 'jumlah_salah_pg' => 2, 'skor_pg' => 90, 'skor_es' => 80, 'skor_akhir_komposit' => 86, 'status_koreksi' => 'Sudah Dikoreksi', 'waktu_selesai' => '2025-12-04 07:45:53'],
            ['id_nilai' => 11, 'id_siswa' => 1, 'mapel' => 'XII_Gladi Bersih', 'id_tahun' => 2, 'jumlah_benar_pg' => 5, 'jumlah_salah_pg' => 5, 'skor_pg' => 50, 'skor_es' => null, 'skor_akhir_komposit' => 0, 'status_koreksi' => 'Belum Dikoreksi', 'waktu_selesai' => '2025-12-05 08:00:00'],
            ['id_nilai' => 12, 'id_siswa' => 2, 'mapel' => 'Matematika', 'id_tahun' => 1, 'skor_pg' => 70],   // NISN tak dikenal
        ]);
        $this->isi('tbl_soal_pg', [
            ['id_soal' => 1, 'mapel' => 'Matematika', 'tingkat_kelas' => 'X', 'pertanyaan' => 'Berapa 2+2?', 'opsi_a' => '3', 'opsi_b' => '4', 'opsi_c' => '5', 'opsi_d' => '6', 'kunci_jawaban' => 'B', 'gambar_pg' => 'x.png'],
            ['id_soal' => 2, 'mapel' => 'Matematika', 'tingkat_kelas' => 'X', 'pertanyaan' => 'Soal tanpa kunci', 'opsi_a' => '1', 'opsi_b' => '2', 'opsi_c' => '3', 'opsi_d' => '4', 'kunci_jawaban' => ''],
            ['id_soal' => 3, 'mapel' => 'Fisika Tak Dikenal', 'tingkat_kelas' => 'X', 'pertanyaan' => 'Q', 'opsi_a' => '1', 'opsi_b' => '2', 'opsi_c' => '3', 'opsi_d' => '4', 'kunci_jawaban' => 'A'],
        ]);
        $lama->table('tbl_soal_es')->insert([['id_soal_es' => 1, 'mapel' => 'Matematika', 'tingkat_kelas' => 'X', 'pertanyaan_es' => 'Jelaskan bilangan prima.']]);
    }

    public function test_hitung_lama_memakai_rumus_np_nk_dan_nilai_manual(): void
    {
        $this->assertSame(['np' => 84, 'nk' => 74], PemindahDataLama::hitungLama((object) ['nilai_ujian' => 80, 'nilai_harian' => 90, 'nilai_tugas_akhir' => 70, 'nilai_praktek' => 80, 'nilai_adab' => 60]));
        $this->assertSame(['np' => 77, 'nk' => 0], PemindahDataLama::hitungLama((object) ['nilai_manual' => 77]));
        $this->assertSame(['np' => 88, 'nk' => 91], PemindahDataLama::hitungLama((object) ['nilai_np_manual' => 88, 'nilai_nk_manual' => 91, 'nilai_ujian' => 10]));
    }

    public function test_uji_coba_tidak_menyimpan_apa_pun(): void
    {
        $this->isiData();

        $this->artisan('migrasi:lama')->assertSuccessful()->expectsOutputToContain('MODE UJI COBA');

        $this->assertSame(0, NilaiRapor::count());
        $this->assertSame(0, NilaiUs::count());
        $this->assertSame(0, BankSoal::count() - 1); // hanya bank bawaan uji
        $this->assertNull(Periode::whereHas('tahunAjaran', fn ($q) => $q->where('nama', '2022/2023'))->first());
    }

    public function test_simpan_memindahkan_nilai_us_dan_soal_dan_aman_diulang(): void
    {
        $this->isiData();

        $this->artisan('migrasi:lama', ['--simpan' => true])->assertSuccessful()->expectsOutputToContain('MODE SIMPAN');

        $n = NilaiRapor::where('siswa_id', $this->siswa[0]->id)->first();
        $this->assertSame([84, 74, 79, 'final'], [$n->np, $n->nk, $n->na, $n->status]);
        $this->assertSame('X TKJ 1', $n->snapshot['kelas_nama']);
        $this->assertSame(1, NilaiRapor::count()); // siswa/mapel tak dikenal dan semester US tidak ikut

        $r = RaporSiswa::where('siswa_id', $this->siswa[0]->id)->sole();
        $this->assertSame([1, 2, 3, 'Tingkatkan lagi.', 'naik', 'final', 'impor'], [$r->sakit, $r->izin, $r->alpa, $r->catatan_wali, $r->keputusan, $r->status, $r->sumber_absensi]);
        $this->assertSame(1, RaporSiswa::count());

        $this->assertSame(2, HasilUjianLama::count()); // siswa tak dikenal tidak ikut
        $a = HasilUjianLama::where('id_lama', 10)->first();
        $this->assertSame(['Matematika', 'X TKJ 1', 18, 2, 86.0, true], [$a->mapel_nama, $a->kelas_nama, $a->benar, $a->salah, (float) $a->skor_akhir, $a->sudah_dikoreksi]);
        $b = HasilUjianLama::where('id_lama', 11)->first();
        $this->assertFalse($b->sudah_dikoreksi);
        $this->assertSame('ujian_sekolah', $b->periode->tipe); // semester US lama menjadi semester Ujian Sekolah
        $this->assertFalse((bool) $n->periode->rapor_di_sini);

        $us = NilaiUs::first();
        $this->assertSame([92.0, 88.0, 'Ok'], [(float) $us->nilai, (float) $us->nilai_cbt, $us->keterangan]);

        $bank = BankSoal::where('nama', 'Migrasi Matematika Kelas X')->first();
        $this->assertSame([1, 1], [$bank->soal()->where('tipe', Soal::PG)->count(), $bank->soal()->where('tipe', Soal::ESAI)->count()]);
        $this->assertSame('B', $bank->soal()->where('tipe', Soal::PG)->first()->opsi()->where('benar', true)->value('label'));

        // Diulang: tidak ada duplikat.
        $this->artisan('migrasi:lama', ['--simpan' => true])->assertSuccessful();
        $this->assertSame([1, 1, 2, 1, 2], [NilaiRapor::count(), NilaiUs::count(), $bank->soal()->count(), RaporSiswa::count(), HasilUjianLama::count()]);
    }

    public function test_kode_keputusan_dari_tulisan_bebas(): void
    {
        $this->assertSame('naik', PemindahDataLama::kodeKeputusan('Naik Kelas XII Teknik Sepeda Motor'));
        $this->assertSame('naik', PemindahDataLama::kodeKeputusan('naik'));
        $this->assertSame('tinggal', PemindahDataLama::kodeKeputusan('Tidak Naik'));
        $this->assertSame('lulus', PemindahDataLama::kodeKeputusan('LULUS'));
        $this->assertNull(PemindahDataLama::kodeKeputusan(''));
        $this->assertNull(PemindahDataLama::kodeKeputusan(null));
    }

    public function test_arsip_hasil_ujian_hanya_admin_dan_menampilkan_nilai(): void
    {
        $this->isiData();
        $this->artisan('migrasi:lama', ['--simpan' => true, '--bagian' => 'cbt'])->assertSuccessful();

        $this->actingAs(User::factory()->create(), 'web')->get('/staf/arsip-hasil-ujian')->assertForbidden();
        $this->actingAs($this->admin, 'web')->get('/staf/arsip-hasil-ujian')->assertOk()
            ->assertSee('Matematika')->assertSee('86')->assertSee('Belum dikoreksi');
        $this->get('/staf/arsip-hasil-ujian?q='.$this->siswa[0]->nisn)->assertOk()->assertSee('Matematika');
        $this->get('/staf/arsip-hasil-ujian?q=zzzz')->assertOk()->assertSee('Belum ada hasil ujian lama');
    }

    public function test_menolak_bila_database_lama_belum_diatur_atau_bagian_salah(): void
    {
        $this->artisan('migrasi:lama', ['--bagian' => 'rahasia'])->assertExitCode(2);
        config(['database.connections.lama.database' => '']);
        $this->artisan('migrasi:lama')->assertFailed();
    }
}
