<?php

namespace Tests\Feature;

use App\Models\NilaiRapor;
use App\Models\Pengampu;
use App\Models\Periode;
use App\Models\SkalaPredikat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class NilaiLampauTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private Periode $periode;

    private User $guru;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 3);
        foreach ([[90, 'A'], [80, 'B'], [70, 'C'], [0, 'D']] as [$min, $p]) {
            SkalaPredikat::create(['nilai_min' => $min, 'predikat' => $p]);
        }
        $this->periode = $this->jadwal->periode;
        $this->periode->update(['rapor_di_sini' => false]);
        $this->guru = User::factory()->create();
        Pengampu::create(['guru_id' => $this->guru->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->kelas->id]);
    }

    private function url(): string
    {
        return "/staf/nilai-lampau?periode={$this->periode->id}&kelas={$this->kelas->id}&mapel={$this->mtk->id}";
    }

    private function kirim(array $nilai): \Illuminate\Testing\TestResponse
    {
        return $this->post('/staf/nilai-lampau', ['periode' => $this->periode->id, 'kelas' => $this->kelas->id, 'mapel' => $this->mtk->id, 'nilai' => $nilai]);
    }

    public function test_halaman_menampilkan_siswa_untuk_semester_lampau(): void
    {
        $this->actingAs($this->guru, 'web')->get($this->url())->assertOk()
            ->assertSee('Nilai Lampau')->assertSee($this->siswa[0]->nama)->assertSee('Matematika');
    }

    public function test_simpan_menyimpan_np_nk_na_predikat_dan_membekukan(): void
    {
        $this->actingAs($this->guru, 'web');
        $this->kirim([$this->siswa[0]->id => ['np' => '90', 'nk' => '80'], $this->siswa[1]->id => ['np' => '82,5', 'nk' => ''], $this->siswa[2]->id => ['np' => '', 'nk' => '']])
            ->assertSessionHas('berhasil');

        $n = NilaiRapor::where('siswa_id', $this->siswa[0]->id)->first();
        $this->assertSame([90, 80, 85, app(\App\Services\LayananRapor::class)->predikat(85), 'final'], [$n->np, $n->nk, $n->na, $n->predikat, $n->status]);
        $this->assertTrue($n->snapshot['lampau']);

        $m = NilaiRapor::where('siswa_id', $this->siswa[1]->id)->first();
        $this->assertSame([83, 0, 83, app(\App\Services\LayananRapor::class)->predikat(83)], [$m->np, $m->nk, $m->na, $m->predikat]); // NK kosong: NA = NP
        $this->assertNull(NilaiRapor::where('siswa_id', $this->siswa[2]->id)->first());

        $this->get($this->url())->assertSee('85');
    }

    public function test_input_salah_dilewati_dan_nilai_lama_dapat_diubah(): void
    {
        $this->actingAs($this->guru, 'web');
        $this->kirim([$this->siswa[0]->id => ['np' => '90', 'nk' => '90']]);
        $this->kirim([$this->siswa[0]->id => ['np' => '70', 'nk' => '70'], $this->siswa[1]->id => ['np' => 'abc', 'nk' => ''], $this->siswa[2]->id => ['np' => '101', 'nk' => '']])
            ->assertSessionHas('catatan');

        $this->assertSame(70, NilaiRapor::where('siswa_id', $this->siswa[0]->id)->first()->na);
        $this->assertSame(1, NilaiRapor::where('siswa_id', $this->siswa[0]->id)->count());
        $this->assertNull(NilaiRapor::where('siswa_id', $this->siswa[1]->id)->first());
        $this->assertNull(NilaiRapor::where('siswa_id', $this->siswa[2]->id)->first());
    }

    public function test_semester_tahun_lain_diisi_untuk_kelas_saat_ini_dengan_nama_kelas_lama(): void
    {
        $lama = \App\Models\TahunAjaran::create(['nama' => '2020/2021', 'aktif' => false]);
        $sem = Periode::create(['tahun_ajaran_id' => $lama->id, 'semester' => 'ganjil', 'tipe' => 'reguler', 'aktif' => false, 'rapor_di_sini' => false]);
        $this->actingAs($this->guru, 'web');

        $url = "/staf/nilai-lampau?periode={$sem->id}&kelas={$this->kelas->id}&mapel={$this->mtk->id}";
        $this->get($url)->assertOk()->assertSee($this->siswa[0]->nama)->assertSee('Nama kelas pada semester itu');

        $this->post('/staf/nilai-lampau', ['periode' => $sem->id, 'kelas' => $this->kelas->id, 'mapel' => $this->mtk->id, 'kelas_lama' => 'VII A',
            'nilai' => [$this->siswa[0]->id => ['np' => '88', 'nk' => '86']]])->assertSessionHas('berhasil');

        $n = NilaiRapor::where('siswa_id', $this->siswa[0]->id)->where('periode_id', $sem->id)->first();
        $this->assertSame([88, 86, 'VII A', $this->kelas->id], [$n->np, $n->nk, $n->snapshot['kelas_nama'], $n->kelas_id]);
        $this->get($url)->assertSee('value="VII A"', false);
    }

    public function test_semester_yang_rapornya_diisi_di_sini_ditolak(): void
    {
        $this->periode->update(['rapor_di_sini' => true]);
        $this->actingAs($this->guru, 'web');

        $this->kirim([$this->siswa[0]->id => ['np' => '90', 'nk' => '90']])->assertSessionHas('gagal');
        $this->assertSame(0, NilaiRapor::count());
    }

    public function test_hak_akses_guru_hanya_mapel_yang_diampu_dan_admin_semua(): void
    {
        $lain = User::factory()->create();
        $this->actingAs($lain, 'web')->get($this->url())->assertOk()->assertDontSee($this->siswa[0]->nama);
        $this->kirim([$this->siswa[0]->id => ['np' => '90', 'nk' => '90']])->assertNotFound();

        $this->actingAs($this->admin, 'web')->get($this->url())->assertOk()->assertSee($this->siswa[0]->nama);
        $this->kirim([$this->siswa[0]->id => ['np' => '88', 'nk' => '88']])->assertSessionHas('berhasil');

        $pengawas = User::factory()->pengawas()->create();
        $this->actingAs($pengawas, 'web')->get($this->url())->assertForbidden();
    }

    public function test_siswa_di_luar_kelas_diabaikan(): void
    {
        $this->actingAs($this->guru, 'web');
        $this->kirim([999999 => ['np' => '90', 'nk' => '90']])->assertSessionHas('berhasil');
        $this->assertSame(0, NilaiRapor::count());
    }
}
