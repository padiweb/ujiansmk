<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\JadwalSoal;
use App\Models\JadwalUjian;
use App\Models\KegiatanUjian;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Periode;
use App\Models\SesiUjian;
use App\Models\Siswa;
use App\Models\Soal;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\LayananJadwal;
use App\Services\LayananSoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JadwalUjianTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Mapel $mtk;

    private TahunAjaran $tahun;

    private Periode $periode;

    private Kelas $kelasA;

    private Kelas $kelasB;

    private BankSoal $bank;

    private KegiatanUjian $kegiatan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->mtk = Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika', 'kelompok' => 'A', 'kkm' => 75]);
        $this->tahun = TahunAjaran::create(['nama' => '2025/2026', 'aktif' => true]);
        $this->periode = Periode::create(['tahun_ajaran_id' => $this->tahun->id, 'semester' => 'ganjil', 'tipe' => 'reguler', 'aktif' => true]);
        $this->kelasA = Kelas::create(['tahun_ajaran_id' => $this->tahun->id, 'tingkat' => 'X', 'nama' => 'X A']);
        $this->kelasB = Kelas::create(['tahun_ajaran_id' => $this->tahun->id, 'tingkat' => 'XI', 'nama' => 'XI A']);
        $this->bank = BankSoal::create(['mapel_id' => $this->mtk->id, 'tingkat' => 'X', 'nama' => 'Bank MTK X', 'jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $this->admin->id]);
        $this->kegiatan = KegiatanUjian::create(['periode_id' => $this->periode->id, 'nama' => 'UTS', 'aktif' => true]);
    }

    private function soal(string $tipe = 'pg', string $status = 'siap', ?BankSoal $bank = null): Soal
    {
        $bank ??= $this->bank;
        $s = $bank->soal()->create(['tipe' => $tipe, 'pertanyaan' => "Soal {$tipe}", 'bobot' => 1, 'status' => $status, 'sumber' => 'manual', 'urutan' => $bank->soal()->count() + 1]);
        if ($tipe === 'pg') {
            app(LayananSoal::class)->setOpsi($s, ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'], 'A');
        }

        return $s;
    }

    private function siswaDiKelas(Kelas $kelas, int $jumlah = 1, string $status = 'aktif'): array
    {
        return Siswa::factory()->count($jumlah)->state(['status' => $status])->create()
            ->each(fn (Siswa $s) => $s->kelas()->attach($kelas->id, ['status' => 'aktif']))->all();
    }

    private function data(array $timpa = []): array
    {
        return array_replace([
            'kegiatan_id' => $this->kegiatan->id, 'mapel_id' => $this->mtk->id, 'durasi_menit' => 90,
            'mulai_pada' => now()->addDay()->format('Y-m-d\TH:i'), 'tutup_pada' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'kelas_ids' => [$this->kelasA->id], 'bank_ids' => [$this->bank->id],
            'acak_soal' => '1', 'acak_opsi' => '1', 'batas_pelanggaran' => 3, 'bobot_pg' => 100, 'tampilkan_hasil' => 'langsung',
        ], $timpa);
    }

    private function buat(array $timpa = []): JadwalUjian
    {
        $this->actingAs($this->admin, 'web')->post('/staf/jadwal-ujian', $this->data($timpa))->assertSessionHasNoErrors();

        return JadwalUjian::latest('id')->first();
    }

    public function test_peserta_dipilih_per_tingkat_dan_semua_kelas_tingkat_itu_ikut(): void
    {
        $kelasA2 = Kelas::create(['tahun_ajaran_id' => $this->tahun->id, 'tingkat' => 'X', 'nama' => 'X B']);
        $lama = TahunAjaran::create(['nama' => '2024/2025', 'aktif' => false]);
        $kelasLama = Kelas::create(['tahun_ajaran_id' => $lama->id, 'tingkat' => 'X', 'nama' => 'X Lama']);

        $jadwal = $this->buat(['kelas_ids' => null, 'tingkat' => 'X']);

        $this->assertEqualsCanonicalizing([$this->kelasA->id, $kelasA2->id], $jadwal->kelas()->pluck('kelas.id')->all());
        $this->assertNotContains($kelasLama->id, $jadwal->kelas()->pluck('kelas.id')->all());
    }

    public function test_tingkat_tanpa_kelas_pada_tahun_kegiatan_ditolak(): void
    {
        $this->actingAs($this->admin, 'web')->post('/staf/jadwal-ujian', $this->data(['kelas_ids' => null, 'tingkat' => 'XII']))
            ->assertSessionHasErrors('kelas_ids');
    }

    public function test_bank_otomatis_hanya_bank_pengampu_yang_siap_dan_sesuai_kegiatan(): void
    {
        $guru = User::factory()->create(['nama' => 'Bu Mat']);
        $lain = User::factory()->create(['nama' => 'Pak Lain']);
        \App\Models\Pengampu::create(['guru_id' => $guru->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->kelasA->id]);
        $mk = fn ($pemilik, $nama, $kegiatan) => BankSoal::create(['mapel_id' => $this->mtk->id, 'tingkat' => 'X', 'nama' => $nama, 'kegiatan_id' => $kegiatan?->id, 'jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $pemilik->id]);

        $umum = $mk($guru, 'Umum Bu Mat', null);
        $khusus = $mk($guru, 'Khusus UTS Bu Mat', $this->kegiatan);
        $bukanPengampu = $mk($lain, 'Bank Pak Lain', $this->kegiatan);
        $kosong = $mk($guru, 'Kosong', $this->kegiatan);
        $kegiatanLain = KegiatanUjian::create(['periode_id' => $this->periode->id, 'nama' => 'UAS', 'aktif' => true]);
        $untukLain = $mk($guru, 'Untuk UAS', $kegiatanLain);
        foreach ([$umum, $khusus, $bukanPengampu, $untukLain] as $b) {
            $this->soal('pg', 'siap', $b);
        }
        $this->soal('pg', 'draf', $kosong);

        $nama = collect($this->actingAs($this->admin, 'web')->getJson(route('staf.jadwal.bank-otomatis', [
            'kegiatan_id' => $this->kegiatan->id, 'mapel_id' => $this->mtk->id, 'tingkat' => 'X',
        ]))->assertOk()->json())->pluck('nama')->all();

        // Bank umum Bu Mat tersingkir karena ia punya bank khusus kegiatan ini; bank admin lama (tanpa soal) tidak ikut.
        $this->assertSame(['Khusus UTS Bu Mat'], $nama);
        $this->assertNotContains($bukanPengampu->nama, $nama);
    }

    public function test_form_jadwal_memakai_dropdown_tingkat(): void
    {
        $this->actingAs($this->admin, 'web')->get('/staf/jadwal-ujian/tambah')->assertOk()
            ->assertSee('name="tingkat"', false)->assertSee('Kelas XII')->assertSee('Bank soal yang dipakai');
    }

    // ── Akses ───────────────────────────────────────────────────────────────

    public function test_hanya_admin_yang_mengelola_jadwal(): void
    {
        $this->get('/staf/jadwal-ujian')->assertRedirect(route('staf.masuk'));
        foreach ([User::factory()->create(), User::factory()->pengawas()->create()] as $user) {
            $this->actingAs($user, 'web')->get('/staf/jadwal-ujian')->assertForbidden();
            $this->post('/staf/jadwal-ujian', $this->data())->assertForbidden();
        }
        $this->assertSame(0, JadwalUjian::count());
    }

    public function test_halaman_jadwal_tampil(): void
    {
        $jadwal = $this->buat();

        $this->get('/staf/jadwal-ujian')->assertOk()->assertSee('UTS – Matematika');
        $this->get('/staf/jadwal-ujian/tambah')->assertOk()->assertSee('Kelas peserta');
        $this->get("/staf/jadwal-ujian/{$jadwal->id}")->assertOk()->assertSee('Aktifkan jadwal');
        $this->get("/staf/jadwal-ujian/{$jadwal->id}/ubah")->assertOk();
    }

    public function test_daftar_jadwal_menyaring_semester_aktif_dan_menampilkan_token(): void
    {
        $this->soal('pg');
        $this->siswaDiKelas($this->kelasA);
        $jadwal = $this->buat();
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan");

        $this->get('/staf/jadwal-ujian')->assertOk()->assertSee('UTS – Matematika')->assertSee($jadwal->fresh()->token)->assertSee('Token');

        $lain = Periode::create(['tahun_ajaran_id' => $this->tahun->id, 'semester' => 'genap', 'tipe' => 'reguler', 'aktif' => false]);
        $jadwal->update(['periode_id' => $lain->id]);
        $this->get('/staf/jadwal-ujian')->assertOk()->assertDontSee('UTS – Matematika'); // bukan semester aktif
        $this->get("/staf/jadwal-ujian?periode={$lain->id}")->assertOk()->assertSee('UTS – Matematika');
        $this->get('/staf/jadwal-ujian?periode=semua')->assertOk()->assertSee('UTS – Matematika');
    }

    // ── Pembuatan dan validasi ──────────────────────────────────────────────

    public function test_buat_jadwal_draf_dengan_relasinya(): void
    {
        $jadwal = $this->buat([
            'kelas_ids' => [$this->kelasA->id],
            'jumlah_pg' => [$this->bank->id => '5'], 'jumlah_esai' => [$this->bank->id => ''],
        ]);

        $this->assertSame('draf', $jadwal->status);
        $this->assertNull($jadwal->token);
        $this->assertSame([$this->kelasA->id], $jadwal->kelas()->pluck('kelas.id')->all());
        $bank = $jadwal->bank()->first();
        $this->assertSame(5, $bank->jumlah_pg);
        $this->assertNull($bank->jumlah_esai);
        $this->assertSame($this->admin->id, $jadwal->dibuat_oleh);
        $this->assertSame(0, $jadwal->sesi()->count());
    }

    public function test_validasi_dasar(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->post('/staf/jadwal-ujian', [])->assertSessionHasErrors(['kegiatan_id', 'mapel_id', 'durasi_menit', 'mulai_pada', 'tutup_pada', 'kelas_ids', 'bank_ids']);
        $this->post('/staf/jadwal-ujian', $this->data(['durasi_menit' => 4]))->assertSessionHasErrors('durasi_menit');
        $this->post('/staf/jadwal-ujian', $this->data(['durasi_menit' => 481]))->assertSessionHasErrors('durasi_menit');
        $this->post('/staf/jadwal-ujian', $this->data(['durasi_menit' => 60, 'min_kumpul_menit' => 60]))->assertSessionHasErrors('min_kumpul_menit');
        $this->post('/staf/jadwal-ujian', $this->data(['durasi_menit' => 60, 'min_kumpul_menit' => -1]))->assertSessionHasErrors('min_kumpul_menit');
        $this->post('/staf/jadwal-ujian', $this->data(['batas_pelanggaran' => 0]))->assertSessionHasErrors('batas_pelanggaran');
        $this->post('/staf/jadwal-ujian', $this->data(['bobot_pg' => 101]))->assertSessionHasErrors('bobot_pg');
        $this->post('/staf/jadwal-ujian', $this->data(['tampilkan_hasil' => 'rahasia']))->assertSessionHasErrors('tampilkan_hasil');
        $this->post('/staf/jadwal-ujian', $this->data(['tutup_pada' => now()->format('Y-m-d\TH:i'), 'mulai_pada' => now()->addDay()->format('Y-m-d\TH:i')]))
            ->assertSessionHasErrors('tutup_pada');
        $this->assertSame(0, JadwalUjian::count());
    }

    public function test_waktu_minimal_mengumpulkan_tersimpan_dan_tampil(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->post('/staf/jadwal-ujian', $this->data(['durasi_menit' => 60, 'min_kumpul_menit' => 30]))->assertSessionHasNoErrors();
        $j = JadwalUjian::latest('id')->first();
        $this->assertSame(30, $j->min_kumpul_menit);
        $this->get("/staf/jadwal-ujian/{$j->id}")->assertOk()->assertSee('setelah 30 menit');
        $this->get("/staf/jadwal-ujian/{$j->id}/ubah")->assertOk()->assertSee('Waktu minimal sebelum bisa mengumpulkan');
    }

    public function test_kelas_harus_dari_tahun_ajaran_semester_yang_dipilih(): void
    {
        $tahunLain = TahunAjaran::create(['nama' => '2024/2025', 'aktif' => false]);
        $kelasLama = Kelas::create(['tahun_ajaran_id' => $tahunLain->id, 'tingkat' => 'X', 'nama' => 'X Lama']);

        $this->actingAs($this->admin, 'web')->post('/staf/jadwal-ujian', $this->data(['kelas_ids' => [$kelasLama->id]]))
            ->assertSessionHasErrors('kelas_ids');
    }

    public function test_bank_harus_untuk_mapel_dan_tingkat_yang_sesuai(): void
    {
        $ipa = Mapel::create(['kode' => 'IPA', 'nama' => 'IPA', 'kelompok' => 'A', 'kkm' => 75]);
        $bankIpa = BankSoal::create(['mapel_id' => $ipa->id, 'tingkat' => 'X', 'nama' => 'Bank IPA', 'jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $this->admin->id]);
        $bankXII = BankSoal::create(['mapel_id' => $this->mtk->id, 'tingkat' => 'XII', 'nama' => 'Bank MTK XII', 'jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $this->admin->id]);
        $this->actingAs($this->admin, 'web');

        $this->post('/staf/jadwal-ujian', $this->data(['bank_ids' => [$bankIpa->id]]))->assertSessionHasErrors('bank_ids');
        $this->post('/staf/jadwal-ujian', $this->data(['bank_ids' => [$bankXII->id]]))->assertSessionHasErrors('bank_ids');
        $this->assertSame(0, JadwalUjian::count());
    }

    public function test_bank_terhapus_ditolak(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->bank->delete();
        $this->post('/staf/jadwal-ujian', $this->data())->assertSessionHasErrors('bank_ids.0');
    }

    public function test_kelas_tingkat_berbeda_untuk_mapel_sama_dalam_satu_jadwal(): void
    {
        $bankXI = BankSoal::create(['mapel_id' => $this->mtk->id, 'tingkat' => 'XI', 'nama' => 'Bank MTK XI', 'jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $this->admin->id]);

        $jadwal = $this->buat(['kelas_ids' => [$this->kelasA->id, $this->kelasB->id], 'bank_ids' => [$this->bank->id, $bankXI->id]]);

        $this->assertSame(2, $jadwal->kelas()->count());
        $this->assertSame(2, $jadwal->bank()->count());
    }

    public function test_ubah_jadwal_draf_mengganti_relasi(): void
    {
        $jadwal = $this->buat();

        $lain = KegiatanUjian::create(['periode_id' => $this->periode->id, 'nama' => 'UAS', 'aktif' => true]);
        $this->put("/staf/jadwal-ujian/{$jadwal->id}", $this->data(['kegiatan_id' => $lain->id, 'kelas_ids' => [$this->kelasA->id, $this->kelasB->id], 'durasi_menit' => 60]))
            ->assertSessionHasNoErrors();

        $jadwal->refresh();
        $this->assertSame('UAS – Matematika', $jadwal->nama);
        $this->assertSame($lain->id, $jadwal->kegiatan_id);
        $this->assertSame(60, $jadwal->durasi_menit);
        $this->assertSame(2, $jadwal->kelas()->count());
    }

    public function test_nama_jadwal_diturunkan_dari_kegiatan_dan_mapel_bukan_diketik(): void
    {
        $jadwal = $this->buat(['nama' => 'Nama ketikan diabaikan', 'periode_id' => 999]);

        $this->assertSame('UTS – Matematika', $jadwal->nama);
        $this->assertSame($this->periode->id, $jadwal->periode_id);
        $this->assertSame($this->kegiatan->id, $jadwal->kegiatan_id);
    }

    public function test_kegiatan_tidak_aktif_tidak_bisa_dipilih_untuk_jadwal_baru(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->kegiatan->update(['aktif' => false]);

        $this->post('/staf/jadwal-ujian', $this->data())->assertSessionHasErrors('kegiatan_id');
        $this->get('/staf/jadwal-ujian/tambah')->assertOk()->assertDontSee('UTS —', false);
    }

    // ── Aktivasi ────────────────────────────────────────────────────────────

    public function test_aktifkan_membekukan_soal_membuat_token_dan_sesi_peserta(): void
    {
        $this->soal('pg');
        $this->soal('pg');
        $this->soal('esai');
        $this->soal('pg', 'draf'); // draf tidak ikut
        $aktif = $this->siswaDiKelas($this->kelasA, 3);
        $this->siswaDiKelas($this->kelasA, 1, 'lulus'); // bukan peserta
        $this->siswaDiKelas($this->kelasB, 2);            // kelas lain
        $jadwal = $this->buat(['bobot_pg' => 70]);

        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan")->assertSessionHas('berhasil');

        $jadwal->refresh();
        $this->assertSame('aktif', $jadwal->status);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{5}$/', $jadwal->token);
        $this->assertNotNull($jadwal->diaktifkan_pada);

        // PG dulu, lalu esai; soal draf tidak ikut.
        $beku = $jadwal->soalBeku()->with('soal')->get();
        $this->assertSame(['pg', 'pg', 'esai'], $beku->pluck('soal.tipe')->all());
        $this->assertSame([1, 2, 3], $beku->pluck('urutan')->all());
        $this->assertFalse($beku->pluck('soal.status')->contains('draf'));

        $sesi = $jadwal->sesi()->get();
        $this->assertCount(3, $sesi);
        $this->assertEqualsCanonicalizing(collect($aktif)->pluck('id')->all(), $sesi->pluck('siswa_id')->all());
        $this->assertSame(['belum'], $sesi->pluck('status')->unique()->all());
    }

    public function test_bobot_soal_dibekukan_walau_soal_diubah_kemudian(): void
    {
        $s = $this->soal('pg');
        $s->update(['bobot' => 2]);
        $this->siswaDiKelas($this->kelasA);
        $jadwal = $this->buat();
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan");

        $s->update(['bobot' => 9, 'pertanyaan' => 'diubah']);

        $this->assertEquals(2, JadwalSoal::where('jadwal_id', $jadwal->id)->first()->bobot);
    }

    public function test_jumlah_soal_diambil_acak_sesuai_permintaan(): void
    {
        foreach (range(1, 6) as $i) {
            $this->soal('pg');
        }
        $this->soal('esai');
        $this->siswaDiKelas($this->kelasA);
        $jadwal = $this->buat(['jumlah_pg' => [$this->bank->id => '4'], 'jumlah_esai' => [$this->bank->id => '1']]);

        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan")->assertSessionHas('berhasil');

        $this->assertSame(5, $jadwal->soalBeku()->count());
    }

    public function test_aktifkan_gagal_bila_soal_siap_kurang_dari_permintaan(): void
    {
        $this->soal('pg');
        $this->soal('pg');
        $this->siswaDiKelas($this->kelasA);
        $jadwal = $this->buat(['jumlah_pg' => [$this->bank->id => '5']]);

        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan")->assertSessionHas('gagal');

        $this->assertStringContainsString('hanya punya 2 soal PG', session('gagal'));
        $this->assertSame('draf', $jadwal->fresh()->status);
        $this->assertSame(0, JadwalSoal::count());
        $this->assertSame(0, SesiUjian::count());
    }

    public function test_aktifkan_gagal_tanpa_soal_siap_tanpa_peserta_atau_waktu_tutup_lewat(): void
    {
        $jadwal = $this->buat();
        $this->actingAs($this->admin, 'web');

        // Tanpa soal siap.
        $this->soal('pg', 'draf');
        $this->siswaDiKelas($this->kelasA);
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan")->assertSessionHas('gagal');
        $this->assertStringContainsString('belum punya soal berstatus siap', session('gagal'));

        // Tanpa peserta.
        $this->soal('pg');
        Siswa::query()->update(['status' => 'keluar']);
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan")->assertSessionHas('gagal');
        $this->assertStringContainsString('Tidak ada siswa aktif', session('gagal'));

        // Waktu tutup lewat.
        Siswa::query()->update(['status' => 'aktif']);
        $jadwal->update(['mulai_pada' => now()->subDays(2), 'tutup_pada' => now()->subDay()]);
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan")->assertSessionHas('gagal');
        $this->assertStringContainsString('Waktu tutup sudah lewat', session('gagal'));

        $this->assertSame('draf', $jadwal->fresh()->status);
    }

    public function test_bobot_esai_tanpa_soal_esai_ditolak_saat_aktivasi(): void
    {
        $this->soal('pg');
        $this->siswaDiKelas($this->kelasA);
        $jadwal = $this->buat(['bobot_pg' => 60]);

        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan")->assertSessionHas('gagal');

        $this->assertStringContainsString('tidak ada soal esai', session('gagal'));
        $this->assertSame('draf', $jadwal->fresh()->status);
    }

    public function test_hanya_draf_yang_bisa_diaktifkan_dan_aktivasi_ganda_tidak_menggandakan_sesi(): void
    {
        $this->soal('pg');
        $this->siswaDiKelas($this->kelasA, 2);
        $jadwal = $this->buat();
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan")->assertSessionHas('berhasil');

        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan")->assertSessionHas('gagal');

        $this->assertSame(2, SesiUjian::count());
        $this->assertSame(1, JadwalSoal::count());
    }

    public function test_token_unik_antar_jadwal(): void
    {
        $this->soal('pg');
        $this->siswaDiKelas($this->kelasA);
        $tokens = [];
        foreach (range(1, 5) as $i) {
            $jadwal = $this->buat();
            $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan");
            $tokens[] = $jadwal->fresh()->token;
        }

        $this->assertCount(5, array_unique($tokens));
    }

    public function test_peserta_saat_aktivasi_dipotret_dan_tidak_otomatis_bertambah(): void
    {
        $this->soal('pg');
        $this->siswaDiKelas($this->kelasA, 2);
        $jadwal = $this->buat();
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan");

        $this->siswaDiKelas($this->kelasA, 1); // masuk kelas setelah aktivasi

        $this->assertSame(2, $jadwal->sesi()->count());
        $this->assertSame(3, app(LayananJadwal::class)->pesertaBerhak($jadwal)->count());
    }

    // ── Setelah aktif ───────────────────────────────────────────────────────

    public function test_regenerasi_token_mengganti_token_dan_hanya_untuk_jadwal_aktif(): void
    {
        $this->soal('pg');
        $this->siswaDiKelas($this->kelasA);
        $jadwal = $this->buat();
        $this->actingAs($this->admin, 'web')->post("/staf/jadwal-ujian/{$jadwal->id}/token")->assertSessionHas('gagal');

        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan");
        $lama = $jadwal->fresh()->token;
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/token")->assertSessionHas('berhasil');

        $this->assertNotSame($lama, $jadwal->fresh()->token);
    }

    public function test_ubah_jadwal_aktif_hanya_sebagian_isian_dan_isian_lain_diabaikan(): void
    {
        $this->soal('pg');
        $this->siswaDiKelas($this->kelasA);
        $jadwal = $this->buat();
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan");
        $tutupBaru = now()->addDays(5)->format('Y-m-d\TH:i');

        $this->put("/staf/jadwal-ujian/{$jadwal->id}", [
            'tutup_pada' => $tutupBaru, 'batas_pelanggaran' => 5, 'tampilkan_hasil' => 'ditahan',
            // Isian di bawah harus diabaikan karena soal dan peserta sudah dibekukan.
            'nama' => 'Dibajak', 'durasi_menit' => 1, 'kelas_ids' => [$this->kelasB->id], 'bank_ids' => [999],
        ])->assertSessionHasNoErrors();

        $jadwal->refresh();
        $this->assertSame('UTS – Matematika', $jadwal->nama);
        $this->assertSame(90, $jadwal->durasi_menit);
        $this->assertSame([$this->kelasA->id], $jadwal->kelas()->pluck('kelas.id')->all());
        $this->assertSame(5, $jadwal->batas_pelanggaran);
        $this->assertSame('ditahan', $jadwal->tampilkan_hasil);
        $this->assertSame($tutupBaru, $jadwal->tutup_pada->format('Y-m-d\TH:i'));
    }

    public function test_waktu_tutup_jadwal_aktif_tidak_boleh_di_masa_lalu(): void
    {
        $this->soal('pg');
        $this->siswaDiKelas($this->kelasA);
        $jadwal = $this->buat();
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan");

        $this->put("/staf/jadwal-ujian/{$jadwal->id}", [
            'tutup_pada' => now()->subHour()->format('Y-m-d\TH:i'), 'batas_pelanggaran' => 3, 'tampilkan_hasil' => 'langsung',
        ])->assertSessionHasErrors('tutup_pada');
    }

    public function test_batalkan_aktivasi_selama_belum_ada_yang_mulai(): void
    {
        $this->soal('pg');
        $this->siswaDiKelas($this->kelasA, 2);
        $jadwal = $this->buat();
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan");

        $this->post("/staf/jadwal-ujian/{$jadwal->id}/batalkan")->assertSessionHas('berhasil');

        $jadwal->refresh();
        $this->assertSame('draf', $jadwal->status);
        $this->assertNull($jadwal->token);
        $this->assertSame(0, $jadwal->sesi()->count());
        $this->assertSame(0, $jadwal->soalBeku()->count());

        // Setelah dikembalikan ke draf, soal bisa diaktifkan ulang.
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan")->assertSessionHas('berhasil');
    }

    public function test_batalkan_aktivasi_ditolak_bila_ada_yang_sudah_mulai(): void
    {
        $this->soal('pg');
        $this->siswaDiKelas($this->kelasA);
        $jadwal = $this->buat();
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan");
        $jadwal->sesi()->first()->update(['status' => 'berlangsung']);

        $this->post("/staf/jadwal-ujian/{$jadwal->id}/batalkan")->assertSessionHas('gagal');

        $this->assertSame('aktif', $jadwal->fresh()->status);
        $this->assertSame(1, $jadwal->sesi()->count());
    }

    public function test_selesaikan_jadwal_memaksa_sesi_berjalan_selesai_dan_menilainya(): void
    {
        $this->soal('pg');
        $this->siswaDiKelas($this->kelasA, 3);
        $jadwal = $this->buat();
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan");
        [$berjalan, $terblokir, $belum] = $jadwal->sesi()->get()->all();
        $berjalan->update(['status' => 'berlangsung', 'mulai_pada' => now(), 'batas_selesai' => now()->addHour()]);
        $terblokir->update(['status' => 'diblokir', 'mulai_pada' => now(), 'batas_selesai' => now()->addHour()]);

        $this->post("/staf/jadwal-ujian/{$jadwal->id}/selesaikan")->assertSessionHas('berhasil');

        $this->assertSame('selesai', $jadwal->fresh()->status);
        foreach ([$berjalan, $terblokir] as $s) {
            $s->refresh();
            $this->assertSame('selesai', $s->status);
            $this->assertSame('dipaksa', $s->alasan_selesai);
            $this->assertNotNull($s->hasil);
        }
        $this->assertSame('belum', $belum->fresh()->status);
        $this->assertNull($belum->fresh()->hasil);
    }

    public function test_selesaikan_jadwal_yang_tidak_aktif_ditolak(): void
    {
        $jadwal = $this->buat();

        $this->post("/staf/jadwal-ujian/{$jadwal->id}/selesaikan")->assertSessionHas('gagal');

        $this->assertSame('draf', $jadwal->fresh()->status);
    }

    public function test_jadwal_selesai_tidak_bisa_diubah_dan_jadwal_aktif_tidak_bisa_dihapus(): void
    {
        $this->soal('pg');
        $this->siswaDiKelas($this->kelasA);
        $jadwal = $this->buat();
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan");

        $this->delete("/staf/jadwal-ujian/{$jadwal->id}")->assertSessionHas('gagal');
        $this->assertNotNull(JadwalUjian::find($jadwal->id));

        $jadwal->update(['status' => 'selesai']);
        $this->get("/staf/jadwal-ujian/{$jadwal->id}/ubah")->assertForbidden();
        $this->put("/staf/jadwal-ujian/{$jadwal->id}", $this->data())->assertForbidden();
    }

    public function test_hapus_jadwal_draf(): void
    {
        $jadwal = $this->buat();

        $this->delete("/staf/jadwal-ujian/{$jadwal->id}")->assertRedirect(route('staf.jadwal.index'));

        $this->assertNull(JadwalUjian::find($jadwal->id));
        $this->assertSame(0, $jadwal->bank()->count());
    }

    public function test_jendela_waktu_dihitung_dari_jam_server(): void
    {
        $jadwal = $this->buat();

        $this->assertSame('belum_dibuka', $jadwal->jendela());

        $jadwal->update(['mulai_pada' => now()->subHour(), 'tutup_pada' => now()->addHour()]);
        $this->assertSame('dibuka', $jadwal->fresh()->jendela());

        $jadwal->update(['mulai_pada' => now()->subDays(2), 'tutup_pada' => now()->subDay()]);
        $this->assertSame('ditutup', $jadwal->fresh()->jendela());
    }

    public function test_perubahan_jadwal_dicatat_di_audit_log(): void
    {
        $this->soal('pg');
        $this->siswaDiKelas($this->kelasA);
        $jadwal = $this->buat();
        $this->post("/staf/jadwal-ujian/{$jadwal->id}/aktifkan");

        $this->assertDatabaseHas('audit_log', ['aksi' => 'jadwal.tambah', 'pelaku_id' => $this->admin->id]);
        $this->assertDatabaseHas('audit_log', ['aksi' => 'jadwal.aktifkan', 'pelaku_id' => $this->admin->id]);
    }
}
