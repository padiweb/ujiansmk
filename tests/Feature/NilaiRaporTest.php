<?php

namespace Tests\Feature;

use App\Models\KegiatanUjian;
use App\Models\NilaiRapor;
use App\Models\Pengampu;
use App\Models\SkalaPredikat;
use App\Models\User;
use App\Services\LayananRapor;
use App\Services\LayananRekap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class NilaiRaporTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private User $guru;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 3); // hanya PG: skor akhir langsung ada
        foreach ([[90, 'A'], [80, 'B'], [70, 'C'], [0, 'D']] as [$min, $p]) {
            SkalaPredikat::create(['nilai_min' => $min, 'predikat' => $p]);
        }
        $this->guru = User::factory()->create();
        Pengampu::create(['guru_id' => $this->guru->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->kelas->id]);
    }

    private function selesaikan(int $i, bool $semuaBenar): void
    {
        $token = $this->mulaiSesi($this->siswa[$i]);
        $sesi = $this->sesiDari($this->siswa[$i]);
        $jawaban = $semuaBenar ? $this->jawabanBenar() : [$this->soal['s1']->id => ['o' => 'A', 'w' => now()->getTimestampMs()]]; // 1 dari 4 bobot benar = 25
        $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => $jawaban], $this->header($token))->assertOk();
    }

    private function kunci(): void
    {
        app(LayananRekap::class)->kunci($this->jadwal->fresh(), $this->admin, [$this->kelas->id]);
    }

    private function url(string $akhir = ''): string
    {
        return "/staf/nilai/{$this->mtk->id}/{$this->kelas->id}{$akhir}";
    }

    private function nilai(int $i): ?NilaiRapor
    {
        return NilaiRapor::where('siswa_id', $this->siswa[$i]->id)->where('mapel_id', $this->mtk->id)->first();
    }

    // ── Akses ───────────────────────────────────────────────────────────────

    public function test_akses_guru_pengampu_admin_dan_larangan_lainnya(): void
    {
        $lain = User::factory()->create();
        $pengawas = User::factory()->pengawas()->create();

        $this->actingAs($this->guru, 'web')->get('/staf/nilai')->assertOk()->assertSee('Matematika')->assertSee('X A');
        $this->get($this->url())->assertOk();
        $this->actingAs($this->admin, 'web')->get($this->url())->assertOk();
        $this->actingAs($lain, 'web')->get('/staf/nilai')->assertOk()->assertDontSee('Matematika');
        $this->get($this->url())->assertNotFound();
        $this->post($this->url(), ['nilai' => []])->assertNotFound();
        $this->actingAs($pengawas, 'web')->get('/staf/nilai')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->siswa[0], 'siswa')->get('/staf/nilai')->assertRedirect();
    }

    // ── Nilai ujian otomatis ────────────────────────────────────────────────

    public function test_ujian_terisi_otomatis_hanya_setelah_kelas_dikunci(): void
    {
        $this->selesaikan(0, true);
        $this->selesaikan(1, false);

        $this->actingAs($this->guru, 'web')->get($this->url())->assertOk()->assertDontSee('dari ujian');
        $this->assertSame([], app(\App\Services\LayananNilaiRapor::class)->ujianOtomatis($this->mtk, $this->kelas, $this->jadwal->periode));

        $this->kunci();

        $auto = app(\App\Services\LayananNilaiRapor::class)->ujianOtomatis($this->mtk, $this->kelas, $this->jadwal->periode);
        $this->assertSame(100.0, $auto[$this->siswa[0]->id]);
        $this->assertSame(25.0, $auto[$this->siswa[1]->id]);
        $this->assertArrayNotHasKey($this->siswa[2]->id, $auto); // tidak mengikuti ujian

        $this->get($this->url())->assertOk()->assertSee('dari ujian');
    }

    public function test_simpan_menghitung_di_server_dan_mengabaikan_hasil_kiriman_peramban(): void
    {
        $this->selesaikan(0, true);
        $this->kunci();

        $this->actingAs($this->guru, 'web')->post($this->url(), ['nilai' => [
            $this->siswa[0]->id => ['ujian' => '', 'harian' => '80', 'tugas_akhir' => '70', 'praktik' => '90', 'adab' => '100', 'np' => 1, 'na' => 1, 'predikat' => 'Z', 'catatan' => 'Baik'],
            $this->siswa[1]->id => ['harian' => '250', 'tugas_akhir' => 'abc'],
            99999 => ['harian' => '90'], // bukan siswa kelas ini
        ]])->assertSessionHas('berhasil');

        $n = $this->nilai(0);
        $this->assertEquals(100, $n->ujian); // kosong = ikut hasil ujian
        $this->assertSame('otomatis', $n->ujian_sumber);
        $this->assertSame(82, $n->np); // 20 + 48 + 14
        $this->assertSame($this->hitungNp(100, 80, 70), $n->np);
        $this->assertSame(93, $n->nk); // 90x70% + 100x30% = 93
        $this->assertSame('Baik', $n->catatan_guru);
        $this->assertSame($this->guru->id, $n->guru_id);
        $this->assertSame('draf', $n->status);

        $b = $this->nilai(1);
        $this->assertEquals(100, $b->harian); // dibatasi 0-100
        $this->assertNull($b->tugas_akhir);
        $this->assertNull(NilaiRapor::where('siswa_id', 99999)->first());
    }

    private function hitungNp(float $ujian, float $harian, float $ta): int
    {
        return (int) round($ujian * .2 + $harian * .6 + $ta * .2);
    }

    public function test_nilai_ujian_yang_diubah_guru_bertahan_dan_ambil_ulang_mengembalikannya(): void
    {
        $this->selesaikan(0, true);
        $this->kunci();
        $this->actingAs($this->guru, 'web')->post($this->url(), ['nilai' => [$this->siswa[0]->id => ['ujian' => '85', 'harian' => '70']]]);

        $this->assertEquals(85, $this->nilai(0)->ujian);
        $this->assertSame('manual', $this->nilai(0)->ujian_sumber);
        $this->get($this->url())->assertOk()->assertSee('diubah (ujian: 100)');

        $this->post($this->url('/ambil-ulang'))->assertSessionHas('berhasil');
        $this->assertEquals(100, $this->nilai(0)->ujian);
        $this->assertSame('otomatis', $this->nilai(0)->ujian_sumber);
        $this->assertEquals(70, $this->nilai(0)->harian); // komponen lain tidak tersentuh
    }

    public function test_kegiatan_bukan_untuk_rapor_tidak_menjadi_sumber_nilai_ujian(): void
    {
        $this->selesaikan(0, true);
        $kegiatan = KegiatanUjian::create(['periode_id' => $this->jadwal->periode_id, 'nama' => 'Try Out', 'aktif' => true, 'untuk_rapor' => false]);
        $this->jadwal->update(['kegiatan_id' => $kegiatan->id]);
        $this->kunci();

        $layanan = app(\App\Services\LayananNilaiRapor::class);
        $this->assertSame([], $layanan->ujianOtomatis($this->mtk, $this->kelas, $this->jadwal->periode));

        $this->actingAs($this->admin, 'web')->put("/staf/kegiatan/{$kegiatan->id}", ['untuk_rapor' => 1])->assertSessionHas('berhasil');
        $this->assertCount(1, $layanan->ujianOtomatis($this->mtk, $this->kelas, $this->jadwal->periode));
    }

    // ── Komponen dan bobot ──────────────────────────────────────────────────

    public function test_kolom_tampil_menurut_bobot_yang_diatur_admin(): void
    {
        $html = $this->actingAs($this->guru, 'web')->get($this->url())->assertOk()->getContent();
        $this->assertStringContainsString('data-k="harian"', $html);
        $this->assertStringNotContainsString('data-k="tugas"', $html); // nilai tugas EduWeb = Harian

        \App\Models\PengaturanSekolah::ambil()->update(['bobot_harian' => 80, 'bobot_tugas_akhir' => 0]);
        $this->get($this->url())->assertOk()->assertDontSee('data-k="tugas_akhir"', false);

        $this->post($this->url(), ['nilai' => [$this->siswa[0]->id => ['ujian' => '80', 'harian' => '70', 'tugas_akhir' => '60']]]);
        $this->assertSame(72, $this->nilai(0)->np); // 80x20% + 70x80% = 16 + 56; tugas akhir tidak dipakai
        $this->assertSame(72, $this->nilai(0)->na);
        $this->assertSame('C', $this->nilai(0)->predikat);
    }

    public function test_kolom_pintas_np_nk_tampil_dan_tidak_ikut_dikirim(): void
    {
        $html = $this->actingAs($this->guru, 'web')->get($this->url())->assertOk()->getContent();
        $this->assertStringContainsString('data-pintas="np"', $html);
        $this->assertStringContainsString('data-pintas="nk"', $html);
        $this->assertStringNotContainsString('name="nilai['.$this->siswa[0]->id.'][np]"', $html); // pintas hanya di layar; server tetap menghitung

        \App\Models\PengaturanSekolah::ambil()->update(['bobot_praktik' => 0, 'bobot_adab' => 0, 'bobot_np' => 100, 'bobot_nk' => 0]);
        $this->get($this->url())->assertOk()->assertSee('data-pintas="nk"', false)->assertSee('disabled', false); // NK tanpa komponen: pintas nonaktif
    }

    public function test_komponen_nonaktif_tidak_bisa_diisi_lewat_permintaan_langsung(): void
    {
        \App\Models\PengaturanSekolah::ambil()->update(['bobot_harian' => 80, 'bobot_tugas_akhir' => 0]);
        $this->actingAs($this->guru, 'web')->post($this->url(), ['nilai' => [$this->siswa[0]->id => ['tugas_akhir' => '99', 'harian' => '80']]]);

        $this->assertNull($this->nilai(0)->tugas_akhir); // bobot 0
        $this->assertEquals(80, $this->nilai(0)->harian);
    }

    public function test_nilai_final_tidak_diubah(): void
    {
        $this->actingAs($this->guru, 'web')->post($this->url(), ['nilai' => [$this->siswa[0]->id => ['harian' => '80']]]);
        $this->nilai(0)->update(['status' => 'final']);

        $this->post($this->url(), ['nilai' => [$this->siswa[0]->id => ['harian' => '10'], $this->siswa[1]->id => ['harian' => '60']]])->assertSessionHas('catatan');

        $this->assertEquals(80, $this->nilai(0)->harian);
        $this->assertEquals(60, $this->nilai(1)->harian);
        $this->get($this->url())->assertOk()->assertSee('disabled', false);
    }

    // ── Harian dari tugas EduWeb ────────────────────────────────────────────

    private function hubungkanEduweb(): void
    {
        config(['eduweb.url' => 'http://127.0.0.1:8000/api/exam/v1', 'eduweb.kunci' => 'exu_'.str_repeat('a', 40)]);
        $this->mtk->update(['eduweb_id' => 501]);
        $this->kelas->update(['eduweb_id' => 77]);
        foreach ($this->siswa as $i => $s) {
            $s->update(['eduweb_id' => 9000 + $i]);
        }
    }

    public function test_harian_diisi_dari_rata_rata_tugas_eduweb_dan_bisa_diubah_guru(): void
    {
        $this->hubungkanEduweb();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['data' => [
            ['student_id' => 9000, 'average' => 90.5, 'graded' => 3],
            ['student_id' => 9001, 'average' => 72, 'graded' => 2],
            ['student_id' => 5555, 'average' => 99, 'graded' => 1], // bukan siswa kelas ini
        ]])]);

        $this->actingAs($this->guru, 'web')->post($this->url('/ambil-ulang'))->assertSessionHas('berhasil');

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'assignment-scores') && $r['classroom_id'] == 77 && $r['subject_id'] == 501);
        $this->assertEquals(90.5, $this->nilai(0)->harian);
        $this->assertSame('otomatis', $this->nilai(0)->harian_sumber);
        $this->assertEquals(72, $this->nilai(1)->harian);
        $this->assertNull($this->nilai(2)); // tidak ada nilai tugas: tidak dibuatkan baris
        $this->get($this->url())->assertOk()->assertSee('dari tugas EduWeb');

        // Menyimpan tanpa mengubah Harian mempertahankan sumbernya; mengubahnya menandai diubah guru.
        $this->post($this->url(), ['nilai' => [$this->siswa[0]->id => ['harian' => '90.5', 'tugas_akhir' => '80'], $this->siswa[1]->id => ['harian' => '85']]]);
        $this->assertSame('otomatis', $this->nilai(0)->harian_sumber);
        $this->assertSame('manual', $this->nilai(1)->harian_sumber);
        $this->get($this->url())->assertSee('diubah guru');
    }

    public function test_galat_eduweb_tidak_menghalangi_dan_tidak_menghapus_isian(): void
    {
        $this->hubungkanEduweb();
        $this->actingAs($this->guru, 'web')->post($this->url(), ['nilai' => [$this->siswa[0]->id => ['harian' => '70']]]);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['message' => 'x'], 403)]);
        $this->post($this->url('/ambil-ulang'))->assertSessionHas('catatan', fn ($m) => str_contains($m, 'Akses nilai tugas'));
        $this->assertEquals(70, $this->nilai(0)->harian);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 500)]);
        $this->post($this->url('/ambil-ulang'))->assertSessionHas('catatan');
        $this->assertEquals(70, $this->nilai(0)->harian);
        $this->get($this->url())->assertOk();
    }

    public function test_harian_final_tidak_ditimpa_dari_eduweb(): void
    {
        $this->hubungkanEduweb();
        $this->actingAs($this->guru, 'web')->post($this->url(), ['nilai' => [$this->siswa[0]->id => ['harian' => '70']]]);
        $this->nilai(0)->update(['status' => 'final']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['data' => [['student_id' => 9000, 'average' => 95, 'graded' => 1]]])]);

        $this->post($this->url('/ambil-ulang'));

        $this->assertEquals(70, $this->nilai(0)->harian);
    }

    public function test_status_input_di_daftar(): void
    {
        $this->actingAs($this->guru, 'web')->get('/staf/nilai')->assertSee('Belum diisi');
        $this->post($this->url(), ['nilai' => [$this->siswa[0]->id => ['harian' => '80']]]);
        $this->get('/staf/nilai')->assertSee('1 dari 3');
        $this->post($this->url(), ['nilai' => collect($this->siswa)->mapWithKeys(fn ($s) => [$s->id => ['harian' => '80']])->all()]);
        $this->get('/staf/nilai')->assertSee('Lengkap');
    }

    public function test_pengaturan_bobot_default_sesuai_aplikasi_lama(): void
    {
        $this->assertSame(LayananRapor::BAWAAN, app(LayananRapor::class)->bobot());
    }
}
