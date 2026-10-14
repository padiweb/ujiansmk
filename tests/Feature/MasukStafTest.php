<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MasukStafTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_masuk_staf_tampil(): void
    {
        $this->get('/staf/masuk')->assertOk()->assertSee('Masuk Staf');
    }

    public function test_staf_masuk_dan_waktu_login_terakhir_tercatat(): void
    {
        $user = User::factory()->admin()->create(['username' => 'bu.admin']);

        $this->post('/staf/masuk', ['username' => 'bu.admin', 'password' => 'kata-sandi-uji-123'])
            ->assertRedirect(route('staf.beranda'));

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->fresh()->login_terakhir);
    }

    public function test_kata_sandi_salah_ditolak(): void
    {
        User::factory()->create(['username' => 'pak.guru']);

        $this->from('/staf/masuk')->post('/staf/masuk', ['username' => 'pak.guru', 'password' => 'salah'])
            ->assertSessionHasErrors(['username' => 'Nama pengguna atau kata sandi salah.']);

        $this->assertGuest('web');
    }

    public function test_akun_nonaktif_tidak_bisa_masuk(): void
    {
        User::factory()->nonaktif()->create(['username' => 'pak.guru']);

        $this->post('/staf/masuk', ['username' => 'pak.guru', 'password' => 'kata-sandi-uji-123']);

        $this->assertGuest('web');
    }

    public function test_dibatasi_setelah_lima_kali_gagal(): void
    {
        User::factory()->create(['username' => 'pak.guru']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/staf/masuk', ['username' => 'pak.guru', 'password' => 'salah']);
        }

        $this->post('/staf/masuk', ['username' => 'pak.guru', 'password' => 'kata-sandi-uji-123'])
            ->assertSessionHasErrors('username');

        $this->assertStringContainsString('Terlalu banyak percobaan', session('errors')->first('username'));
    }

    public function test_beranda_staf_butuh_login(): void
    {
        $this->get('/staf')->assertRedirect(route('staf.masuk'));
    }

    public function test_peran_dibatasi_oleh_middleware(): void
    {
        Route::middleware(['web', 'auth:web', 'peran:admin'])->get('/uji/hanya-admin', fn () => 'ok');

        $this->actingAs(User::factory()->admin()->create(), 'web')->get('/uji/hanya-admin')->assertOk();
        $this->actingAs(User::factory()->create(), 'web')->get('/uji/hanya-admin')->assertForbidden();
        $this->actingAs(User::factory()->pengawas()->create(), 'web')->get('/uji/hanya-admin')->assertForbidden();
    }

    public function test_staf_dinonaktifkan_saat_sesi_berjalan_langsung_ditolak(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user, 'web')->get('/staf')->assertOk();

        $user->update(['aktif' => false]);

        $this->actingAs($user->fresh(), 'web')->get('/staf')->assertForbidden();
    }

    public function test_staf_bisa_keluar(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->post('/staf/keluar')
            ->assertRedirect(route('staf.masuk'));

        $this->assertGuest('web');
    }
}
