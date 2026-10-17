<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HalamanGalatTest extends TestCase
{
    use RefreshDatabase;

    public function test_403_tampil_ramah_dan_tanpa_jejak_teknis(): void
    {
        $guru = User::factory()->create();

        $html = $this->actingAs($guru, 'web')->get('/staf/pengaturan')->assertForbidden()
            ->assertSee('Akses ditolak')->assertSee('Anda tidak memiliki akses ke halaman ini.')
            ->assertSee('Kembali ke beranda')->getContent();

        $this->assertStringNotContainsString('Symfony', $html);
        $this->assertStringContainsString(route('staf.beranda'), $html);
    }

    public function test_403_dengan_pesan_khusus_menampilkan_pesan_itu(): void
    {
        $guru = User::factory()->create();
        $this->actingAs($guru, 'web')->post('/staf/rekap/1/buka-kunci')->assertStatus(404);
        $this->actingAs($guru, 'web')->get('/staf/master/siswa')->assertForbidden()->assertSee('Anda tidak memiliki akses');
    }

    public function test_404_tampil_ramah(): void
    {
        $this->get('/halaman-yang-tidak-ada')->assertNotFound()->assertSee('Halaman tidak ditemukan')->assertSee('Ke halaman masuk');
        $this->get('/staf/tidak-ada')->assertNotFound()->assertSee(route('staf.masuk'), false);
    }

    public function test_halaman_kedaluwarsa_diarahkan_ke_masuk_dengan_pesan_bukan_page_expired(): void
    {
        Route::middleware('web')->post('/staf/uji-419', fn () => throw new TokenMismatchException);
        Route::middleware('web')->post('/uji-419-siswa', fn () => throw new TokenMismatchException);

        $this->post('/staf/uji-419')->assertRedirect(route('staf.masuk'))->assertSessionHas('info');
        $this->post('/uji-419-siswa')->assertRedirect(route('siswa.masuk'))->assertSessionHas('info');
        $this->get(route('staf.masuk'))->assertSee('Sesi Anda sudah berakhir');
    }

    public function test_kedaluwarsa_saat_sudah_masuk_kembali_dengan_pesan_di_halaman_sebelumnya(): void
    {
        Route::middleware('web')->post('/staf/uji-419', fn () => throw new TokenMismatchException);
        $guru = User::factory()->create();

        $this->actingAs($guru, 'web')->from('/staf/bank-soal')->post('/staf/uji-419')
            ->assertRedirect('/staf/bank-soal')->assertSessionHas('gagal');
    }

    public function test_klien_json_tetap_mendapat_419_json(): void
    {
        Route::middleware('web')->post('/api-uji-419', fn () => throw new TokenMismatchException);

        $this->postJson('/api-uji-419')->assertStatus(419);
    }
}
