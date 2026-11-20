<?php

namespace Tests\Feature;

use App\Models\DenahPenempatan;
use App\Models\Kelas;
use App\Models\Ruang;
use App\Models\Siswa;
use App\Models\User;
use App\Services\KesalahanJadwal;
use App\Services\LayananDenah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class RuangOtomatisTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 1);
    }

    private function kelasIsi(string $tingkat, string $nama, int $jumlah): Kelas
    {
        $k = Kelas::create(['tahun_ajaran_id' => $this->kelas->tahun_ajaran_id, 'tingkat' => $tingkat, 'nama' => $nama]);
        Siswa::factory()->count($jumlah)->create()->each(fn (Siswa $s) => $s->kelas()->attach($k->id, ['status' => 'aktif']));

        return $k;
    }

    private function ruang(string $nama, int $kapasitas, bool $campur = false): Ruang
    {
        return Ruang::create(['nama' => $nama, 'kapasitas' => $kapasitas, 'campur' => $campur]);
    }

    /** @return array<string,array<string,int>> nama ruang => [nama kelas => jumlah] */
    private function isi(array $rencana): array
    {
        $hasil = [];
        foreach ($rencana['ruang'] as $p) {
            $hasil[$p['ruang']->nama] = collect($p['tambah'])->pluck('jumlah', 'nama')->all();
        }

        return $hasil;
    }

    private function layanan(): LayananDenah
    {
        return app(LayananDenah::class);
    }

    public function test_contoh_pemilik_kelas_utuh_digabung_agar_ruang_penuh_dan_urut(): void
    {
        $x = $this->kelasIsi('X', 'X TKJ', 15);
        $xiTkj = $this->kelasIsi('XI', 'XI TKJ', 5);
        $xiTsm = $this->kelasIsi('XI', 'XI TSM', 5);
        $xiDpb = $this->kelasIsi('XI', 'XI DPB', 15);
        $this->ruang('Ruang 1', 20);
        $this->ruang('Ruang 2', 20);

        $rencana = $this->layanan()->rencanaOtomatis($this->layanan()->utama(), [$x->id, $xiTkj->id, $xiTsm->id, $xiDpb->id]);

        $this->assertSame([
            'Ruang 1' => ['X TKJ' => 15, 'XI TKJ' => 5],
            'Ruang 2' => ['XI DPB' => 15, 'XI TSM' => 5],
        ], $this->isi($rencana));
        $this->assertSame(40, $rencana['ditempatkan']);
        $this->assertSame(0, $rencana['sisa_total']);
        $this->assertTrue(collect($rencana['ruang'])->flatMap(fn ($p) => $p['tambah'])->every(fn ($g) => $g['utuh']));
    }

    public function test_ruang_biasa_dapat_memuat_tiga_kelas_atau_lebih(): void
    {
        $a = $this->kelasIsi('X', 'X AA', 10);
        $b = $this->kelasIsi('XI', 'XI AA', 6);
        $c = $this->kelasIsi('XI', 'XI AB', 4);
        $d = $this->kelasIsi('XII', 'XII AA', 2);
        $this->ruang('Ruang 1', 22);

        $isi = $this->isi($this->layanan()->rencanaOtomatis($this->layanan()->utama(), [$a->id, $b->id, $c->id, $d->id]));

        $this->assertSame(['X AA' => 10, 'XI AA' => 6, 'XI AB' => 4, 'XII AA' => 2], $isi['Ruang 1']);
    }

    public function test_kelas_dipecah_ke_ruang_berikutnya_dan_sisa_dilaporkan(): void
    {
        $a = $this->kelasIsi('X', 'X AA', 8);
        $b = $this->kelasIsi('X', 'X AB', 8);
        $c = $this->kelasIsi('X', 'X AC', 8);
        $this->ruang('Ruang 1', 10);
        $this->ruang('Ruang 2', 10);
        $ids = [$a->id, $b->id, $c->id];
        $denah = $this->layanan()->utama();

        $boleh = $this->layanan()->rencanaOtomatis($denah, $ids, false, true);
        $this->assertSame(['Ruang 1' => ['X AA' => 8, 'X AB' => 2], 'Ruang 2' => ['X AB' => 6, 'X AC' => 4]], $this->isi($boleh));
        $this->assertSame(4, $boleh['sisa_total']);
        $this->assertSame('X AC', $boleh['sisa'][0]['nama']);
        $this->assertFalse($boleh['ruang'][0]['tambah'][1]['utuh']); // X B terpecah

        $utuh = $this->layanan()->rencanaOtomatis($denah, $ids, false, false);
        $this->assertSame(['Ruang 1' => ['X AA' => 8], 'Ruang 2' => ['X AB' => 8]], $this->isi($utuh));
        $this->assertSame(8, $utuh['sisa_total']);
    }

    public function test_kelas_lebih_besar_dari_ruang_tetap_dipecah_walau_pecah_dimatikan(): void
    {
        $a = $this->kelasIsi('X', 'X AA', 25);
        $this->ruang('Ruang 1', 10);
        $this->ruang('Ruang 2', 10);
        $this->ruang('Ruang 3', 10);

        $isi = $this->isi($this->layanan()->rencanaOtomatis($this->layanan()->utama(), [$a->id], false, false));

        $this->assertSame(['Ruang 1' => ['X AA' => 10], 'Ruang 2' => ['X AA' => 10], 'Ruang 3' => ['X AA' => 5]], $isi);
    }

    public function test_ruang_campur_tingkat_mematuhi_batas_lima_puluh_persen_per_tingkat(): void
    {
        $x = $this->kelasIsi('X', 'X AA', 10);
        $xi = $this->kelasIsi('XI', 'XI AA', 10);
        $this->ruang('Ruang 1', 10, campur: true);

        $rencana = $this->layanan()->rencanaOtomatis($this->layanan()->utama(), [$x->id, $xi->id]);

        $this->assertSame(['X AA' => 5, 'XI AA' => 5], $this->isi($rencana)['Ruang 1']);
        $this->assertSame(10, $rencana['sisa_total']);
    }

    public function test_penerapan_menyimpan_urutan_meja_tidak_menduplikasi_dan_bisa_diatur_ulang(): void
    {
        $x = $this->kelasIsi('X', 'X TKJ', 3);
        $xi = $this->kelasIsi('XI', 'XI TKJ', 2);
        $r1 = $this->ruang('Ruang 1', 4);
        $r2 = $this->ruang('Ruang 2', 4);
        $denah = $this->layanan()->utama();

        $h = $this->layanan()->isiOtomatis($denah, [$x->id, $xi->id], false, true);
        $this->assertSame(5, $h['ditempatkan']);
        $this->assertSame(5, DenahPenempatan::where('denah_id', $denah->id)->count());
        $this->assertSame([1, 2, 3, 4], DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $r1->id)->orderBy('meja')->pluck('meja')->all());
        $this->assertSame(1, DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $r2->id)->count());

        // Dijalankan lagi tanpa atur ulang: semua sudah punya ruang.
        $this->expectException(KesalahanJadwal::class);
        try {
            $this->layanan()->isiOtomatis($denah, [$x->id, $xi->id], false, true);
        } finally {
            // Atur ulang menghasilkan penempatan yang sama jumlahnya, tanpa duplikat.
            $this->layanan()->isiOtomatis($denah, [$x->id, $xi->id], true, true);
            $this->assertSame(5, DenahPenempatan::where('denah_id', $denah->id)->count());
            $this->assertSame(5, DenahPenempatan::where('denah_id', $denah->id)->distinct('siswa_id')->count('siswa_id'));
        }
    }

    public function test_mengisi_kursi_tersisa_tanpa_mengubah_penempatan_yang_ada(): void
    {
        $a = $this->kelasIsi('X', 'X AA', 3);
        $b = $this->kelasIsi('X', 'X AB', 3);
        $r1 = $this->ruang('Ruang 1', 5);
        $this->ruang('Ruang 2', 5);
        $denah = $this->layanan()->utama();

        $this->layanan()->isiOtomatis($denah, [$a->id], false, true);
        $awal = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $r1->id)->pluck('meja', 'siswa_id')->all();

        $rencana = $this->layanan()->rencanaOtomatis($denah, [$a->id, $b->id], false, true);
        $this->assertSame(['Ruang 1' => ['X AB' => 2], 'Ruang 2' => ['X AB' => 1]], $this->isi($rencana));

        $this->layanan()->isiOtomatis($denah, [$a->id, $b->id], false, true);
        foreach ($awal as $siswaId => $meja) {
            $this->assertSame($meja, (int) DenahPenempatan::where('denah_id', $denah->id)->where('siswa_id', $siswaId)->value('meja'));
        }
        $this->assertSame(6, DenahPenempatan::where('denah_id', $denah->id)->count());
    }

    public function test_halaman_pratinjau_dan_terapkan_hanya_admin(): void
    {
        $a = $this->kelasIsi('X', 'X TKJ', 4);
        $this->ruang('Ruang 1', 10);
        $this->actingAs($this->admin, 'web');

        $this->get('/staf/ruang/otomatis')->assertOk()->assertSee('Isi ruang otomatis')->assertSee('X TKJ');
        $this->post('/staf/ruang/otomatis', ['kelas_ids' => [$a->id], 'pecah' => 1, 'aksi' => 'pratinjau'])
            ->assertOk()->assertSee('Rencana pembagian')->assertSee('X TKJ · 4')->assertSee('Terapkan rencana');
        $this->assertSame(0, DenahPenempatan::count()); // pratinjau tidak menyimpan

        $this->post('/staf/ruang/otomatis', ['kelas_ids' => [$a->id], 'pecah' => 1, 'aksi' => 'terapkan'])
            ->assertRedirect(route('staf.ruang.index'))->assertSessionHas('berhasil');
        $this->assertSame(4, DenahPenempatan::count());
        $this->post('/staf/ruang/otomatis', ['aksi' => 'pratinjau'])->assertSessionHasErrors('kelas_ids');

        $this->actingAs(User::factory()->create(), 'web')->get('/staf/ruang/otomatis')->assertForbidden();
        $this->post('/staf/ruang/otomatis', ['kelas_ids' => [$a->id], 'aksi' => 'terapkan'])->assertForbidden();
    }
}
