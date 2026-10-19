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

    /** Bawaan campur tingkat agar tes pengacakan tetap berlaku; $campur false = pembagian urut kelas seperti aplikasi lama. */
    private function bagi(array $tingkat, ?int $benih = 7, bool $campur = true): array
    {
        return app(LayananDenah::class)->bagi($this->denah, collect($tingkat)->map(fn ($t) => $this->kelasTingkat[$t]->id)->all(), null, $benih, $campur);
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

    // ── Halaman admin ───────────────────────────────────────────────────────

    public function test_bagi_bawaan_mengisi_ruang_berurutan_per_kelas_lalu_nama(): void
    {
        $kelasA = $this->kelas;
        $kelasB = $this->kelasBaru('X', 3, 'X 0'); // nama kelas lebih awal dari "X A"
        $r1 = $this->ruang('Lab 1', 4, 1);
        $r2 = $this->ruang('Lab 2', 10, 1);

        $h = app(LayananDenah::class)->bagi($this->denah, [$kelasA->id, $kelasB->id], null, null, false);
        $this->assertSame(9, $h['ditempatkan']);
        $this->assertSame(0, $h['meja_sejenis']);

        $lab1 = DenahPenempatan::where('ruang_id', $r1->id)->orderBy('meja')->get();
        $this->assertSame([1, 2, 3, 4], $lab1->pluck('meja')->all());
        $this->assertSame([1, 1, 1, 1], $lab1->pluck('posisi')->all());
        $kelasSiswa = DB::table('keanggotaan_kelas')->pluck('kelas_id', 'siswa_id');
        $this->assertSame([$kelasB->id, $kelasB->id, $kelasB->id], $lab1->take(3)->map(fn ($p) => $kelasSiswa[$p->siswa_id])->all()); // X AAA lebih dulu
        $this->assertSame(5, DenahPenempatan::where('ruang_id', $r2->id)->count());
    }

    public function test_kapasitas_ruang_dihormati_saat_bagi(): void
    {
        $r = Ruang::create(['nama' => 'Kecil']);
        app(LayananDenah::class)->aturRuang($this->denah, [$r->id => 3]);

        $h = $this->bagi(['X'], campur: false);

        $this->assertSame(3, $h['ditempatkan']);
        $this->assertSame(3, $h['tanpa_tempat']);
        $this->assertSame(3, DenahRuang::where('ruang_id', $r->id)->value('kapasitas'));
    }

    public function test_urutkan_menyusun_nomor_meja_mengikuti_urutan_dan_aman_dari_id_asing(): void
    {
        $r = $this->ruang('Lab 1', 10, 1);
        $this->bagi(['X'], campur: false);
        $dr = DenahRuang::where('ruang_id', $r->id)->first();
        $urut = DenahPenempatan::where('ruang_id', $r->id)->orderBy('meja')->pluck('siswa_id')->all();

        $baru = array_reverse($urut);
        app(LayananDenah::class)->urutkan($this->denah, $dr, array_merge($baru, [999999]));

        $this->assertSame($baru, DenahPenempatan::where('ruang_id', $r->id)->orderBy('meja')->pluck('siswa_id')->all());
        $this->assertSame(range(1, 6), DenahPenempatan::where('ruang_id', $r->id)->orderBy('meja')->pluck('meja')->all());

    }

    public function test_pindah_ke_ruang_penuh_ditolak(): void
    {
        $r1 = $this->ruang('Lab 1', 4, 1);
        $r2 = $this->ruang('Lab 2', 2, 1);
        $this->bagi(['X'], campur: false); // Lab 1 penuh (4), Lab 2 berisi 2 (penuh)
        $s = DenahPenempatan::where('ruang_id', $r1->id)->first()->siswa;

        $this->expectException(KesalahanJadwal::class);
        $this->expectExceptionMessage('sudah penuh');
        app(LayananDenah::class)->pindah($this->denah, $s, $r2->id);
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

    // ── Menu Ruang (denah utama, seperti aplikasi lama) ─────────────────────

    private function utama(): Denah
    {
        return app(LayananDenah::class)->utama();
    }

    public function test_pasang_dan_cabut_denah_lewat_ubah_jadwal(): void
    {
        $this->ruang('Lab 1', 10);
        $this->bagi(['X']);
        $dasar = ['tutup_pada' => now()->addHours(2)->format('Y-m-d H:i:s'), 'batas_pelanggaran' => 3, 'tampilkan_hasil' => 'langsung'];

        $this->actingAs($this->admin, 'web')->put(route('staf.jadwal.update', $this->jadwal), $dasar + ['denah_id' => $this->denah->id])->assertRedirect();
        $this->assertNotNull($this->sesiDari($this->siswa[0])->ruang_id);

        // Tanpa denah khusus, jadwal memakai penempatan tetap dari menu Ruang (belum ada penempatan => tanpa ruang).
        $this->put(route('staf.jadwal.update', $this->jadwal), $dasar)->assertRedirect();
        $this->assertSame($this->utama()->id, $this->jadwal->fresh()->denah_id);
        $this->assertNull($this->sesiDari($this->siswa[0])->ruang_id);
        $this->assertNull($this->sesiDari($this->siswa[0])->no_meja);
    }

    public function test_hanya_admin_yang_mengatur_ruang(): void
    {
        $guru = User::factory()->create();
        $pengawas = User::factory()->pengawas()->create();

        $this->actingAs($this->admin, 'web')->get(route('staf.ruang.index'))->assertOk()->assertSee('Ruang Ujian');
        $this->get('/staf')->assertSee('data-tip="Ruang Ujian"', false)->assertDontSee('Denah Ruang');
        $this->actingAs($guru, 'web')->get(route('staf.ruang.index'))->assertForbidden();
        $r = Ruang::create(['nama' => 'Lab A', 'kapasitas' => 10]);
        $this->actingAs($pengawas, 'web')->post(route('staf.ruang.kelas', $r), ['kelas_ids' => [$this->kelas->id]])->assertForbidden();
        $this->assertSame(0, DenahPenempatan::count());
    }

    public function test_tambah_edit_dan_hapus_ruang(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->post(route('staf.ruang.store'), ['nama' => 'LAB TKJ 1', 'kapasitas' => 4])->assertSessionHas('berhasil');
        $r = Ruang::where('nama', 'LAB TKJ 1')->firstOrFail();
        $this->assertSame(4, $r->kapasitas);
        $this->post(route('staf.ruang.store'), ['nama' => 'LAB TKJ 1', 'kapasitas' => 4])->assertSessionHasErrors('nama');
        $this->post(route('staf.ruang.store'), ['nama' => 'X', 'kapasitas' => 0])->assertSessionHasErrors('kapasitas');
        $this->get(route('staf.ruang.index')); // input lama dari validasi gagal tidak terbawa ke form
        $this->get(route('staf.ruang.edit', $r))->assertOk()->assertSee('Ubah Ruang Ujian')->assertSee('LAB TKJ 1');
        $this->get(route('staf.ruang.create'))->assertOk()->assertSee('Tambah Ruang Ujian');

        $this->put(route('staf.ruang.update', $r), ['nama' => 'LAB TKJ 1A', 'kapasitas' => 5])->assertSessionHas('berhasil');
        $this->assertSame('LAB TKJ 1A', $r->fresh()->nama);

        $this->post(route('staf.ruang.kelas', $r), ['kelas_ids' => [$this->kelas->id]])->assertSessionHas('catatan'); // 5 dari 6 siswa
        $this->assertSame(5, DenahPenempatan::count());

        $this->delete(route('staf.ruang.destroy', $r))->assertSessionHas('berhasil');
        $this->assertDatabaseMissing('ruang', ['id' => $r->id]);
        $this->assertSame(0, DenahPenempatan::count()); // siswa dilepas, data siswa tetap
        $this->assertSame(6, Siswa::where('status', 'aktif')->count());
    }

    public function test_bagi_pindah_keluarkan_dan_peringatan_belum_punya_ruang(): void
    {
        $this->actingAs($this->admin, 'web');
        $r1 = Ruang::create(['nama' => 'Lab A', 'kapasitas' => 4]);
        $r2 = Ruang::create(['nama' => 'Lab B', 'kapasitas' => 10]);

        $this->get(route('staf.ruang.index'))->assertSee('6 siswa aktif');
        $this->post(route('staf.ruang.kelas', $r1), ['kelas_ids' => []])->assertSessionHasErrors('kelas_ids');
        $this->post(route('staf.ruang.kelas', $r1), ['kelas_ids' => [$this->kelas->id]])->assertSessionHas('catatan'); // 4 muat, 2 sisa
        $this->get(route('staf.ruang.index'))->assertSee('2 siswa aktif');
        $this->post(route('staf.ruang.kelas', $r2), ['kelas_ids' => [$this->kelas->id]])->assertSessionHas('berhasil'); // sisanya ke ruang lain
        $this->assertSame(4, DenahPenempatan::where('ruang_id', $r1->id)->count());
        $this->assertSame(2, DenahPenempatan::where('ruang_id', $r2->id)->count());
        $this->get(route('staf.ruang.index'))->assertDontSee('siswa aktif</strong> yang belum', false);

        $s = DenahPenempatan::where('ruang_id', $r1->id)->where('meja', 1)->first()->siswa;
        $this->post(route('staf.ruang.pindah', $s), ['ruang_id' => $r2->id])->assertSessionHas('berhasil');
        $this->assertSame($r2->id, DenahPenempatan::where('siswa_id', $s->id)->value('ruang_id'));
        $this->assertSame([1, 2, 3], DenahPenempatan::where('ruang_id', $r1->id)->orderBy('meja')->pluck('meja')->all()); // celah ditutup

        $this->post(route('staf.ruang.pindah', $s), ['ruang_id' => 'keluar'])->assertSessionHas('berhasil');
        $this->assertFalse(DenahPenempatan::where('siswa_id', $s->id)->exists());
        $this->get(route('staf.ruang.index'))->assertSee('1 siswa aktif');

        $this->post(route('staf.ruang.pindah', $s), ['ruang_id' => 'bukan-angka'])->assertSessionHasErrors('ruang_id');
    }

    public function test_mengubah_kapasitas_ruang_melepas_kelebihan_penempatan(): void
    {
        $this->actingAs($this->admin, 'web');
        $r = Ruang::create(['nama' => 'Lab A', 'kapasitas' => 10]);
        $this->post(route('staf.ruang.kelas', $r), ['kelas_ids' => [$this->kelas->id]]);
        $this->assertSame(6, DenahPenempatan::count());

        $this->put(route('staf.ruang.update', $r), ['nama' => 'Lab A', 'kapasitas' => 4])->assertSessionHasNoErrors();

        $this->assertSame(4, DenahPenempatan::count());
        $this->assertSame(4, DenahRuang::where('ruang_id', $r->id)->value('kapasitas'));
    }

    public function test_halaman_ruang_urutan_cetak_meja_dan_absen(): void
    {
        $this->actingAs($this->admin, 'web');
        $r = Ruang::create(['nama' => 'Lab A', 'kapasitas' => 10]);
        $this->post(route('staf.ruang.kelas', $r), ['kelas_ids' => [$this->kelas->id]]);

        $this->get(route('staf.ruang.show', $r))->assertOk()->assertSee('Lab A')->assertSee($this->siswa[0]->nama)->assertSee('Cetak no. meja', false);
        $this->get(route('staf.ruang.no-meja', $r))->assertOk()->assertSee($this->siswa[0]->nama);
        $this->get(route('staf.ruang.absen', $r))->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->post(route('staf.ruang.urut-kelas', $r))->assertSessionHas('berhasil');

        $urut = DenahPenempatan::where('ruang_id', $r->id)->orderBy('meja')->pluck('siswa_id')->all();
        $this->postJson(route('staf.ruang.urutan', $r), ['ids' => array_reverse($urut)])->assertOk();
        $this->assertSame(array_reverse($urut), DenahPenempatan::where('ruang_id', $r->id)->orderBy('meja')->pluck('siswa_id')->all());

        $this->actingAs(User::factory()->create(), 'web')->get(route('staf.ruang.show', $r))->assertForbidden();
    }

    public function test_jadwal_otomatis_memakai_penempatan_tetap_dan_sesi_mendapat_ruang(): void
    {
        $this->actingAs($this->admin, 'web');
        $r = Ruang::create(['nama' => 'Lab K', 'kapasitas' => 10]);
        $this->assertNull($this->jadwal->fresh()->denah_id);

        $this->post(route('staf.ruang.kelas', $r), ['kelas_ids' => [$this->kelas->id]])->assertSessionHas('berhasil');

        $this->assertSame($this->utama()->id, $this->jadwal->fresh()->denah_id);
        $sesi = $this->sesiDari($this->siswa[0]);
        $this->assertSame($r->id, $sesi->ruang_id);
        $this->assertNotNull($sesi->no_meja);
    }

    // ── Masukkan kelas ke ruang (biasa dan campur tingkat) ──────────────────

    /** @return array<string,int> tingkat => jumlah siswa di ruang */
    private function tingkatDiRuang(Ruang $r): array
    {
        $tingkat = Kelas::pluck('tingkat', 'id');
        $kelasSiswa = DB::table('keanggotaan_kelas')->pluck('kelas_id', 'siswa_id');

        return DenahPenempatan::where('ruang_id', $r->id)->get()->map(fn ($p) => $tingkat[$kelasSiswa[$p->siswa_id]])->countBy()->all();
    }

    public function test_satu_ruang_biasa_menampung_beberapa_kelas_dan_sisanya_ke_ruang_lain(): void
    {
        $this->actingAs($this->admin, 'web');
        $kelasB = $this->kelasBaru('X', 5, 'X B');
        $besar = Ruang::create(['nama' => 'Aula', 'kapasitas' => 8]);
        $kecil = Ruang::create(['nama' => 'Kelas 1', 'kapasitas' => 10]);

        $this->post(route('staf.ruang.kelas', $besar), ['kelas_ids' => [$this->kelas->id, $kelasB->id]])->assertSessionHas('catatan');
        $this->assertSame(8, DenahPenempatan::where('ruang_id', $besar->id)->count()); // 6 kelas A + 2 dari kelas B
        $this->assertSame(range(1, 8), DenahPenempatan::where('ruang_id', $besar->id)->orderBy('meja')->pluck('meja')->all());

        $this->post(route('staf.ruang.kelas', $kecil), ['kelas_ids' => [$kelasB->id]])->assertSessionHas('berhasil'); // 3 sisa kelas B
        $this->assertSame(3, DenahPenempatan::where('ruang_id', $kecil->id)->count());
        $this->assertSame(11, DenahPenempatan::count());
        $this->assertSame(11, DenahPenempatan::distinct('siswa_id')->count('siswa_id'));
    }

    public function test_ruang_campur_membatasi_tiap_tingkat_maksimal_setengah_kapasitas(): void
    {
        $this->actingAs($this->admin, 'web');
        $xi = $this->kelasBaru('XI', 8, 'XI A');
        $r1 = Ruang::create(['nama' => 'Lab 1', 'kapasitas' => 10, 'campur' => true]);
        $r2 = Ruang::create(['nama' => 'Lab 2', 'kapasitas' => 10, 'campur' => true]);

        $this->post(route('staf.ruang.kelas', $r1), ['kelas_ids' => [$this->kelas->id, $xi->id]])->assertSessionHas('catatan');
        $this->assertEquals(['X' => 5, 'XI' => 5], $this->tingkatDiRuang($r1)); // 6 X dan 8 XI, masing-masing dibatasi 5

        $this->post(route('staf.ruang.kelas', $r2), ['kelas_ids' => [$this->kelas->id, $xi->id]])->assertSessionHas('berhasil'); // sisa semuanya muat
        $this->assertEquals(['X' => 1, 'XI' => 3], $this->tingkatDiRuang($r2));
        $this->assertSame(14, DenahPenempatan::count());

        // Setiap meja di ruang campur berisi tingkat berbeda (selang-seling).
        $tingkat = Kelas::pluck('tingkat', 'id');
        $kelasSiswa = DB::table('keanggotaan_kelas')->pluck('kelas_id', 'siswa_id');
        $meja = DenahPenempatan::where('ruang_id', $r1->id)->get()->groupBy('meja')->map(fn ($g) => $g->map(fn ($p) => $tingkat[$kelasSiswa[$p->siswa_id]])->all());
        $this->assertCount(5, $meja);
        foreach ($meja as $isi) {
            $this->assertCount(2, array_unique($isi), 'meja campur harus berisi dua tingkat berbeda');
        }
    }

    public function test_porsi_tingkat_dihitung_dari_semua_kelas_sejenis_dalam_ruang_campur(): void
    {
        $this->actingAs($this->admin, 'web');
        $kelasB = $this->kelasBaru('X', 3, 'X B');
        $r = Ruang::create(['nama' => 'Lab 1', 'kapasitas' => 8, 'campur' => true]); // maksimal 4 per tingkat

        $this->post(route('staf.ruang.kelas', $r), ['kelas_ids' => [$kelasB->id, $this->kelas->id]]);

        $this->assertSame(['X' => 4], $this->tingkatDiRuang($r)); // 3 dari X B + 1 dari X A
    }

    public function test_pindah_ke_ruang_campur_ditolak_bila_porsi_tingkat_penuh(): void
    {
        $this->actingAs($this->admin, 'web');
        $r = Ruang::create(['nama' => 'Lab 1', 'kapasitas' => 4, 'campur' => true]); // maksimal 2 per tingkat
        $biasa = Ruang::create(['nama' => 'Aula', 'kapasitas' => 10]);
        $this->post(route('staf.ruang.kelas', $biasa), ['kelas_ids' => [$this->kelas->id]]);
        $ids = DenahPenempatan::where('ruang_id', $biasa->id)->orderBy('meja')->pluck('siswa_id')->all();

        $this->post(route('staf.ruang.pindah', Siswa::find($ids[0])), ['ruang_id' => $r->id])->assertSessionHas('berhasil');
        $this->post(route('staf.ruang.pindah', Siswa::find($ids[1])), ['ruang_id' => $r->id])->assertSessionHas('berhasil');
        $this->post(route('staf.ruang.pindah', Siswa::find($ids[2])), ['ruang_id' => $r->id])->assertSessionHas('gagal');
        $this->assertSame(2, DenahPenempatan::where('ruang_id', $r->id)->count());
    }

    public function test_mengaktifkan_campur_ditolak_bila_satu_tingkat_sudah_lebih_dari_setengah(): void
    {
        $this->actingAs($this->admin, 'web');
        $r = Ruang::create(['nama' => 'Lab 1', 'kapasitas' => 10]);
        $this->post(route('staf.ruang.kelas', $r), ['kelas_ids' => [$this->kelas->id]]); // 6 siswa tingkat X

        $this->put(route('staf.ruang.update', $r), ['nama' => 'Lab 1', 'kapasitas' => 10, 'campur' => '1'])->assertSessionHas('gagal');
        $this->assertFalse($r->fresh()->campur);

        $this->delete(route('staf.ruang.kelas.keluar', [$r, $this->kelas]))->assertSessionHas('berhasil');
        $this->assertSame(0, DenahPenempatan::where('ruang_id', $r->id)->count());
        $this->put(route('staf.ruang.update', $r), ['nama' => 'Lab 1', 'kapasitas' => 10, 'campur' => '1'])->assertSessionHas('berhasil');
        $this->assertTrue($r->fresh()->campur);
    }

    public function test_kelas_tanpa_siswa_tersisa_tidak_masuk_dan_halaman_ruang_menampilkan_kelas_belum_beruang(): void
    {
        $this->actingAs($this->admin, 'web');
        $r = Ruang::create(['nama' => 'Lab 1', 'kapasitas' => 10]);

        $this->get(route('staf.ruang.show', $r))->assertOk()->assertSee('Masukkan kelas')->assertSee('X A');
        $this->post(route('staf.ruang.kelas', $r), ['kelas_ids' => [$this->kelas->id]])->assertSessionHas('berhasil');
        $this->post(route('staf.ruang.kelas', $r), ['kelas_ids' => [$this->kelas->id]])->assertSessionHas('catatan'); // sudah punya ruang semua
        $this->get(route('staf.ruang.show', $r))->assertOk()->assertSee('Semua siswa aktif sudah punya ruang');
    }
}
