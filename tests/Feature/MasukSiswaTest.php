<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Siswa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class MasukSiswaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login:siswa:0011223344|127.0.0.1');
    }

    public function test_halaman_masuk_tampil_untuk_tamu(): void
    {
        $this->get('/')->assertOk()->assertSee('Masuk Siswa');
    }

    public function test_siswa_masuk_dengan_nisn_dan_kata_sandi_benar(): void
    {
        $siswa = Siswa::factory()->create(['nisn' => '0011223344']);

        $this->post('/masuk', ['username' => '0011223344', 'password' => 'kata-sandi-uji-123'])
            ->assertRedirect(route('siswa.beranda'));

        $this->assertAuthenticatedAs($siswa, 'siswa');
        $this->assertDatabaseHas('audit_log', ['aksi' => 'siswa.masuk', 'pelaku_id' => $siswa->id]);
    }

    public function test_siswa_masuk_dengan_nama_pengguna_eduweb_tanpa_peduli_huruf_besar(): void
    {
        $siswa = Siswa::factory()->create(['nisn' => '0011223344', 'username' => 'Budi.S']);

        $this->post('/masuk', ['username' => 'budi.s', 'password' => 'kata-sandi-uji-123'])->assertRedirect(route('siswa.beranda'));
        $this->assertAuthenticatedAs($siswa, 'siswa');
    }

    public function test_nama_pengguna_didahulukan_sebelum_nisn_saat_sama(): void
    {
        $a = Siswa::factory()->create(['nisn' => '5555', 'username' => 'siswa.a']);
        $b = Siswa::factory()->create(['nisn' => '6666', 'username' => '5555']);

        $this->post('/masuk', ['username' => '5555', 'password' => 'kata-sandi-uji-123'])->assertRedirect(route('siswa.beranda'));
        $this->assertAuthenticatedAs($b, 'siswa');
    }

    public function test_kata_sandi_disimpan_sebagai_hash(): void
    {
        $siswa = Siswa::factory()->create();

        $this->assertNotSame('kata-sandi-uji-123', $siswa->getRawOriginal('password'));
        $this->assertStringStartsWith('$2y$', $siswa->getRawOriginal('password'));
    }

    public function test_kata_sandi_salah_ditolak_dengan_pesan_umum(): void
    {
        Siswa::factory()->create(['nisn' => '0011223344']);

        $this->from('/')->post('/masuk', ['username' => '0011223344', 'password' => 'salah'])
            ->assertRedirect('/')
            ->assertSessionHasErrors(['username' => 'Nama pengguna atau kata sandi salah.']);

        $this->assertGuest('siswa');
    }

    public function test_nisn_tidak_dikenal_mendapat_pesan_yang_sama(): void
    {
        $this->from('/')->post('/masuk', ['username' => '9999999999', 'password' => 'apa-saja'])
            ->assertSessionHasErrors(['username' => 'Nama pengguna atau kata sandi salah.']);
    }

    public function test_siswa_lulus_atau_keluar_tidak_bisa_masuk(): void
    {
        Siswa::factory()->status('lulus')->create(['nisn' => '0011223344']);

        $this->post('/masuk', ['username' => '0011223344', 'password' => 'kata-sandi-uji-123']);

        $this->assertGuest('siswa');
    }

    public function test_dibatasi_setelah_lima_kali_gagal(): void
    {
        Siswa::factory()->create(['nisn' => '0011223344']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/masuk', ['username' => '0011223344', 'password' => 'salah']);
        }

        // Percobaan ke-6 ditolak walau kata sandinya benar.
        $this->post('/masuk', ['username' => '0011223344', 'password' => 'kata-sandi-uji-123'])
            ->assertSessionHasErrors('username');

        $this->assertStringContainsString('Terlalu banyak percobaan', session('errors')->first('username'));

        $this->assertGuest('siswa');
    }

    public function test_beranda_siswa_butuh_login(): void
    {
        $this->get('/beranda')->assertRedirect(route('siswa.masuk'));
    }

    public function test_siswa_bisa_keluar(): void
    {
        $siswa = Siswa::factory()->create();

        $this->actingAs($siswa, 'siswa')->post('/keluar')->assertRedirect(route('siswa.masuk'));

        $this->assertGuest('siswa');
    }

    public function test_siswa_tidak_bisa_membuka_area_staf(): void
    {
        $siswa = Siswa::factory()->create();

        $this->actingAs($siswa, 'siswa')->get('/staf')->assertRedirect(route('staf.masuk'));
    }

    public function test_kegagalan_masuk_tidak_menyimpan_kata_sandi_di_log(): void
    {
        $this->post('/masuk', ['username' => '0011223344', 'password' => 'rahasia-banget']);

        $this->assertFalse(AuditLog::where('detail', 'like', '%rahasia-banget%')->exists());
    }
}
