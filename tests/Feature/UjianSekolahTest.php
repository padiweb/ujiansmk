<?php

namespace Tests\Feature;

use App\Models\Jurusan;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\NilaiRapor;
use App\Models\NilaiUs;
use App\Models\Pengampu;
use App\Models\Periode;
use App\Models\PengaturanSekolah;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\LayananTranskrip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class UjianSekolahTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private Kelas $xii;

    private User $guru;

    /** @var list<Siswa> */
    private array $lulusan;

    private Mapel $produktif;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian();
        $jurusan = Jurusan::create(['kode' => 'TKJ', 'nama' => 'Teknik Komputer dan Jaringan', 'program_keahlian' => 'Teknologi Informasi']);
        $this->xii = Kelas::create(['tahun_ajaran_id' => $this->kelas->tahun_ajaran_id, 'tingkat' => 'XII', 'nama' => 'XII TKJ', 'jurusan_id' => $jurusan->id]);
        $this->lulusan = Siswa::factory()->count(2)->create(['tempat_lahir' => 'Tanon', 'tgl_lahir' => '2008-05-17', 'no_peserta_us' => '26-001'])
            ->each(fn (Siswa $s) => $s->kelas()->attach($this->xii->id, ['status' => 'aktif']))->all();
        $this->produktif = Mapel::create(['kode' => 'ASJ', 'nama' => 'Administrasi Sistem Jaringan', 'nama_skl' => 'Administrasi Sistem Jaringan (ASJ)', 'kelompok' => 'C3', 'kkm' => 75]);
        $this->guru = User::factory()->create();
        Pengampu::create(['guru_id' => $this->guru->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->xii->id]);
        PengaturanSekolah::ambil()->update(['nama' => 'SMK Alhikmah Tanon', 'kota' => 'Sragen', 'kepala_sekolah' => 'Drs. Contoh', 'nip_kepala_sekolah' => '1970']);
    }

    private function nilai(Siswa $s, Mapel $m, string $tahun, string $semester, int $na): void
    {
        $t = TahunAjaran::firstOrCreate(['nama' => $tahun]);
        $p = Periode::firstOrCreate(['tahun_ajaran_id' => $t->id, 'semester' => $semester, 'tipe' => 'reguler'], ['aktif' => false, 'rapor_di_sini' => false]);
        NilaiRapor::create(['siswa_id' => $s->id, 'mapel_id' => $m->id, 'periode_id' => $p->id, 'kelas_id' => $this->xii->id, 'np' => $na, 'nk' => $na, 'na' => $na, 'predikat' => 'B', 'status' => 'final']);
    }

    private function urlUs(Mapel $m): string
    {
        return "/staf/ujian-sekolah/{$m->id}/{$this->xii->id}";
    }

    // ── Input US ────────────────────────────────────────────────────────────

    public function test_guru_hanya_melihat_dan_mengisi_mapel_kelas_xii_yang_diampunya(): void
    {
        $this->actingAs($this->guru, 'web')->get('/staf/ujian-sekolah')->assertOk()->assertSee('Matematika')->assertSee('XII TKJ');
        $this->get($this->urlUs($this->mtk))->assertOk()->assertSee($this->lulusan[0]->nama);
        $this->get($this->urlUs($this->produktif))->assertNotFound();

        $lain = User::factory()->create();
        $this->actingAs($lain, 'web')->get($this->urlUs($this->mtk))->assertNotFound();
        $this->actingAs(User::factory()->pengawas()->create(), 'web')->get('/staf/ujian-sekolah')->assertForbidden();
    }

    public function test_simpan_nilai_us_manual_keterangan_dan_penanda_xii2(): void
    {
        $this->actingAs($this->guru, 'web');
        $a = $this->lulusan[0]->id;
        $b = $this->lulusan[1]->id;

        $this->post($this->urlUs($this->mtk), ['masuk_xii2' => '1', 'nilai' => [$a => ['nilai' => '88,5', 'keterangan' => 'Remidi lulus'], $b => ['nilai' => '']]])
            ->assertSessionHas('berhasil');

        $n = NilaiUs::where('siswa_id', $a)->first();
        $this->assertSame([88.5, 'Remidi lulus', true], [(float) $n->nilai, $n->keterangan, $n->masuk_xii2]);
        $this->assertNull(NilaiUs::where('siswa_id', $b)->first()); // kosong dan tanpa CBT: tidak dibuat

        $this->get($this->urlUs($this->mtk))->assertSee('88.5')->assertSee('Lulus');

        $this->post($this->urlUs($this->mtk), ['nilai' => [$a => ['nilai' => '120'], $b => ['nilai' => 'abc']]])->assertSessionHas('catatan');
        $this->assertSame(88.5, (float) NilaiUs::where('siswa_id', $a)->first()->nilai);
    }

    // ── Transkrip ───────────────────────────────────────────────────────────

    public function test_transkrip_memetakan_tiga_tahun_terakhir_ke_x_xi_xii_dan_menghitung_rata(): void
    {
        $s = $this->lulusan[0];
        $this->nilai($s, $this->mtk, '2023/2024', 'ganjil', 80);
        $this->nilai($s, $this->mtk, '2023/2024', 'genap', 82);
        $this->nilai($s, $this->mtk, '2024/2025', 'ganjil', 84);
        $this->nilai($s, $this->mtk, '2025/2026', 'ganjil', 90);
        $this->nilai($s, $this->produktif, '2025/2026', 'ganjil', 70);
        NilaiUs::create(['siswa_id' => $s->id, 'mapel_id' => $this->mtk->id, 'nilai' => 96, 'masuk_xii2' => true]);
        NilaiUs::create(['siswa_id' => $s->id, 'mapel_id' => $this->produktif->id, 'nilai' => 60, 'masuk_xii2' => false]);

        $t = app(LayananTranskrip::class)->susun($s);

        $mtk = $t['kelompok']['UMUM'][0];
        $this->assertSame([80.0, 82.0, 84.0, null, 90.0, 96.0], $mtk['slot']); // XII-2 kosong diisi nilai US karena ditandai
        $this->assertSame(86.4, $mtk['rata']);
        $asj = $t['kelompok']['KEJURUAN'][0];
        $this->assertSame('Administrasi Sistem Jaringan (ASJ)', $asj['nama']);
        $this->assertSame([null, null, null, null, 70.0, null], $asj['slot']); // tidak ditandai: US tidak masuk slot
        $this->assertSame(70.0, $asj['rata']);
        $this->assertSame(60.0, $asj['us']);
        $this->assertSame(78.2, $t['rata_semua']);
    }

    public function test_transkrip_dua_tahun_dipetakan_ke_xi_dan_xii_dan_mapel_hanya_us_memakai_nilai_us(): void
    {
        $s = $this->lulusan[1];
        $this->nilai($s, $this->mtk, '2024/2025', 'ganjil', 80);
        $this->nilai($s, $this->mtk, '2025/2026', 'genap', 90);
        NilaiUs::create(['siswa_id' => $s->id, 'mapel_id' => $this->produktif->id, 'nilai_cbt' => 77]);

        $t = app(LayananTranskrip::class)->susun($s);

        $this->assertSame([null, null, 80.0, null, null, 90.0], $t['kelompok']['UMUM'][0]['slot']);
        $this->assertSame(77.0, $t['kelompok']['KEJURUAN'][0]['rata']); // hanya US (dari CBT): rata = nilai US
    }

    // ── Cetak ───────────────────────────────────────────────────────────────

    public function test_admin_mencetak_transkrip_dan_skl_dan_guru_ditolak(): void
    {
        $s = $this->lulusan[0];
        $this->nilai($s, $this->mtk, '2025/2026', 'ganjil', 90);
        NilaiUs::create(['siswa_id' => $s->id, 'mapel_id' => $this->mtk->id, 'nilai' => 95]);

        $this->actingAs($this->admin, 'web');
        $this->get('/staf/kelulusan/transkrip')->assertOk()->assertSee('Transkrip US')->assertSee($s->nama);
        $this->get('/staf/kelulusan/skl')->assertOk()->assertSee('Cetak SKL');

        $this->get("/staf/kelulusan/transkrip/cetak?kelas={$this->xii->id}&siswa={$s->id}&no_surat=421/01&tgl=2026-05-20")->assertOk()
            ->assertSee('TRANSKRIP NILAI')->assertSee($s->nama)->assertSee('421/01')->assertSee('Teknik Komputer dan Jaringan')
            ->assertSee('17 Mei 2008')->assertSee('26-001')->assertSee('90,00')->assertSee('Sragen, 20 Mei 2026')->assertDontSee($this->lulusan[1]->nama);

        $this->get("/staf/kelulusan/skl/cetak?kelas={$this->xii->id}&no_ijazah=DN-77&tgl=2026-05-20&tgl_lulus=2026-05-15")->assertOk()
            ->assertSee('SURAT KETERANGAN LULUS')->assertSee(strtoupper($s->nama))->assertSee('DN-77')->assertSee('15 Mei 2026')
            ->assertSee('Teknologi Informasi')->assertSee('Rata - rata')->assertSee(strtoupper($this->lulusan[1]->nama));

        $this->get("/staf/kelulusan/skl/cetak?kelas={$this->xii->id}&format=word")->assertOk()->assertHeader('Content-Disposition');

        $this->actingAs($this->guru, 'web')->get('/staf/kelulusan/transkrip')->assertForbidden();
        $this->get("/staf/kelulusan/skl/cetak?kelas={$this->xii->id}")->assertForbidden();
    }

    public function test_cetak_menolak_kelas_bukan_xii_dan_jenis_tidak_dikenal(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->get("/staf/kelulusan/transkrip/cetak?kelas={$this->kelas->id}")->assertNotFound();
        $this->get('/staf/kelulusan/rahasia')->assertNotFound();
    }
}
