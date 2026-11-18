<?php

namespace Tests\Feature;

use App\Models\Siswa;
use App\Models\User;
use App\Support\FotoEduweb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FotoEduwebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['eduweb.url' => 'https://eduweb.contoh.sch.id']);
    }

    public function test_jalur_foto_dibersihkan_dan_yang_mencurigakan_ditolak(): void
    {
        $this->assertSame('photos/guru/siti.jpg', FotoEduweb::bersihkan('photos/guru/siti.jpg'));
        $this->assertSame('photos/a.png', FotoEduweb::bersihkan('/storage/photos/a.png'));

        foreach ([null, '', 'photos/../.env', 'http://jahat.example/x.jpg', '//jahat.example/x.jpg', 'photos/x.php', 'photos/a b.jpg', 'photos/x.jpg?x=1', ['a']] as $buruk) {
            $this->assertNull(FotoEduweb::bersihkan($buruk), json_encode($buruk));
        }
    }

    public function test_alamat_dibentuk_dari_host_eduweb_saja_dan_wajib_https(): void
    {
        $this->assertSame('https://eduweb.contoh.sch.id/storage/photos/siti.jpg', FotoEduweb::url('photos/siti.jpg'));

        config(['eduweb.url' => 'https://eduweb.contoh.sch.id/api/exam/v1']);
        $this->assertSame('https://eduweb.contoh.sch.id/storage/photos/siti.jpg', FotoEduweb::url('photos/siti.jpg'));

        config(['eduweb.url' => 'http://eduweb.contoh.sch.id']);
        $this->assertNull(FotoEduweb::url('photos/siti.jpg'));
        config(['eduweb.url' => null]);
        $this->assertNull(FotoEduweb::url('photos/siti.jpg'));
    }

    public function test_foto_eduweb_tampil_di_bilah_atas_staf_dan_unggahan_sendiri_diutamakan(): void
    {
        Storage::fake('local');
        $guru = User::factory()->create(['foto_eduweb' => 'photos/guru/siti.jpg']);
        $this->actingAs($guru, 'web');

        $this->get(route('staf.beranda'))->assertOk()->assertSee('https://eduweb.contoh.sch.id/storage/photos/guru/siti.jpg', false);
        $this->get(route('staf.profil'))->assertOk()->assertSee('diambil dari EduWeb');

        $this->post(route('staf.profil.simpan'), ['foto' => UploadedFile::fake()->image('a.jpg', 300, 300)]);
        $this->get(route('staf.beranda'))->assertOk()->assertSee('staf/profil/foto', false)->assertDontSee('eduweb.contoh.sch.id/storage', false);

        // Hapus unggahan: kembali ke foto EduWeb.
        $this->post(route('staf.profil.simpan'), ['hapus' => 1]);
        $this->get(route('staf.beranda'))->assertSee('https://eduweb.contoh.sch.id/storage/photos/guru/siti.jpg', false);
    }

    public function test_foto_siswa_dari_eduweb_tampil_di_beranda_siswa(): void
    {
        $siswa = Siswa::factory()->create(['foto_eduweb' => 'photos/siswa/ani.jpg']);

        $this->actingAs($siswa, 'siswa')->get(route('siswa.beranda'))->assertOk()->assertSee('https://eduweb.contoh.sch.id/storage/photos/siswa/ani.jpg', false);
    }
}
