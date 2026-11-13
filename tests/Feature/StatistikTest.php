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
}
