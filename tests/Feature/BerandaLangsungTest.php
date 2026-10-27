<?php

namespace Tests\Feature;

use App\Models\SesiUjian;
use App\Models\User;
use App\Services\LayananPelanggaran;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class BerandaLangsungTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 3);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_beranda_menampilkan_jadwal_aktif_dan_token_untuk_admin(): void
    {
        $this->actingAs($this->admin, 'web')->get('/staf')->assertOk()
            ->assertSee('Monitor Langsung')->assertSee('UTS Matematika')->assertSee($this->jadwal->token)->assertSee('Siswa aktif');
    }

    public function test_data_langsung_menghitung_status_dan_mendaftar_yang_terblokir(): void
    {
        $this->mulaiSesi($this->siswa[0]);
        $this->mulaiSesi($this->siswa[1]);
        Carbon::setTestNow(now()->addSeconds(10));
        $sesi = $this->sesiDari($this->siswa[1]);
        for ($i = 0; $i < $this->jadwal->batas_pelanggaran; $i++) {
            app(LayananPelanggaran::class)->catat($sesi->fresh(), 'pindah_tab', null);
            Carbon::setTestNow(now()->addSeconds(10));
        }

        $h = $this->actingAs($this->admin, 'web')->getJson('/staf/beranda/data')->assertOk()->json();
        $this->assertTrue($h['aktif']);
        $this->assertSame(1, $h['ringkasan']['mengerjakan']);
        $this->assertSame(1, $h['ringkasan']['terblokir']);
        $this->assertSame(1, $h['ringkasan']['belum']);
        $this->assertCount(1, $h['masalah']);
        $this->assertTrue($h['masalah'][0]['terblokir']);
        $this->assertTrue($h['masalah'][0]['bisa_aktifkan']);
        $this->assertSame($this->siswa[1]->nama, $h['masalah'][0]['nama']);
    }

    public function test_filter_ruang_membatasi_angka(): void
    {
        $this->mulaiSesi($this->siswa[0]);

        $h = $this->actingAs($this->admin, 'web')->getJson('/staf/beranda/data?ruang=999999')->assertOk()->json();
        $this->assertSame(0, $h['ringkasan']['mengerjakan']);
        $this->assertSame([], $h['masalah']);
    }

    public function test_guru_tanpa_mapel_ujian_tidak_melihat_angka_dan_tamu_ditolak(): void
    {
        $guru = User::factory()->create();

        $h = $this->actingAs($guru, 'web')->getJson('/staf/beranda/data')->assertOk()->json();
        $this->assertFalse($h['aktif']);
        $this->assertSame(0, $h['ringkasan']['mengerjakan']);

        $this->flushSession();
        auth('web')->logout();
        $this->getJson('/staf/beranda/data')->assertUnauthorized();
    }
}
