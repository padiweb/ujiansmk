<?php

namespace Tests\Feature;

use App\Models\KegiatanUjian;
use App\Models\Pengampu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class KegiatanUjianTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private KegiatanUjian $kegiatan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(denganEsai: true, jumlahSiswa: 1);
        $this->kegiatan = KegiatanUjian::create(['periode_id' => $this->jadwal->periode_id, 'nama' => 'Asesmen Semester Gasal', 'aktif' => true]);
        $this->jadwal->update(['kegiatan_id' => $this->kegiatan->id, 'nama' => 'Asesmen Semester Gasal – Matematika']);
    }

    public function test_hanya_admin_yang_mengelola_kegiatan(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->get('/staf/kegiatan')->assertForbidden();
        $this->actingAs(User::factory()->pengawas()->create(), 'web')->post('/staf/kegiatan', [])->assertForbidden();
        $this->actingAs($this->admin, 'web')->get('/staf/kegiatan')->assertOk()->assertSee('Asesmen Semester Gasal');
        $this->get('/staf')->assertSee('Kegiatan Ujian');
    }

    public function test_tambah_kegiatan_dan_nama_ganda_ditolak(): void
    {
        $this->actingAs($this->admin, 'web');
        $p = $this->jadwal->periode_id;

        $this->post('/staf/kegiatan', ['periode_id' => $p, 'nama' => 'Asesmen Akhir Tahun'])->assertSessionHas('berhasil');
        $this->assertDatabaseHas('kegiatan_ujian', ['nama' => 'Asesmen Akhir Tahun', 'aktif' => true]);
        $this->post('/staf/kegiatan', ['periode_id' => $p, 'nama' => 'Asesmen Akhir Tahun'])->assertSessionHasErrors('nama');
        $this->post('/staf/kegiatan', ['periode_id' => $p, 'nama' => ''])->assertSessionHasErrors('nama');
    }

    public function test_kegiatan_bernama_ujian_sekolah_di_semester_reguler_diberi_peringatan(): void
    {
        $this->actingAs($this->admin, 'web');
        $reguler = $this->jadwal->periode_id;
        $us = \App\Models\Periode::create(['tahun_ajaran_id' => $this->jadwal->periode->tahun_ajaran_id, 'semester' => 'genap', 'tipe' => 'ujian_sekolah', 'aktif' => false, 'rapor_di_sini' => false]);

        $this->post('/staf/kegiatan', ['periode_id' => $reguler, 'nama' => 'Ujian Sekolah 2026'])
            ->assertSessionHas('catatan', fn ($m) => str_contains($m, 'semester reguler'))->assertSessionMissing('berhasil');
        $this->post('/staf/kegiatan', ['periode_id' => $us->id, 'nama' => 'Ujian Sekolah 2026'])->assertSessionHas('berhasil')->assertSessionMissing('catatan');
        $this->post('/staf/kegiatan', ['periode_id' => $reguler, 'nama' => 'Asesmen Sumatif Akhir'])->assertSessionHas('berhasil'); // nama biasa tidak diperingatkan

        $k = KegiatanUjian::where('periode_id', $reguler)->where('nama', 'Ujian Sekolah 2026')->first();
        $this->put("/staf/kegiatan/{$k->id}", ['untuk_rapor' => '1'])->assertSessionHas('catatan', fn ($m) => str_contains($m, 'sehingga nilainya bisa ikut masuk rapor'));
        $this->put("/staf/kegiatan/{$k->id}", ['untuk_rapor' => '0'])->assertSessionHas('catatan', fn ($m) => ! str_contains($m, 'sehingga nilainya'));
    }

    public function test_ganti_nama_kegiatan_memperbarui_nama_semua_jadwalnya(): void
    {
        $this->actingAs($this->admin, 'web')->put("/staf/kegiatan/{$this->kegiatan->id}", ['nama' => 'Asesmen Sumatif Gasal'])->assertSessionHas('berhasil');

        $this->assertSame('Asesmen Sumatif Gasal – Matematika', $this->jadwal->fresh()->nama);
    }

    public function test_hapus_hanya_bila_belum_dipakai_jadwal(): void
    {
        $this->actingAs($this->admin, 'web');
        $kosong = KegiatanUjian::create(['periode_id' => $this->jadwal->periode_id, 'nama' => 'Kosong', 'aktif' => true]);

        $this->delete("/staf/kegiatan/{$this->kegiatan->id}")->assertSessionHas('gagal');
        $this->assertDatabaseHas('kegiatan_ujian', ['id' => $this->kegiatan->id]);
        $this->delete("/staf/kegiatan/{$kosong->id}")->assertSessionHas('berhasil');
        $this->assertDatabaseMissing('kegiatan_ujian', ['id' => $kosong->id]);
    }

    public function test_kegiatan_tidak_aktif_menghilang_dari_koreksi_dan_rekap_guru_tetapi_tidak_dari_admin(): void
    {
        $guru = User::factory()->create();
        Pengampu::create(['guru_id' => $guru->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->kelas->id]);

        $this->actingAs($guru, 'web')->get('/staf/rekap')->assertOk()->assertSee('Matematika · X A');
        $this->get('/staf/koreksi')->assertOk()->assertSee('Matematika · X A');

        $this->actingAs($this->admin, 'web')->put("/staf/kegiatan/{$this->kegiatan->id}", ['aktif' => 0])->assertSessionHas('berhasil');
        $this->assertFalse($this->kegiatan->fresh()->aktif);

        $this->actingAs($guru, 'web')->get('/staf/rekap')->assertOk()->assertDontSee('Matematika · X A');
        $this->get('/staf/koreksi')->assertOk()->assertDontSee('Matematika · X A');
        $this->get("/staf/rekap/{$this->jadwal->id}")->assertNotFound();
        $this->actingAs($this->admin, 'web')->get('/staf/rekap')->assertOk()->assertSee('Matematika · X A');

        $this->put("/staf/kegiatan/{$this->kegiatan->id}", ['aktif' => 1]);
        $this->actingAs($guru, 'web')->get('/staf/rekap')->assertOk()->assertSee('Matematika · X A');
    }
}
