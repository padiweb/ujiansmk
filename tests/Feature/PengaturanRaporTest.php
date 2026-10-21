<?php

namespace Tests\Feature;

use App\Models\PengaturanSekolah;
use App\Models\Periode;
use App\Models\SkalaPredikat;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\LayananRapor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PengaturanRaporTest extends TestCase
{
    use RefreshDatabase;

    private LayananRapor $rapor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rapor = app(LayananRapor::class);
        foreach ([[95, 'A+'], [90, 'A'], [85, 'A-'], [80, 'B+'], [75, 'B-'], [70, 'C'], [0, 'D']] as [$min, $p]) {
            SkalaPredikat::create(['nilai_min' => $min, 'predikat' => $p]);
        }
    }

    private function bobot(array $timpa = []): array
    {
        return array_replace(LayananRapor::BAWAAN, $timpa);
    }

    // ── Rumus ───────────────────────────────────────────────────────────────

    public function test_rumus_bawaan_sama_dengan_aplikasi_lama(): void
    {
        $h = $this->rapor->hitung(['ujian' => 80, 'harian' => 90, 'tugas_akhir' => 70, 'praktik' => 80, 'adab' => 90]);

        $this->assertSame(84, $h['np']); // 16 + 54 + 14
        $this->assertSame(83, $h['nk']); // 56 + 27
        $this->assertSame(84, $h['na']); // 83,5 dibulatkan
        $this->assertSame('B+', $h['predikat']);
    }

    public function test_nk_kosong_memakai_np_dan_np_kosong_memakai_nk(): void
    {
        $this->assertSame(88, $this->rapor->hitung(['ujian' => 88, 'harian' => 88, 'tugas_akhir' => 88])['na']);
        $this->assertSame(76, $this->rapor->hitung(['praktik' => 76, 'adab' => 76])['na']);
        $kosong = $this->rapor->hitung([]);
        $this->assertSame(0, $kosong['na']);
        $this->assertSame('-', $kosong['predikat']);
    }

    public function test_tugas_eduweb_ikut_dihitung_menurut_bobot_yang_diatur_admin(): void
    {
        // Tugas rata-rata 90 dihitung ulang dengan bobot 25%: ujian 20, tugas 25, harian 35, tugas akhir 20.
        $bobot = $this->bobot(['ujian' => 20, 'tugas' => 25, 'harian' => 35, 'tugas_akhir' => 20]);
        $h = $this->rapor->hitung(['ujian' => 80, 'tugas' => 90, 'harian' => 70, 'tugas_akhir' => 60], $bobot);

        $this->assertSame(75, $h['np']); // 16 + 22,5 + 24,5 + 12 = 75
        $this->assertSame(75, $h['na']);
        $this->assertSame('B-', $h['predikat']);

        // Dengan bobot tugas 0 (mati) nilai tugas tidak berpengaruh.
        $h2 = $this->rapor->hitung(['ujian' => 80, 'tugas' => 0, 'harian' => 90, 'tugas_akhir' => 70]);
        $this->assertSame(84, $h2['np']);
    }

    public function test_bobot_nilai_akhir_np_dan_nk_dapat_diatur(): void
    {
        $bobot = $this->bobot(['np' => 70, 'nk' => 30]);
        $h = $this->rapor->hitung(['ujian' => 100, 'harian' => 100, 'tugas_akhir' => 100, 'praktik' => 50, 'adab' => 50], $bobot);

        $this->assertSame(100, $h['np']);
        $this->assertSame(50, $h['nk']);
        $this->assertSame(85, $h['na']); // 70 + 15
    }

    public function test_nilai_di_luar_rentang_dibatasi_0_sampai_100(): void
    {
        $h = $this->rapor->hitung(['ujian' => 500, 'harian' => 500, 'tugas_akhir' => 500]);
        $this->assertSame(100, $h['np']);
    }

    public function test_pemeriksaan_bobot(): void
    {
        $this->assertSame([], $this->rapor->periksaBobot($this->bobot()));
        $this->assertSame([], $this->rapor->periksaBobot($this->bobot(['praktik' => 0, 'adab' => 0]))); // keterampilan tidak dipakai

        $this->assertNotEmpty($this->rapor->periksaBobot($this->bobot(['ujian' => 30]))); // NP 110
        $this->assertNotEmpty($this->rapor->periksaBobot($this->bobot(['praktik' => 50]))); // NK 80
        $this->assertNotEmpty($this->rapor->periksaBobot($this->bobot(['np' => 60]))); // 60 + 50
        $this->assertNotEmpty($this->rapor->periksaBobot($this->bobot(['adab' => 'abc'])));
        $this->assertNotEmpty($this->rapor->periksaBobot($this->bobot(['ujian' => -5, 'harian' => 85])));
    }

    public function test_komponen_aktif_hanya_yang_berbobot(): void
    {
        $this->assertSame(['ujian', 'harian', 'tugas_akhir', 'praktik', 'adab'], $this->rapor->komponenAktif());
        $this->assertSame(['ujian', 'tugas', 'harian', 'praktik', 'adab'], $this->rapor->komponenAktif($this->bobot(['tugas' => 10, 'tugas_akhir' => 0, 'harian' => 70])));
    }

    // ── Layar pengaturan ────────────────────────────────────────────────────

    public function test_hanya_admin_yang_membuka_pengaturan_rapor(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->get('/staf/pengaturan-rapor')->assertForbidden();
        $this->actingAs(User::factory()->pengawas()->create(), 'web')->put('/staf/pengaturan-rapor/bobot', $this->bobot())->assertForbidden();
        $this->actingAs(User::factory()->admin()->create(), 'web')->get('/staf/pengaturan-rapor')->assertOk()->assertSee('Bobot nilai')->assertSee('Skala predikat');
        $this->get('/staf')->assertSee('Pengaturan Rapor');
    }

    public function test_bobot_disimpan_dan_dipakai_perhitungan(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $baru = $this->bobot(['ujian' => 30, 'tugas' => 20, 'harian' => 30, 'tugas_akhir' => 20, 'np' => 60, 'nk' => 40]);

        $this->put('/staf/pengaturan-rapor/bobot', $baru)->assertSessionHas('berhasil');

        $this->assertSame($baru, $this->rapor->bobot());
        $this->assertDatabaseHas('audit_log', ['aksi' => 'rapor.bobot']);
    }

    public function test_bobot_tidak_sah_ditolak_dan_tidak_mengubah_apa_pun(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->put('/staf/pengaturan-rapor/bobot', $this->bobot(['ujian' => 40]))->assertSessionHasErrors('bobot');
        $this->put('/staf/pengaturan-rapor/bobot', $this->bobot(['np' => 80]))->assertSessionHasErrors('bobot');
        $this->put('/staf/pengaturan-rapor/bobot', ['ujian' => 'x'] + $this->bobot())->assertSessionHasErrors('ujian');
        $this->put('/staf/pengaturan-rapor/bobot', [])->assertSessionHasErrors(['ujian', 'harian']);

        $this->assertSame(LayananRapor::BAWAAN, $this->rapor->bobot());
    }

    public function test_skala_predikat_diganti_dan_divalidasi(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->put('/staf/pengaturan-rapor/skala', ['skala' => [
            ['nilai_min' => 90, 'predikat' => 'Amat Baik'], ['nilai_min' => 75, 'predikat' => 'Baik'], ['nilai_min' => 0, 'predikat' => 'Cukup'],
            ['nilai_min' => '', 'predikat' => ''],
        ]])->assertSessionHas('berhasil');

        $this->assertSame(3, SkalaPredikat::count());
        $this->assertSame('Baik', $this->rapor->predikat(80));
        $this->assertSame('Amat Baik', $this->rapor->predikat(95));

        $this->put('/staf/pengaturan-rapor/skala', ['skala' => [['nilai_min' => 50, 'predikat' => 'X']]])->assertSessionHasErrors('skala'); // tanpa batas 0
        $this->put('/staf/pengaturan-rapor/skala', ['skala' => [['nilai_min' => 0, 'predikat' => 'A'], ['nilai_min' => 0, 'predikat' => 'B']]])->assertSessionHasErrors('skala'); // ganda
        $this->put('/staf/pengaturan-rapor/skala', ['skala' => [['nilai_min' => 0, 'predikat' => '']]])->assertSessionHasErrors('skala');
        $this->put('/staf/pengaturan-rapor/skala', ['skala' => [['nilai_min' => 150, 'predikat' => 'A'], ['nilai_min' => 0, 'predikat' => 'B']]])->assertSessionHasErrors('skala');
        $this->assertSame(3, SkalaPredikat::count()); // tidak berubah oleh yang ditolak
    }

    public function test_rentang_semester_disimpan(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $tahun = TahunAjaran::create(['nama' => '2025/2026', 'aktif' => true]);
        $p = Periode::create(['tahun_ajaran_id' => $tahun->id, 'semester' => 'ganjil', 'tipe' => 'reguler', 'aktif' => true]);

        $this->put('/staf/pengaturan-rapor/periode', ['periode' => [$p->id => ['mulai' => '2025-07-14', 'selesai' => '2025-12-20']]])->assertSessionHas('berhasil');
        $this->assertSame('2025-07-14', $p->fresh()->mulai->format('Y-m-d'));
        $this->assertSame('2025-12-20', $p->fresh()->selesai->format('Y-m-d'));

        $this->put('/staf/pengaturan-rapor/periode', ['periode' => [$p->id => ['mulai' => '2025-12-20', 'selesai' => '2025-07-14']]])->assertSessionHasErrors('periode');
        $this->assertSame('2025-07-14', $p->fresh()->mulai->format('Y-m-d'));
    }

    public function test_pengaturan_baru_bernilai_bawaan(): void
    {
        $p = PengaturanSekolah::ambil()->fresh();
        $this->assertSame(0, (int) $p->bobot_tugas);
        $this->assertSame(50, (int) $p->bobot_np);
    }
}
