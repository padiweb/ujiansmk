<?php

namespace Tests\Feature;

use App\Models\Jurusan;
use App\Models\Kelas;
use App\Models\PengaturanSekolah;
use App\Models\Ruang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_tamu_dan_guru_tidak_bisa_membuka_master_data(): void
    {
        $this->get('/staf/master/siswa')->assertRedirect(route('staf.masuk'));
        $this->actingAs(User::factory()->create(), 'web')->get('/staf/master/siswa')->assertForbidden();
        $this->actingAs(User::factory()->pengawas()->create(), 'web')->get('/staf/master/siswa')->assertForbidden();
    }

    public function test_entitas_tidak_dikenal_404(): void
    {
        $this->actingAs($this->admin(), 'web')->get('/staf/master/tidak-ada')->assertNotFound();
    }

    public function test_semua_halaman_daftar_dan_formulir_tampil(): void
    {
        $this->actingAs($this->admin(), 'web');

        foreach (['siswa', 'staf', 'tahun-ajaran', 'periode', 'jurusan', 'kelas', 'mapel', 'pengampu', 'ruang'] as $slug) {
            $this->get("/staf/master/{$slug}")->assertOk();
            $this->get("/staf/master/{$slug}/tambah")->assertOk();
        }
    }

    public function test_tambah_ubah_hapus_jurusan(): void
    {
        $this->actingAs($this->admin(), 'web');

        $this->post('/staf/master/jurusan', ['kode' => 'TKJ', 'nama' => 'Teknik Komputer dan Jaringan'])
            ->assertRedirect(route('staf.master.index', 'jurusan'));
        $j = Jurusan::firstWhere('kode', 'TKJ');
        $this->assertNotNull($j);

        $this->put("/staf/master/jurusan/{$j->id}", ['kode' => 'TKJ', 'nama' => 'TKJ Baru']);
        $this->assertSame('TKJ Baru', $j->fresh()->nama);

        $this->delete("/staf/master/jurusan/{$j->id}")->assertRedirect();
        $this->assertNull(Jurusan::find($j->id));
    }

    public function test_kode_jurusan_harus_unik_dengan_pesan_indonesia(): void
    {
        $this->actingAs($this->admin(), 'web');
        Jurusan::create(['kode' => 'TKJ', 'nama' => 'A']);

        $this->from('/staf/master/jurusan/tambah')->post('/staf/master/jurusan', ['kode' => 'TKJ', 'nama' => 'B'])
            ->assertSessionHasErrors(['kode' => 'Kode sudah dipakai.']);
    }

    public function test_tahun_ajaran_aktif_hanya_satu(): void
    {
        $this->actingAs($this->admin(), 'web');

        $this->post('/staf/master/tahun-ajaran', ['nama' => '2025/2026', 'aktif' => '1']);
        $this->post('/staf/master/tahun-ajaran', ['nama' => '2026/2027', 'aktif' => '1']);

        $this->assertSame(1, TahunAjaran::where('aktif', true)->count());
        $this->assertSame('2026/2027', TahunAjaran::aktif()->nama);
    }

    public function test_format_tahun_ajaran_divalidasi(): void
    {
        $this->actingAs($this->admin(), 'web');

        $this->post('/staf/master/tahun-ajaran', ['nama' => '2025-2026', 'aktif' => '0'])->assertSessionHasErrors('nama');
    }

    public function test_siswa_baru_tanpa_sandi_mendapat_sandi_acak_sekali_tampil(): void
    {
        $this->actingAs($this->admin(), 'web');
        $tahun = TahunAjaran::create(['nama' => '2025/2026', 'aktif' => true]);
        $kelas = Kelas::create(['tahun_ajaran_id' => $tahun->id, 'tingkat' => 'X', 'nama' => 'X TKJ 1']);

        $r = $this->post('/staf/master/siswa', [
            'nisn' => '0012345678', 'nama' => 'Ahmad Fauzi', 'status' => 'aktif', 'kelas_id' => $kelas->id,
        ]);

        $r->assertRedirect(route('staf.master.index', 'siswa'));
        $catatan = session('catatan');
        $this->assertStringContainsString('Kata sandi sementara untuk Ahmad Fauzi', $catatan);

        // Sandi yang ditampilkan benar-benar bisa dipakai login, dan tersimpan sebagai hash.
        preg_match('/: (\S{8}) \(/', $catatan, $m);
        $siswa = Siswa::firstWhere('nisn', '0012345678');
        $this->assertStringStartsWith('$2y$', $siswa->getRawOriginal('password'));
        $this->assertTrue(\Hash::check($m[1], $siswa->password));
        $this->assertSame([$kelas->id], $siswa->kelas->pluck('id')->all());
    }

    public function test_ubah_siswa_tanpa_sandi_tidak_mengganti_sandi(): void
    {
        $this->actingAs($this->admin(), 'web');
        $siswa = Siswa::factory()->create(['nisn' => '0012345678']);
        $lama = $siswa->getRawOriginal('password');

        $this->put("/staf/master/siswa/{$siswa->id}", [
            'nisn' => '0012345678', 'nama' => 'Nama Baru', 'status' => 'aktif', 'password' => '',
        ]);

        $this->assertSame('Nama Baru', $siswa->fresh()->nama);
        $this->assertSame($lama, $siswa->fresh()->getRawOriginal('password'));
    }

    public function test_pindah_kelas_menggantikan_kelas_lama_di_tahun_aktif(): void
    {
        $this->actingAs($this->admin(), 'web');
        $tahun = TahunAjaran::create(['nama' => '2025/2026', 'aktif' => true]);
        $a = Kelas::create(['tahun_ajaran_id' => $tahun->id, 'tingkat' => 'X', 'nama' => 'X A']);
        $b = Kelas::create(['tahun_ajaran_id' => $tahun->id, 'tingkat' => 'X', 'nama' => 'X B']);
        $siswa = Siswa::factory()->create();
        $siswa->kelas()->attach($a->id, ['status' => 'aktif']);

        $this->put("/staf/master/siswa/{$siswa->id}", [
            'nisn' => $siswa->nisn, 'nama' => $siswa->nama, 'status' => 'aktif', 'kelas_id' => $b->id,
        ]);

        $this->assertSame([$b->id], $siswa->fresh()->kelas->pluck('id')->all());
    }

    public function test_kelas_yang_masih_punya_siswa_tidak_bisa_dihapus(): void
    {
        $this->actingAs($this->admin(), 'web');
        $tahun = TahunAjaran::create(['nama' => '2025/2026', 'aktif' => true]);
        $kelas = Kelas::create(['tahun_ajaran_id' => $tahun->id, 'tingkat' => 'X', 'nama' => 'X A']);
        Siswa::factory()->create()->kelas()->attach($kelas->id, ['status' => 'aktif']);

        $this->delete("/staf/master/kelas/{$kelas->id}")->assertSessionHas('gagal');

        $this->assertNotNull(Kelas::find($kelas->id));
    }

    public function test_admin_tidak_bisa_menghapus_atau_menurunkan_dirinya_sendiri(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web');

        $this->delete("/staf/master/staf/{$admin->id}")->assertSessionHas('gagal');
        $this->assertNotNull(User::find($admin->id));

        $this->put("/staf/master/staf/{$admin->id}", [
            'nama' => $admin->nama, 'username' => $admin->username, 'role' => 'guru', 'aktif' => '1',
        ])->assertSessionHasErrors('role');
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_tidak_boleh_menghapus_admin_terakhir_oleh_admin_lain_yang_nonaktif(): void
    {
        $admin = $this->admin();
        $lain = User::factory()->admin()->nonaktif()->create();
        $this->actingAs($admin, 'web');

        // Menghapus admin nonaktif boleh (masih ada satu admin aktif: diri sendiri).
        $this->delete("/staf/master/staf/{$lain->id}")->assertRedirect(route('staf.master.index', 'staf'));
        $this->assertNull(User::find($lain->id));
    }

    public function test_mode_sinkron_mengunci_perubahan_data_dari_eduweb(): void
    {
        $this->actingAs($this->admin(), 'web');
        PengaturanSekolah::ambil()->update(['mode_sinkron_eduweb' => true]);

        $this->get('/staf/master/siswa')->assertOk()->assertSee('dikelola dari EduWeb');
        $this->get('/staf/master/siswa/tambah')->assertForbidden();
        $this->post('/staf/master/jurusan', ['kode' => 'TKJ', 'nama' => 'x'])->assertForbidden();
        $this->get('/staf/master/siswa/impor')->assertForbidden();
        $this->assertSame(0, Jurusan::count());
    }

    public function test_ruang_dan_staf_tidak_ikut_dikunci_mode_sinkron(): void
    {
        $this->actingAs($this->admin(), 'web');
        PengaturanSekolah::ambil()->update(['mode_sinkron_eduweb' => true]);

        $this->post('/staf/master/ruang', ['nama' => 'Lab 1'])->assertRedirect();
        $this->assertSame(1, Ruang::count());
        $this->get('/staf/master/staf/tambah')->assertOk();
    }

    public function test_mode_sinkron_diabaikan_di_pengaturan_bila_bukan_mode_pengembang(): void
    {
        $this->actingAs($this->admin(), 'web');

        $this->get('/staf/pengaturan')->assertOk()->assertDontSee('Sinkron dengan EduWeb');
        $this->put('/staf/pengaturan', [
            'nama' => 'SMK Alhikmah Tanon', 'kurikulum' => 'Kurikulum Merdeka', 'mode_sinkron_eduweb' => '1',
        ])->assertSessionHas('berhasil');

        $this->assertFalse(PengaturanSekolah::sinkronAktif());
    }

    public function test_pengaturan_sekolah_bisa_disimpan(): void
    {
        config(['ujian.mode_pengembang' => true]);
        $this->actingAs($this->admin(), 'web');

        $this->put('/staf/pengaturan', [
            'nama' => 'SMK Alhikmah Tanon', 'kurikulum' => 'Kurikulum Merdeka', 'mode_sinkron_eduweb' => '1',
        ])->assertSessionHas('berhasil');

        $this->assertTrue(PengaturanSekolah::sinkronAktif());
    }

    public function test_pencarian_dan_filter_siswa(): void
    {
        $this->actingAs($this->admin(), 'web');
        Siswa::factory()->create(['nama' => 'Zaenal Abidin', 'nisn' => '0011111111']);
        Siswa::factory()->create(['nama' => 'Budi Hartono', 'nisn' => '0022222222']);
        Siswa::factory()->status('lulus')->create(['nama' => 'Citra Lestari', 'nisn' => '0033333333']);

        $this->get('/staf/master/siswa?q=Zaenal')->assertSee('Zaenal Abidin')->assertDontSee('Budi Hartono');
        $this->get('/staf/master/siswa?status=lulus')->assertSee('Citra Lestari')->assertDontSee('Budi Hartono');
    }

    public function test_beranda_admin_menampilkan_langkah_persiapan(): void
    {
        $this->actingAs($this->admin(), 'web')->get('/staf')->assertOk()->assertSee('Persiapan data')->assertSee('Tahun Ajaran');
    }

    public function test_perubahan_master_dicatat_di_audit_log(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web')->post('/staf/master/ruang', ['nama' => 'Lab 1']);

        $this->assertDatabaseHas('audit_log', ['aksi' => 'master.ruang.tambah', 'pelaku_id' => $admin->id, 'pelaku_tipe' => 'staf']);
    }
}
