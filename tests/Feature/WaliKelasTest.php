<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NilaiRapor;
use App\Models\Pengampu;
use App\Models\Periode;
use App\Models\RaporSiswa;
use App\Models\SkalaPredikat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class WaliKelasTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private User $wali;

    private User $guruMapel;

    private Periode $periode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 3);
        foreach ([[90, 'A'], [80, 'B'], [70, 'C'], [0, 'D']] as [$min, $p]) {
            SkalaPredikat::create(['nilai_min' => $min, 'predikat' => $p]);
        }
        $this->wali = User::factory()->create();
        $this->guruMapel = User::factory()->create();
        $this->kelas->update(['wali_kelas_id' => $this->wali->id]);
        Pengampu::create(['guru_id' => $this->guruMapel->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->kelas->id]);
        $this->periode = $this->jadwal->periode;
    }

    private function url(string $akhir = ''): string
    {
        return "/staf/wali-kelas/{$this->kelas->id}{$akhir}";
    }

    private function isiNilaiLengkap(int $jumlah = 3): void
    {
        foreach (array_slice($this->siswa, 0, $jumlah) as $s) {
            NilaiRapor::create([
                'siswa_id' => $s->id, 'mapel_id' => $this->mtk->id, 'periode_id' => $this->periode->id, 'kelas_id' => $this->kelas->id,
                'ujian' => 80, 'harian' => 80, 'tugas_akhir' => 80, 'praktik' => 80, 'adab' => 80, 'np' => 80, 'nk' => 80, 'na' => 80, 'predikat' => 'B', 'status' => 'draf',
            ]);
        }
    }

    private function rapor(int $i): ?RaporSiswa
    {
        return RaporSiswa::where('siswa_id', $this->siswa[$i]->id)->where('periode_id', $this->periode->id)->first();
    }

    // ── Akses ───────────────────────────────────────────────────────────────

    public function test_wali_hanya_melihat_kelasnya_dan_admin_semua(): void
    {
        $lain = User::factory()->create();
        $pengawas = User::factory()->pengawas()->create();

        $this->actingAs($this->wali, 'web')->get('/staf/wali-kelas')->assertOk()->assertSee('X A');
        $this->get($this->url())->assertOk()->assertSee('Matematika');
        $this->actingAs($this->admin, 'web')->get('/staf/wali-kelas')->assertOk()->assertSee('X A');
        $this->get($this->url())->assertOk();
        $this->actingAs($lain, 'web')->get('/staf/wali-kelas')->assertOk()->assertDontSee('X A')->assertSee('belum ditetapkan');
        $this->get($this->url())->assertNotFound();
        $this->post($this->url(), ['rapor' => []])->assertNotFound();
        $this->post($this->url('/finalkan'))->assertNotFound();
        $this->actingAs($this->guruMapel, 'web')->get($this->url())->assertNotFound(); // guru mapel bukan wali
        $this->actingAs($pengawas, 'web')->get('/staf/wali-kelas')->assertForbidden();
    }

    public function test_siswa_tidak_bisa_membuka_menu_wali_kelas(): void
    {
        $this->actingAs($this->siswa[0], 'siswa')->get('/staf/wali-kelas')->assertRedirect();
    }

    // ── Simpan ──────────────────────────────────────────────────────────────

    public function test_simpan_absensi_catatan_dan_keputusan_dengan_penanda_sumber(): void
    {
        $id = $this->siswa[0]->id;
        $this->actingAs($this->wali, 'web')->post($this->url(), ['rapor' => [
            $id => ['sakit' => '2', 'izin' => '-5', 'alpa' => 'abc', 'catatan' => 'Rajin', 'keputusan' => 'naik'],
            $this->siswa[1]->id => ['sakit' => '0', 'keputusan' => 'terbang'], // keputusan tidak dikenal
            99999 => ['sakit' => '9'], // bukan siswa kelas ini
        ]])->assertSessionHas('berhasil');

        $r = $this->rapor(0);
        $this->assertSame(2, $r->sakit);
        $this->assertSame(0, $r->izin);
        $this->assertSame(0, $r->alpa);
        $this->assertSame('manual', $r->sumber_absensi);
        $this->assertSame('Rajin', $r->catatan_wali);
        $this->assertSame('naik', $r->keputusan);
        $this->assertSame('X A', $r->kelas_nama);
        $this->assertNull($this->rapor(1)->keputusan);
        $this->assertNull($this->rapor(1)->sumber_absensi); // semua nol: bukan isian manual
        $this->assertNull(RaporSiswa::where('siswa_id', 99999)->first());
    }

    // ── Absensi dari EduWeb ─────────────────────────────────────────────────

    private function hubungkanEduweb(): void
    {
        config(['eduweb.url' => 'http://127.0.0.1:8000/api/exam/v1', 'eduweb.kunci' => 'exu_'.str_repeat('a', 40)]);
        $this->periode->update(['mulai' => '2025-07-14', 'selesai' => '2025-12-20']);
        $this->kelas->update(['eduweb_id' => 77]);
        foreach ($this->siswa as $i => $s) {
            $s->update(['eduweb_id' => 9000 + $i]);
        }
    }

    public function test_absensi_diambil_dari_eduweb_dan_bisa_dikoreksi_wali(): void
    {
        $this->hubungkanEduweb();
        Http::fake(['*' => Http::response(['data' => [
            ['student_id' => 9000, 'hadir' => 90, 'sakit' => 3, 'izin' => 1, 'alpa' => 2],
            ['student_id' => 5555, 'hadir' => 1, 'sakit' => 9, 'izin' => 9, 'alpa' => 9], // bukan siswa kelas ini
        ]])]);

        $this->actingAs($this->wali, 'web')->post($this->url('/absensi'))->assertSessionHas('berhasil');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'attendance-summary') && $r['classroom_id'] == 77 && $r['from'] === '2025-07-14' && $r['to'] === '2025-12-20');
        $this->assertSame([3, 1, 2, 'eduweb'], [$this->rapor(0)->sakit, $this->rapor(0)->izin, $this->rapor(0)->alpa, $this->rapor(0)->sumber_absensi]);
        $this->assertNull($this->rapor(1)?->sumber_absensi); // siswa lain tidak ikut terisi absensinya
        $this->get($this->url())->assertSee('dari EduWeb');

        // Kirim ulang tanpa mengubah angka mempertahankan sumber; mengubahnya menjadi manual.
        $id = $this->siswa[0]->id;
        $this->post($this->url(), ['rapor' => [$id => ['sakit' => '3', 'izin' => '1', 'alpa' => '2']]]);
        $this->assertSame('eduweb', $this->rapor(0)->sumber_absensi);
        $this->post($this->url(), ['rapor' => [$id => ['sakit' => '4', 'izin' => '1', 'alpa' => '2']]]);
        $this->assertSame('manual', $this->rapor(0)->sumber_absensi);
    }

    public function test_absensi_tanpa_rentang_semester_atau_saat_eduweb_mati_memberi_pesan_dan_tidak_mengubah_data(): void
    {
        $this->actingAs($this->wali, 'web')->post($this->url('/absensi'))->assertSessionHas('gagal', fn ($m) => str_contains($m, 'belum terhubung'));

        $this->hubungkanEduweb();
        $this->periode->update(['mulai' => null, 'selesai' => null]);
        $this->post($this->url('/absensi'))->assertSessionHas('gagal', fn ($m) => str_contains($m, 'Rentang tanggal'));

        $this->periode->update(['mulai' => '2025-07-14', 'selesai' => '2025-12-20']);
        $this->post($this->url(), ['rapor' => [$this->siswa[0]->id => ['sakit' => '5']]]);
        Http::fake(['*' => Http::response('', 500)]);
        $this->post($this->url('/absensi'))->assertSessionHas('gagal');
        $this->assertSame(5, $this->rapor(0)->sakit);
    }

    // ── Finalisasi ──────────────────────────────────────────────────────────

    public function test_tidak_bisa_difinalkan_selama_nilai_belum_lengkap(): void
    {
        $this->isiNilaiLengkap(2); // satu siswa belum punya nilai Matematika

        $this->actingAs($this->wali, 'web')->get($this->url())->assertOk()->assertSee('Belum bisa difinalkan')->assertSee('2 dari 3');
        $this->post($this->url('/finalkan'))->assertSessionHas('gagal', fn ($m) => str_contains($m, 'Matematika'));

        $this->assertSame(0, NilaiRapor::where('status', 'final')->count());
        $this->assertNull($this->rapor(0));
    }

    public function test_finalisasi_membekukan_nilai_dengan_snapshot_dan_menolak_perubahan(): void
    {
        $this->isiNilaiLengkap();
        $this->actingAs($this->wali, 'web')->post($this->url(), ['rapor' => [$this->siswa[0]->id => ['sakit' => '1', 'catatan' => 'Baik']]]);

        $this->post($this->url('/finalkan'))->assertSessionHas('berhasil');

        $this->assertSame(3, NilaiRapor::where('status', 'final')->count());
        $n = NilaiRapor::where('siswa_id', $this->siswa[0]->id)->first();
        $this->assertSame('Matematika', $n->snapshot['mapel_nama']);
        $this->assertSame('X A', $n->snapshot['kelas_nama']);
        $this->assertSame(75, (int) $n->snapshot['kkm']);
        $this->assertSame(20, $n->snapshot['bobot']['ujian']);
        $this->assertNotEmpty($n->snapshot['skala']);
        foreach ([0, 1, 2] as $i) {
            $this->assertSame('final', $this->rapor($i)->status);
            $this->assertSame($this->wali->id, $this->rapor($i)->difinalkan_oleh);
        }
        $this->assertDatabaseHas('audit_log', ['aksi' => 'rapor.final']);

        // Tidak bisa diubah lagi: wali maupun guru mapel
        $this->post($this->url(), ['rapor' => [$this->siswa[0]->id => ['sakit' => '9']]])->assertSessionHas('catatan');
        $this->assertSame(1, $this->rapor(0)->sakit);
        $this->actingAs($this->guruMapel, 'web')->post("/staf/nilai/{$this->mtk->id}/{$this->kelas->id}", ['nilai' => [$this->siswa[0]->id => ['harian' => '10']]])->assertSessionHas('catatan');
        $this->assertEquals(80, $n->fresh()->harian);
    }

    public function test_semester_genap_mewajibkan_keputusan_sebelum_final(): void
    {
        $this->periode->update(['semester' => 'genap']);
        $this->isiNilaiLengkap();

        $this->actingAs($this->wali, 'web')->post($this->url('/finalkan'))->assertSessionHas('gagal', fn ($m) => str_contains($m, 'Keputusan'));

        $this->post($this->url(), ['rapor' => collect($this->siswa)->mapWithKeys(fn ($s) => [$s->id => ['keputusan' => 'naik']])->all()]);
        $this->post($this->url('/finalkan'))->assertSessionHas('berhasil');
    }

    public function test_hanya_admin_membuka_final_dengan_alasan_dan_tercatat(): void
    {
        $this->isiNilaiLengkap();
        $this->actingAs($this->wali, 'web')->post($this->url('/finalkan'));

        $this->post($this->url('/buka-final'), ['alasan' => 'Salah input nilai'])->assertForbidden();
        $this->assertSame(3, NilaiRapor::where('status', 'final')->count());

        $this->actingAs($this->admin, 'web')->post($this->url('/buka-final'), ['alasan' => ''])->assertSessionHasErrors('alasan');
        $this->post($this->url('/buka-final'), ['alasan' => 'abc'])->assertSessionHasErrors('alasan');
        $this->assertSame(3, NilaiRapor::where('status', 'final')->count());

        $this->post($this->url('/buka-final'), ['alasan' => 'Salah input nilai Matematika'])->assertSessionHas('berhasil');

        $this->assertSame(0, NilaiRapor::where('status', 'final')->count());
        $this->assertNull(NilaiRapor::first()->snapshot);
        $this->assertSame('draf', $this->rapor(0)->status);
        $this->assertTrue(AuditLog::where('aksi', 'rapor.buka_final')->where('detail', 'like', '%Salah input nilai Matematika%')->exists());

        // Setelah dibuka, guru dapat mengubah lagi
        $this->actingAs($this->guruMapel, 'web')->post("/staf/nilai/{$this->mtk->id}/{$this->kelas->id}", ['nilai' => [$this->siswa[0]->id => ['harian' => '90']]])->assertSessionHas('berhasil');
        $this->assertEquals(90, NilaiRapor::where('siswa_id', $this->siswa[0]->id)->first()->harian);
    }

    // ── Penanda semester dan siswa yang sudah dinaikkan ─────────────────────

    public function test_semester_yang_rapornya_tidak_diisi_di_sini_ditutup(): void
    {
        $this->periode->update(['rapor_di_sini' => false]);

        $this->actingAs($this->wali, 'web')->get('/staf/wali-kelas')->assertOk()->assertSee('tidak diisi di aplikasi ini')->assertDontSee('Siap difinalkan');
        $this->get($this->url())->assertNotFound();
        $this->post($this->url('/finalkan'))->assertNotFound();
        $this->actingAs($this->guruMapel, 'web')->get('/staf/nilai')->assertOk()->assertSee('tidak diisi di aplikasi ini')->assertDontSee('Matematika');
        $this->get("/staf/nilai/{$this->mtk->id}/{$this->kelas->id}")->assertNotFound();
    }

    public function test_admin_mengatur_penanda_rapor_di_sini_per_semester(): void
    {
        $this->actingAs($this->admin, 'web')->put('/staf/pengaturan-rapor/periode', ['periode' => [$this->periode->id => ['mulai' => '2025-07-14', 'selesai' => '2025-12-20', 'rapor_di_sini' => '0']]])->assertSessionHas('berhasil');
        $this->assertFalse($this->periode->fresh()->rapor_di_sini);

        // Tanpa isian penanda, nilai penanda tidak berubah
        $this->put('/staf/pengaturan-rapor/periode', ['periode' => [$this->periode->id => ['mulai' => '2025-07-15', 'selesai' => '2025-12-20']]]);
        $this->assertFalse($this->periode->fresh()->rapor_di_sini);

        $this->put('/staf/pengaturan-rapor/periode', ['periode' => [$this->periode->id => ['mulai' => '2025-07-15', 'selesai' => '2025-12-20', 'rapor_di_sini' => '1']]]);
        $this->assertTrue($this->periode->fresh()->rapor_di_sini);
    }

    public function test_siswa_yang_sudah_naik_tetap_ikut_rapor_kelas_lama_sampai_final(): void
    {
        $this->isiNilaiLengkap();
        $this->siswa[0]->kelas()->updateExistingPivot($this->kelas->id, ['status' => 'naik']);
        $this->siswa[1]->kelas()->updateExistingPivot($this->kelas->id, ['status' => 'lulus']);
        $this->siswa[2]->update(['status' => 'keluar']);

        $this->actingAs($this->wali, 'web')->get($this->url())->assertOk()
            ->assertSee($this->siswa[0]->nama)->assertSee($this->siswa[1]->nama)->assertDontSee($this->siswa[2]->nama)
            ->assertSee('2 siswa sudah dinaikkan');

        $this->actingAs($this->guruMapel, 'web')->get("/staf/nilai/{$this->mtk->id}/{$this->kelas->id}")->assertOk()->assertSee($this->siswa[0]->nama);

        $this->actingAs($this->wali, 'web')->post($this->url('/finalkan'))->assertSessionHas('berhasil');
        $this->get($this->url())->assertDontSee('sudah dinaikkan');
        $this->assertSame('final', $this->rapor(0)->status);
    }
}
