<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfilFotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_foto_diunggah_dipotong_persegi_dan_tampil_di_bilah_atas(): void
    {
        $guru = User::factory()->create();
        $this->actingAs($guru, 'web');

        $this->get(route('staf.profil'))->assertOk()->assertSee('Profil Saya');
        $this->post(route('staf.profil.simpan'), ['foto' => UploadedFile::fake()->image('saya.jpg', 800, 400)])->assertRedirect(route('staf.profil'));

        $guru->refresh();
        $this->assertNotNull($guru->foto);
        Storage::disk('local')->assertExists($guru->foto);
        [$lebar, $tinggi] = getimagesizefromstring(Storage::disk('local')->get($guru->foto));
        $this->assertSame([320, 320], [$lebar, $tinggi]);

        $this->get(route('staf.beranda'))->assertOk()->assertSee('staf/profil/foto', false);
        $this->get(route('staf.profil.foto'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_berkas_bukan_gambar_ditolak(): void
    {
        $guru = User::factory()->create();
        $this->actingAs($guru, 'web')
            ->post(route('staf.profil.simpan'), ['foto' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;')])
            ->assertSessionHasErrors('foto');

        $this->assertNull($guru->fresh()->foto);
    }

    public function test_mengganti_dan_menghapus_foto_membersihkan_berkas_lama(): void
    {
        $guru = User::factory()->create();
        $this->actingAs($guru, 'web');

        $this->post(route('staf.profil.simpan'), ['foto' => UploadedFile::fake()->image('a.png', 300, 300)]);
        $pertama = $guru->fresh()->foto;
        $this->post(route('staf.profil.simpan'), ['foto' => UploadedFile::fake()->image('b.png', 300, 300)]);
        $kedua = $guru->fresh()->foto;

        $this->assertNotSame($pertama, $kedua);
        Storage::disk('local')->assertMissing($pertama);

        $this->post(route('staf.profil.simpan'), ['hapus' => 1])->assertRedirect();
        $this->assertNull($guru->fresh()->foto);
        Storage::disk('local')->assertMissing($kedua);
        $this->get(route('staf.profil.foto'))->assertNotFound();
    }

    public function test_foto_hanya_untuk_pemiliknya_dan_butuh_login(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->actingAs($a, 'web')->post(route('staf.profil.simpan'), ['foto' => UploadedFile::fake()->image('a.jpg', 200, 200)]);

        $this->actingAs($b, 'web')->get(route('staf.profil.foto'))->assertNotFound(); // B tidak melihat foto A
        auth('web')->logout();
        $this->get(route('staf.profil'))->assertRedirect();
    }
}
