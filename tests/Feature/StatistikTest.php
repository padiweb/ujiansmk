<?php

namespace Tests\Feature;

use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\NilaiRapor;
use App\Models\Siswa;
use App\Models\User;
use App\Services\LayananStatistik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class StatistikTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private Mapel $bindo;

    private Kelas $kelasB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 3);
        $this->bindo = Mapel::create(['kode' => 'BIN', 'nama' => 'Bahasa Indonesia', 'kelompok' => 'A', 'kkm' => 75]);
        $this->kelasB = Kelas::create(['tahun_ajaran_id' => $this->kelas->tahun_ajaran_id, 'tingkat' => 'X', 'nama' => 'X B']);
        $siswaB = Siswa::factory()->create();
        $siswaB->kelas()->attach($this->kelasB->id, ['status' => 'aktif']);

        // X A: siswa0 rata 90, siswa1 rata 80, siswa2 rata 80 (peringkat sama); X B: siswaB rata 95
        foreach ([[0, 90, 90], [1, 80, 80], [2, 80, 80]] as [$i, $a, $b]) {
            $this->nilai($this->siswa[$i], $this->kelas, $this->mtk, $a);
            $this->nilai($this->siswa[$i], $this->kelas, $this->bindo, $b);
        }
        $this->nilai($siswaB, $this->kelasB, $this->mtk, 95);
    }

    private function nilai(Siswa $s, Kelas $k, Mapel $m, int $na): void
    {
        NilaiRapor::create(['siswa_id' => $s->id, 'mapel_id' => $m->id, 'periode_id' => $this->jadwal->periode_id, 'kelas_id' => $k->id, 'np' => $na, 'nk' => $na, 'na' => $na, 'predikat' => 'B', 'status' => 'draf']);
    }

    public function test_peringkat_kelas_berbagi_peringkat_dan_juara_paralel_lintas_kelas(): void
    {
        $layanan = app(LayananStatistik::class);
        $siswa = $layanan->siswa($this->jadwal->periode);

        $kelasA = $layanan->perKelas($siswa)[$this->kelas->id];
        $this->assertSame([1, 2, 2], $kelasA->pluck('peringkat')->all());
        $this->assertSame([90.0, 80.0, 80.0], $kelasA->pluck('rata')->all());

        $paralel = $layanan->perTingkat($siswa)['X'];
        $this->assertSame([95.0, 90.0, 80.0, 80.0], $paralel->pluck('rata')->all());
        $this->assertSame('X B', $paralel->first()->kelas);

        $mapel = $layanan->perMapel($this->jadwal->periode)->keyBy('mapel');
        $this->assertSame(86.25, (float) $mapel['Matematika']->rata);
        $this->assertSame(4, (int) $mapel['Matematika']->tuntas);
    }

    public function test_halaman_statistik_dan_cetak_hanya_admin(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->get('/staf/statistik')->assertOk()->assertSee('Peringkat X A')->assertSee('Juara Paralel Kelas X')->assertSee('Rata-rata per Mata Pelajaran')->assertSee('95,00');
        $this->get("/staf/statistik?kelas={$this->kelasB->id}")->assertOk()->assertSee('Peringkat X B')->assertDontSee('Peringkat X A');
        $this->get("/staf/statistik/cetak/{$this->kelas->id}?periode={$this->jadwal->periode_id}")->assertOk()->assertSee('DAFTAR PERINGKAT SISWA')->assertSee($this->siswa[0]->nama);

        $this->actingAs(User::factory()->create(), 'web')->get('/staf/statistik')->assertForbidden();
    }
    /** @return array{0:\App\Models\Kelas,1:\App\Models\Kelas,2:array<int,Siswa>} */
    private function kelasXii(): array
    {
        $tahun = $this->kelas->tahunAjaran;
        $a = Kelas::create(['tahun_ajaran_id' => $tahun->id, 'tingkat' => 'XII', 'nama' => 'XII A']);
        $b = Kelas::create(['tahun_ajaran_id' => $tahun->id, 'tingkat' => 'XII', 'nama' => 'XII B']);
        $s = [];
        foreach ([[$a, 'Andi', 80, 70], [$a, 'Budi', 90, 90], [$b, 'Citra', 95, 85], [$b, 'Dewi', null, null]] as $i => [$k, $nama, $na, $us]) {
            $x = Siswa::factory()->create(['nama' => $nama]);
            $x->kelas()->attach($k->id, ['status' => 'aktif']);
            if ($na !== null) {
                $this->nilai($x, $k, $this->mtk, $na);
                \App\Models\NilaiUs::create(['siswa_id' => $x->id, 'mapel_id' => $this->mtk->id, 'nilai' => $us, 'masuk_xii2' => false]);
            }
            $s[$i] = $x;
        }

        return [$a, $b, $s];
    }

    public function test_peringkat_ujian_sekolah_xii_memakai_rata_rata_transkrip_dan_melewati_siswa_tanpa_nilai(): void
    {
        [$a, $b] = $this->kelasXii();
        $layanan = app(LayananStatistik::class);
        $siswa = $layanan->siswaUs($this->kelas->tahunAjaran);

        // Rata-rata transkrip = rata-rata semester rapor (slot terisi) per mapel: Matematika hanya 1 semester, jadi NA itu sendiri.
        $this->assertSame(['Citra', 'Budi', 'Andi'], $siswa->pluck('nama')->all());
        $this->assertSame([95.0, 90.0, 80.0], $siswa->pluck('rata')->all());
        $this->assertNotContains('Dewi', $siswa->pluck('nama')->all());
        $this->assertSame([1, 2], $layanan->perKelas($siswa)[$a->id]->pluck('peringkat')->all());
        $this->assertSame(['XII'], $layanan->perTingkat($siswa)->keys()->all());
    }

    public function test_halaman_dan_cetak_peringkat_ujian_sekolah(): void
    {
        [$a, $b] = $this->kelasXii();
        $tahun = $this->kelas->tahunAjaran;
        $this->actingAs($this->admin, 'web');

        $this->get("/staf/statistik?jenis=us&tahun={$tahun->id}")->assertOk()
            ->assertSee('Ujian Sekolah kelas XII')->assertSee('Peringkat XII A')->assertSee('Peringkat XII B')->assertSee('Juara Paralel Kelas XII')
            ->assertDontSee('Rata-rata per Mata Pelajaran')->assertDontSee('Peringkat X A');
        $this->get("/staf/statistik?jenis=us&tahun={$tahun->id}&kelas={$b->id}")->assertOk()->assertSee('Peringkat XII B')->assertDontSee('Peringkat XII A');
        $this->get("/staf/statistik/cetak/{$a->id}?jenis=us&tahun={$tahun->id}")->assertOk()->assertSee('DAFTAR PERINGKAT SISWA')->assertSee('Ujian Sekolah')->assertSee('Budi');

        // Kelas X tidak bisa dicetak sebagai peringkat US.
        $this->get("/staf/statistik/cetak/{$this->kelas->id}?jenis=us&tahun={$tahun->id}")->assertNotFound();
    }
}