<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuPengawasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_hanya_admin_dan_menu_tampil_di_sidebar_admin(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->get('/staf/pengawas')->assertForbidden();
        $this->actingAs(User::factory()->pengawas()->create(), 'web')->get('/staf/pengawas')->assertForbidden();
        $this->actingAs($this->admin, 'web')->get('/staf')->assertSee('Pengawas');
        $this->get('/staf/pengawas')->assertOk()->assertSee('Jadikan guru sebagai pengawas');
    }

    public function test_guru_dijadikan_dan_dicabut_sebagai_pengawas(): void
    {
        $guru = User::factory()->create(['nama' => 'Siti Aminah']);
        $this->actingAs($this->admin, 'web');

        $this->get('/staf/pengawas')->assertSee('Jadikan pengawas');
        $this->put("/staf/pengawas/{$guru->id}", ['aktif' => '1'])->assertSessionHas('berhasil');
        $this->assertTrue($guru->fresh()->mengawasi());
        $this->assertDatabaseHas('audit_log', ['aksi' => 'pengawas.jadikan']);
        $this->get('/staf/pengawas')->assertSee('Cabut tugas');

        $this->put("/staf/pengawas/{$guru->id}")->assertSessionHas('berhasil');
        $this->assertFalse($guru->fresh()->mengawasi());
        $this->assertSame('guru', $guru->fresh()->role); // tetap guru
    }

    public function test_tambah_akun_pengawas_lokal_dan_validasi(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->post('/staf/pengawas', ['nama' => 'Budi', 'username' => 'budi tu', 'password' => 'pendek'])
            ->assertSessionHasErrors(['username', 'password']);

        $this->post('/staf/pengawas', ['nama' => 'Budi TU', 'username' => 'budi.tu', 'password' => 'sandi-panjang-123'])->assertSessionHas('berhasil');
        $u = User::where('username', 'budi.tu')->first();
        $this->assertSame('pengawas', $u->role);
        $this->assertNull($u->eduweb_id);
        $this->assertTrue($u->mengawasi());

        $this->post('/staf/pengawas', ['nama' => 'Lain', 'username' => 'budi.tu', 'password' => 'sandi-panjang-123'])->assertSessionHasErrors('username');

        $this->put("/staf/pengawas/{$u->id}")->assertSessionHas('berhasil');
        $this->assertFalse($u->fresh()->aktif);
        $this->assertFalse($u->fresh()->mengawasi());
    }

    public function test_admin_tidak_bisa_diubah_lewat_menu_ini(): void
    {
        $this->actingAs($this->admin, 'web')->put("/staf/pengawas/{$this->admin->id}", ['aktif' => '0'])->assertNotFound();
    }
}
