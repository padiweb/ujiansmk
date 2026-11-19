<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NilaiRapor;
use App\Models\Pengampu;
use App\Models\PengaturanSekolah;
use App\Models\Periode;
use App\Models\RaporSiswa;
use App\Models\SkalaPredikat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class RaporSiswaTampilTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private User $wali;

    private Periode $periode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 2);
        foreach ([[90, 'A'], [80, 'B'], [70, 'C'], [0, 'D']] as [$min, $p]) {
            SkalaPredikat::create(['nilai_min' => $min, 'predikat' => $p]);
        }
        $this->wali = User::factory()->create(['nama' => 'Bu Wali Kelas']);
        $guru = User::factory()->create();
        $this->kelas->update(['wali_kelas_id' => $this->wali->id]);
        Pengampu::create(['guru_id' => $guru->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->kelas->id]);
        $this->periode = $this->jadwal->periode;

        $p = PengaturanSekolah::ambil();
        $p->update(['nama' => 'SMK Uji', 'alamat' => 'Jl. Contoh 1', 'kota' => 'Tanon', 'kepala_sekolah' => 'Pak Kepala', 'nip_kepala_sekolah' => '1980']);
    }

    private function url(string $akhir = ''): string
    {
        return "/staf/wali-kelas/{$this->kelas->id}{$akhir}";
    }

    private function finalkan(): void
    {
        foreach ($this->siswa as $s) {
            NilaiRapor::create([
                'siswa_id' => $s->id, 'mapel_id' => $this->mtk->id, 'periode_id' => $this->periode->id, 'kelas_id' => $this->kelas->id,
                'ujian' => 80, 'harian' => 80, 'tugas_akhir' => 80, 'praktik' => 80, 'adab' => 80, 'np' => 80, 'nk' => 78, 'na' => 79, 'predikat' => 'B', 'status' => 'draf',
            ]);
        }
        $this->actingAs($this->wali, 'web')->post($this->url(), ['rapor' => [
            $this->siswa[0]->id => ['sakit' => '2', 'izin' => '1', 'alpa' => '0', 'catatan' => 'Anak yang rajin', 'keputusan' => 'naik'],
            $this->siswa[1]->id => ['keputusan' => 'tinggal'],
        ]]);
        RaporSiswa::where('siswa_id', $this->siswa[0]->id)->update(['ekskul' => [['nama' => 'Pramuka', 'nilai' => 88, 'predikat' => 'B']]]);
        $this->post($this->url('/finalkan'))->assertSessionHas('berhasil');
    }

    public function test_siswa_melihat_rapornya_sendiri_setelah_final(): void
    {
        $this->periode->update(['tampil_siswa' => true]);
        $this->actingAs($this->siswa[0], 'siswa')->get('/beranda')->assertOk()->assertDontSee('Lihat rapor');
        $this->actingAs($this->siswa[0], 'siswa')->get('/rapor/'.$this->periode->id)->assertNotFound(); // belum final

        $this->actingAs($this->wali, 'web');
        $this->finalkan();

        $this->actingAs($this->siswa[0], 'siswa')->get('/beranda')->assertOk()->assertSee('Lihat rapor');
        $this->get('/rapor/'.$this->periode->id)->assertOk()
            ->assertSee($this->siswa[0]->nama)->assertSee('Matematika')->assertSee('Pramuka')->assertSee('Anak yang rajin')->assertSee('2 hari');
    }

    public function test_rapor_final_tersembunyi_dari_siswa_sampai_semester_dinyalakan_admin(): void
    {
        $this->actingAs($this->wali, 'web');
        $this->finalkan();

        $this->actingAs($this->siswa[0], 'siswa')->get('/beranda')->assertOk()->assertDontSee('Lihat rapor');
        $this->get('/rapor/'.$this->periode->id)->assertNotFound();

        $this->actingAs($this->admin, 'web')->put('/staf/pengaturan-rapor/periode', ['periode' => [$this->periode->id => ['tampil_siswa' => '1']]])->assertSessionHas('berhasil');
        $this->actingAs($this->siswa[0], 'siswa')->get('/beranda')->assertSee('Lihat rapor');
        $this->get('/rapor/'.$this->periode->id)->assertOk();

        $this->actingAs($this->admin, 'web')->put('/staf/pengaturan-rapor/periode', ['periode' => [$this->periode->id => ['tampil_siswa' => '0']]]);
        $this->actingAs($this->siswa[0], 'siswa')->get('/rapor/'.$this->periode->id)->assertNotFound();
    }

    public function test_siswa_tidak_melihat_rapor_siswa_lain_atau_semester_tanpa_rapor(): void
    {
        $this->periode->update(['tampil_siswa' => true]);
        $this->actingAs($this->wali, 'web');
        $this->finalkan();

        $html = $this->actingAs($this->siswa[1], 'siswa')->get('/rapor/'.$this->periode->id)->assertOk()->getContent();
        $this->assertStringContainsString($this->siswa[1]->nama, $html);
        $this->assertStringNotContainsString($this->siswa[0]->nama, $html);
        $this->assertStringNotContainsString('Anak yang rajin', $html); // catatan siswa lain

        $this->get('/rapor/99999')->assertNotFound();
        auth('siswa')->logout();
        $this->get('/rapor/'.$this->periode->id)->assertRedirect();
    }

    public function test_rapor_yang_dibuka_finalnya_tidak_tampil_ke_siswa(): void
    {
        $this->periode->update(['tampil_siswa' => true]);
        $this->actingAs($this->wali, 'web');
        $this->finalkan();
        $this->actingAs($this->admin, 'web')->post($this->url('/buka-final'), ['alasan' => 'koreksi nilai']);

        $this->actingAs($this->siswa[0], 'siswa')->get('/rapor/'.$this->periode->id)->assertNotFound();
    }
    public function test_siswa_mengunduh_rapor_final_sebagai_pdf_dan_yang_belum_final_ditolak(): void
    {
        $this->periode->update(['tampil_siswa' => true]);
        $this->actingAs($this->siswa[0], 'siswa')->get("/rapor/{$this->periode->id}/pdf")->assertNotFound(); // belum final

        $this->actingAs($this->wali, 'web');
        $this->finalkan();

        $r = $this->actingAs($this->siswa[0], 'siswa')->get("/rapor/{$this->periode->id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="Rapor_', $r->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertDatabaseHas('audit_log', ['aksi' => 'rapor.unduh']);

        $this->get('/beranda')->assertSee('Unduh PDF');
        $this->get("/rapor/{$this->periode->id}")->assertSee('Unduh PDF rapor');
    }

    public function test_pdf_rapor_mengikuti_pembukaan_semester_dan_tamu_ditolak(): void
    {
        $this->actingAs($this->wali, 'web');
        $this->finalkan();
        $this->periode->update(['tampil_siswa' => false]);

        $this->actingAs($this->siswa[0], 'siswa')->get("/rapor/{$this->periode->id}/pdf")->assertNotFound();
        auth('siswa')->logout();
        $this->get("/rapor/{$this->periode->id}/pdf")->assertRedirect();
    }
}