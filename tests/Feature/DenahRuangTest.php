<?php

namespace Tests\Feature;

use App\Models\Denah;
use App\Models\DenahPenempatan;
use App\Models\DenahRuang;
use App\Models\Kelas;
use App\Models\Ruang;
use App\Models\SesiUjian;
use App\Models\Siswa;
use App\Models\User;
use App\Services\KesalahanJadwal;
use App\Services\LayananDenah;
use App\Services\LayananJadwal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class DenahRuangTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private Denah $denah;

    /** @var array<string,Kelas> */
    private array $kelasTingkat = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 6); // kelas X A dengan 6 siswa; jadwal aktif
        $this->kelasTingkat['X'] = $this->kelas;
        $this->denah = Denah::create(['nama' => 'UTS', 'periode_id' => $this->jadwal->periode_id, 'dibuat_oleh' => $this->admin->id]);
    }

    private function kelasBaru(string $tingkat, int $jumlah, ?string $nama = null): Kelas
    {
        $k = Kelas::create(['tahun_ajaran_id' => $this->kelas->tahun_ajaran_id, 'tingkat' => $tingkat, 'nama' => $nama ?? "{$tingkat} B"]);
        Siswa::factory()->count($jumlah)->create()->each(fn (Siswa $s) => $s->kelas()->attach($k->id, ['status' => 'aktif']));
        $this->kelasTingkat[$tingkat] = $k;

        return $k;
    }

    private function ruang(string $nama, int $meja, int $perMeja = 2): Ruang
    {
        $r = Ruang::create(['nama' => $nama]);
        DenahRuang::create(['denah_id' => $this->denah->id, 'ruang_id' => $r->id, 'jumlah_meja' => $meja, 'siswa_per_meja' => $perMeja]);

        return $r;
    }

    private function bagi(array $tingkat, ?int $benih = 7): array
    {
        return app(LayananDenah::class)->bagi($this->denah, collect($tingkat)->map(fn ($t) => $this->kelasTingkat[$t]->id)->all(), null, $benih);
    }

    /** @return array<string,list<string>> "ruang-meja" => tingkat para penghuni */
    private function tingkatPerMeja(): array
    {
        $tingkat = Kelas::pluck('tingkat', 'id');
        $kelasSiswa = DB::table('keanggotaan_kelas')->pluck('kelas_id', 'siswa_id');
        $hasil = [];
        foreach (DenahPenempatan::where('denah_id', $this->denah->id)->get() as $p) {
            $hasil["{$p->ruang_id}-{$p->meja}"][] = $tingkat[$kelasSiswa[$p->siswa_id]];
        }

        return $hasil;
    }

    // ── Pembagian otomatis ──────────────────────────────────────────────────

    public function test_dua_tingkat_seimbang_setiap_meja_berbeda_tingkat(): void
    {
        $this->kelasBaru('XI', 6);
        $this->ruang('Lab 1', 4);
        $this->ruang('Lab 2', 2);

        $h = $this->bagi(['X', 'XI']);

        $this->assertSame(12, $h['ditempatkan']);
        $this->assertSame(0, $h['tanpa_tempat']);
        $this->assertSame(0, $h['meja_sejenis']);
        $meja = $this->tingkatPerMeja();
        $this->assertCount(6, $meja);
        foreach ($meja as $isi) {
            $this->assertCount(2, array_unique($isi), 'meja harus berisi dua tingkat berbeda');
        }
        $this->assertSame(12, DenahPenempatan::where('denah_id', $this->denah->id)->distinct('siswa_id')->count('siswa_id'));
    }

    public function test_tiga_tingkat_tiga_siswa_per_meja(): void
    {
        $this->kelasBaru('XI', 6);
        $this->kelasBaru('XII', 6);
        $this->ruang('Aula', 6, 3);

        $h = $this->bagi(['X', 'XI', 'XII']);

        $this->assertSame(18, $h['ditempatkan']);
        foreach ($this->tingkatPerMeja() as $isi) {
            sort($isi);
            $this->assertSame(['X', 'XI', 'XII'], $isi);
        }
    }

    public function test_tidak_seimbang_dilaporkan_meja_sejenis(): void
    {
        $this->kelasBaru('XI', 2);
        $this->ruang('Lab 1', 4); // 8 siswa, 4 meja

        $h = $this->bagi(['X', 'XI']);

        $this->assertSame(8, $h['ditempatkan']);
        $this->assertSame(2, $h['meja_sejenis']); // 6 X dan 2 XI: dua meja campur, dua meja X-X
    }

    public function test_kapasitas_kurang_menyisakan_siswa_tanpa_tempat(): void
    {
        $this->ruang('Kecil', 2); // kapasitas 4 dari 6 siswa

        $h = $this->bagi(['X']);

        $this->assertSame(4, $h['ditempatkan']);
        $this->assertSame(2, $h['tanpa_tempat']);
        $this->assertSame(4, $h['kapasitas']);
    }

    public function test_siswa_nonaktif_dan_kelas_lain_tidak_ikut(): void
    {
        $this->siswa[0]->update(['status' => 'nonaktif']);
        $this->kelasBaru('XI', 3); // tidak dipilih
        $this->ruang('Lab 1', 10);

        $h = $this->bagi(['X']);

        $this->assertSame(5, $h['ditempatkan']);
        $this->assertFalse(DenahPenempatan::where('siswa_id', $this->siswa[0]->id)->exists());
    }

    public function test_bagi_ulang_mengganti_penempatan_dan_butuh_ruang(): void
    {
        $this->expectException(KesalahanJadwal::class);
        $this->expectExceptionMessage('minimal satu ruang');
        $this->bagi(['X']);
    }

    public function test_bagi_ulang_tidak_menggandakan_siswa(): void
    {
        $this->ruang('Lab 1', 10);
        $this->bagi(['X']);
        $this->bagi(['X'], 99);

        $this->assertSame(6, DenahPenempatan::where('denah_id', $this->denah->id)->count());
    }

    // ── Perubahan manual ────────────────────────────────────────────────────

    public function test_tempatkan_memindah_menukar_dan_memvalidasi(): void
    {
        $r = $this->ruang('Lab 1', 3);
        $this->bagi(['X']);
        $layanan = app(LayananDenah::class);
        $a = DenahPenempatan::where('denah_id', $this->denah->id)->where('ruang_id', $r->id)->where('meja', 1)->where('posisi', 1)->first();
        $b = DenahPenempatan::where('denah_id', $this->denah->id)->where('ruang_id', $r->id)->where('meja', 2)->where('posisi', 2)->first();

        $layanan->tempatkan($this->denah, Siswa::find($a->siswa_id), $r->id, 2, 2); // tukar
        $this->assertSame([2, 2], [DenahPenempatan::where('siswa_id', $a->siswa_id)->value('meja'), DenahPenempatan::where('siswa_id', $a->siswa_id)->value('posisi')]);
        $this->assertSame([1, 1], [DenahPenempatan::where('siswa_id', $b->siswa_id)->value('meja'), DenahPenempatan::where('siswa_id', $b->siswa_id)->value('posisi')]);

        $layanan->tempatkan($this->denah, Siswa::find($a->siswa_id), $r->id, 3, 2); // ke tempat kosong
        $this->assertSame(3, DenahPenempatan::where('siswa_id', $a->siswa_id)->value('meja'));
        $this->assertSame(6, DenahPenempatan::where('denah_id', $this->denah->id)->count());

        foreach ([[99, 1], [0, 1], [1, 3], [1, 0]] as [$meja, $posisi]) {
            try {
                $layanan->tempatkan($this->denah, Siswa::find($a->siswa_id), $r->id, $meja, $posisi);
                $this->fail("seharusnya ditolak: meja {$meja} posisi {$posisi}");
            } catch (KesalahanJadwal) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(KesalahanJadwal::class);
        $layanan->tempatkan($this->denah, Siswa::find($a->siswa_id), 99999, 1, 1); // ruang bukan bagian denah
    }

    public function test_lepas_siswa(): void
    {
        $this->ruang('Lab 1', 10);
        $this->bagi(['X']);

        app(LayananDenah::class)->lepas($this->denah, $this->siswa[0]);

        $this->assertFalse(DenahPenempatan::where('siswa_id', $this->siswa[0]->id)->exists());
        $this->assertSame(5, DenahPenempatan::count());
    }

    // ── Penerapan ke jadwal ─────────────────────────────────────────────────

    public function test_aktivasi_menyalin_ruang_dan_meja_ke_sesi(): void
    {
        $r = $this->ruang('Lab 1', 10);
        $this->bagi(['X']);
        $layanan = app(LayananJadwal::class);
        $layanan->batalkanAktivasi($this->jadwal);
        $this->jadwal->refresh()->update(['denah_id' => $this->denah->id]);

        $layanan->aktifkan($this->jadwal->refresh());

        foreach ($this->siswa as $s) {
            $sesi = $this->sesiDari($s);
            $p = DenahPenempatan::where('denah_id', $this->denah->id)->where('siswa_id', $s->id)->first();
            $this->assertSame($r->id, $sesi->ruang_id);
            $this->assertSame($p->meja, $sesi->no_meja);
        }
    }

    public function test_perubahan_denah_mengikuti_jadwal_aktif_kecuali_sesi_selesai(): void
    {
        $r = $this->ruang('Lab 1', 10);
        $this->bagi(['X']);
        $this->jadwal->update(['denah_id' => $this->denah->id]);
        $layanan = app(LayananDenah::class);
        $this->assertSame(6, $layanan->terapkan($this->jadwal->refresh()));

        // Siswa 0 sudah selesai: penempatannya tidak ikut berubah.
        $this->mulaiSesi($this->siswa[0]);
        app(\App\Services\LayananSesi::class)->selesaikan($this->sesiDari($this->siswa[0]), 'kirim');
        $mejaSelesai = $this->sesiDari($this->siswa[0])->no_meja;

        $layanan->tempatkan($this->denah, $this->siswa[1], $r->id, 10, 2);
        $layanan->tempatkan($this->denah, $this->siswa[0], $r->id, 10, 1);

        $this->assertSame(10, $this->sesiDari($this->siswa[1])->no_meja);
        $this->assertSame($mejaSelesai, $this->sesiDari($this->siswa[0])->no_meja);

        $layanan->lepas($this->denah, $this->siswa[2]);
        $this->assertNull($this->sesiDari($this->siswa[2])->ruang_id);
        $this->assertSame(1, $layanan->tanpaTempat($this->jadwal->refresh()));
    }

    public function test_jadwal_tanpa_denah_tidak_berubah(): void
    {
        $this->assertSame(0, app(LayananDenah::class)->terapkan($this->jadwal));
        $this->assertSame(0, app(LayananDenah::class)->tanpaTempat($this->jadwal));
        $this->assertNull($this->sesiDari($this->siswa[0])->ruang_id);
    }

    public function test_pasang_dan_cabut_denah_lewat_ubah_jadwal(): void
    {
        $this->ruang('Lab 1', 10);
        $this->bagi(['X']);
        $dasar = ['tutup_pada' => now()->addHours(2)->format('Y-m-d H:i:s'), 'batas_pelanggaran' => 3, 'tampilkan_hasil' => 'langsung'];

        $this->actingAs($this->admin, 'web')->put(route('staf.jadwal.update', $this->jadwal), $dasar + ['denah_id' => $this->denah->id])->assertRedirect();
        $this->assertNotNull($this->sesiDari($this->siswa[0])->ruang_id);

        $this->put(route('staf.jadwal.update', $this->jadwal), $dasar)->assertRedirect();
        $this->assertNull($this->jadwal->fresh()->denah_id);
        $this->assertNull($this->sesiDari($this->siswa[0])->ruang_id);
        $this->assertNull($this->sesiDari($this->siswa[0])->no_meja);
    }

    // ── Halaman admin ───────────────────────────────────────────────────────

    public function test_hanya_admin_yang_mengatur_denah(): void
    {
        $guru = User::factory()->create();
        $pengawas = User::factory()->pengawas()->create();

        $this->actingAs($this->admin, 'web')->get(route('staf.denah.index'))->assertOk();
        $this->actingAs($this->admin, 'web')->get(route('staf.denah.show', $this->denah))->assertOk()->assertSee('UTS');
        $this->actingAs($guru, 'web')->get(route('staf.denah.index'))->assertForbidden();
        $this->actingAs($pengawas, 'web')->post(route('staf.denah.bagi', $this->denah), ['kelas_ids' => [$this->kelas->id]])->assertForbidden();
        $this->assertSame(0, DenahPenempatan::count());
    }

    public function test_alur_halaman_buat_ruang_bagi_tempatkan_lepas(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->post(route('staf.denah.store'), ['nama' => 'Denah Baru', 'periode_id' => $this->jadwal->periode_id])->assertRedirect();
        $denah = Denah::where('nama', 'Denah Baru')->firstOrFail();
        $r = Ruang::create(['nama' => 'Lab Z']);

        $this->post(route('staf.denah.ruang', $denah), ['ruang_id' => $r->id, 'jumlah_meja' => 5, 'siswa_per_meja' => 2])->assertSessionHasNoErrors();
        $this->post(route('staf.denah.ruang', $denah), ['ruang_id' => $r->id, 'jumlah_meja' => 0, 'siswa_per_meja' => 4])->assertSessionHasErrors(['jumlah_meja', 'siswa_per_meja']);
        $this->post(route('staf.denah.bagi', $denah), ['kelas_ids' => []])->assertSessionHasErrors('kelas_ids');
        $this->post(route('staf.denah.bagi', $denah), ['kelas_ids' => [$this->kelas->id]])->assertSessionHas('catatan'); // sejenis (satu tingkat)
        $this->assertSame(6, DenahPenempatan::where('denah_id', $denah->id)->count());

        $s = $this->siswa[0];
        $this->post(route('staf.denah.tempatkan', $denah), ['nisn' => $s->nisn, 'ruang_id' => $r->id, 'meja' => 5, 'posisi' => 2])->assertSessionHas('berhasil');
        $this->post(route('staf.denah.tempatkan', $denah), ['nisn' => 'tidak-ada', 'ruang_id' => $r->id, 'meja' => 1, 'posisi' => 1])->assertSessionHas('gagal');
        $this->post(route('staf.denah.tempatkan', $denah), ['nisn' => $s->nisn, 'ruang_id' => $r->id, 'meja' => 6, 'posisi' => 1])->assertSessionHas('gagal');
        $this->post(route('staf.denah.lepas', [$denah, $s]))->assertSessionHas('berhasil');
        $this->assertFalse(DenahPenempatan::where('denah_id', $denah->id)->where('siswa_id', $s->id)->exists());

        $this->get(route('staf.denah.show', $denah))->assertOk()->assertSee('Lab Z');
    }

    public function test_mengecilkan_ruang_melepas_penempatan_di_luar_kapasitas(): void
    {
        $r = $this->ruang('Lab 1', 10);
        $this->bagi(['X']); // 6 siswa menempati meja 1-3

        $this->actingAs($this->admin, 'web')->post(route('staf.denah.ruang', $this->denah), ['ruang_id' => $r->id, 'jumlah_meja' => 2, 'siswa_per_meja' => 2])->assertSessionHasNoErrors();

        $this->assertSame(4, DenahPenempatan::where('denah_id', $this->denah->id)->count());
        $this->assertSame(0, DenahPenempatan::where('denah_id', $this->denah->id)->where('meja', '>', 2)->count());
    }

    // ── Pengawas lintas ruang ───────────────────────────────────────────────

    public function test_pengawas_melihat_dan_menangani_semua_ruang(): void
    {
        $r1 = $this->ruang('Lab 1', 2);
        $r2 = $this->ruang('Lab 2', 2);
        $this->bagi(['X']);
        $this->jadwal->update(['denah_id' => $this->denah->id]);
        app(LayananDenah::class)->terapkan($this->jadwal->refresh());
        $pengawas = User::factory()->pengawas()->create();
        foreach ($this->siswa as $s) {
            $this->mulaiSesi($s);
        }

        $d = $this->actingAs($pengawas, 'web')->getJson("/staf/monitor/{$this->jadwal->id}/data")->assertOk()->json();
        $this->assertSame(count($this->siswa), count($d['peserta']));
        $this->assertEqualsCanonicalizing([$r1->id, $r2->id], array_values(array_unique(array_column($d['peserta'], 'ruang_id'))));

        $sesiLab1 = SesiUjian::where('jadwal_id', $this->jadwal->id)->where('ruang_id', $r1->id)->first();
        $sesiLab2 = SesiUjian::where('jadwal_id', $this->jadwal->id)->where('ruang_id', $r2->id)->first();
        $this->postJson("/staf/monitor/{$this->jadwal->id}/sesi/{$sesiLab2->id}/reset")->assertOk();
        $this->postJson("/staf/monitor/{$this->jadwal->id}/sesi/{$sesiLab1->id}/reset")->assertOk();
    }
}
