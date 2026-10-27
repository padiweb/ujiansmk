<?php

namespace Tests\Feature;

use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DaftarSiswaTampilanTest extends TestCase
{
    use RefreshDatabase;

    public function test_daftar_siswa_memakai_susunan_kolom_aplikasi_lama(): void
    {
        $tahun = TahunAjaran::create(['nama' => '2025/2026', 'aktif' => true]);
        $kelas = Kelas::create(['tahun_ajaran_id' => $tahun->id, 'tingkat' => 'X', 'nama' => 'X TKJ 1']);
        $s = Siswa::create(['nisn' => '0012345678', 'nis' => '2024001', 'nama' => 'Ahmad Fauzi', 'status' => 'aktif', 'password' => 'Rahasia123', 'no_peserta_us' => 'US-0001']);
        $s->kelas()->attach($kelas->id, ['status' => 'aktif']);

        $res = $this->actingAs(User::factory()->admin()->create(), 'web')->get('/staf/master/siswa')->assertOk();
        $res->assertSeeInOrder(['Nama Siswa', 'Kelas', 'No. Peserta US', 'Ruang Ujian', 'Username', 'Status'])
            ->assertSee('NIS: 2024001')->assertSee('X TKJ 1')->assertSee('US-0001')->assertSee('Belum diset')
            ->assertSee('Total:', false)->assertSee('Ruang');
    }

    public function test_pencarian_dan_filter_kelas_membatasi_daftar(): void
    {
        $tahun = TahunAjaran::create(['nama' => '2025/2026', 'aktif' => true]);
        $kelas = Kelas::create(['tahun_ajaran_id' => $tahun->id, 'tingkat' => 'X', 'nama' => 'X TKJ 1']);
        $a = Siswa::create(['nisn' => '0011111111', 'nama' => 'Ahmad Fauzi', 'status' => 'aktif', 'password' => 'Rahasia123']);
        Siswa::create(['nisn' => '0022222222', 'nama' => 'Budi Santoso', 'status' => 'aktif', 'password' => 'Rahasia123']);
        $a->kelas()->attach($kelas->id, ['status' => 'aktif']);
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->get('/staf/master/siswa?q=Budi')->assertSee('Budi Santoso')->assertDontSee('Ahmad Fauzi')->assertSee('Atur ulang');
        $this->get('/staf/master/siswa?kelas='.$kelas->id)->assertSee('Ahmad Fauzi')->assertDontSee('Budi Santoso');
        $this->get('/staf/master/siswa?q=tidakada')->assertSee('Tidak ada siswa yang cocok');
    }
}
