<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Jawaban;
use App\Models\Pengampu;
use App\Models\SesiUjian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class RekapNilaiTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private User $guru;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(['tampilkan_hasil' => 'ditahan'], denganEsai: true, jumlahSiswa: 3);
        $this->guru = User::factory()->create();
        Pengampu::create(['guru_id' => $this->guru->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->kelas->id]);
    }

    private function selesaikan(int $i, ?string $esai, bool $benar = true): SesiUjian
    {
        $token = $this->mulaiSesi($this->siswa[$i]);
        $sesi = $this->sesiDari($this->siswa[$i]);
        $jawaban = $benar ? $this->jawabanBenar() : [];
        if ($esai !== null) {
            $jawaban[$this->soal['esai']->id] = ['t' => $esai, 'w' => now()->getTimestampMs()];
        }
        $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => $jawaban], $this->header($token))->assertOk();

        return $sesi->fresh();
    }

    private function nilaiEsai(SesiUjian $sesi, float $nilai): void
    {
        $j = Jawaban::where('sesi_id', $sesi->id)->where('soal_id', $this->soal['esai']->id)->firstOrFail();
        $this->actingAs($this->guru, 'web')->postJson("/staf/koreksi/{$this->jadwal->id}/jawaban/{$j->id}/nilai", ['nilai' => $nilai])->assertOk();
    }

    public function test_akses_admin_guru_pengampu_dan_larangan_lainnya(): void
    {
        $guruLain = User::factory()->create();
        $pengawas = User::factory()->pengawas()->create();
        $url = "/staf/rekap/{$this->jadwal->id}";

        $this->actingAs($this->admin, 'web')->get($url)->assertOk();
        $this->actingAs($this->guru, 'web')->get($url)->assertOk();
        $this->actingAs($guruLain, 'web')->get($url)->assertNotFound();
        $this->actingAs($guruLain, 'web')->get("{$url}/ekspor")->assertNotFound();
        $this->actingAs($guruLain, 'web')->post("{$url}/kunci")->assertNotFound();
        $this->actingAs($pengawas, 'web')->get($url)->assertForbidden();
    }

    public function test_rekap_menghitung_ringkasan(): void
    {
        $a = $this->selesaikan(0, 'Jawaban A');
        $b = $this->selesaikan(1, 'Jawaban B', benar: false);
        $this->nilaiEsai($a, 100);   // akhir 100
        $this->nilaiEsai($b, 50);    // PG 0 x 70% + 50 x 30% = 15
        // siswa 2: belum mulai

        $d = app(\App\Services\LayananRekap::class)->data($this->jadwal->fresh()->load('mapel'));

        $this->assertSame(3, $d['ringkasan']['peserta']);
        $this->assertSame(2, $d['ringkasan']['selesai']);
        $this->assertSame(2, $d['ringkasan']['dinilai']);
        $this->assertSame(57.5, $d['ringkasan']['rata_rata']);
        $this->assertSame(100.0, $d['ringkasan']['tertinggi']);
        $this->assertSame(15.0, $d['ringkasan']['terendah']);
        $this->assertSame(1, $d['ringkasan']['di_bawah_kkm']);
        $ket = collect($d['baris'])->pluck('keterangan')->sort()->values()->all();
        $this->assertSame(['Belum mulai', 'Belum tuntas', 'Tuntas'], $ket);
    }

    public function test_siswa_yang_tidak_mengikuti_ditandai_dan_tidak_dinolkan(): void
    {
        $this->jadwal->update(['status' => 'selesai']);

        $d = app(\App\Services\LayananRekap::class)->data($this->jadwal->fresh()->load('mapel'));

        $this->assertSame(3, $d['ringkasan']['tidak_mengikuti']);
        $this->assertNull($d['baris'][0]['skor_akhir']);
        $this->assertSame(0, $d['ringkasan']['dinilai']);
    }

    public function test_halaman_rekap_dan_cetak_tampil(): void
    {
        $this->nilaiEsai($this->selesaikan(0, 'Jawaban A'), 80);

        $this->actingAs($this->guru, 'web')->get("/staf/rekap/{$this->jadwal->id}")->assertOk()->assertSee('94')->assertSee('Tuntas');
        $this->actingAs($this->guru, 'web')->get("/staf/rekap/{$this->jadwal->id}/cetak")->assertOk()->assertSee('Rekap nilai: UTS Matematika')->assertSee('KKM 75');
    }

    public function test_ekspor_excel_aman_dari_rumus(): void
    {
        $this->siswa[0]->update(['nama' => '=HYPERLINK("http://x","klik")']);
        $this->nilaiEsai($this->selesaikan(0, 'Jawaban A'), 80);

        $r = $this->actingAs($this->guru, 'web')->get("/staf/rekap/{$this->jadwal->id}/ekspor")->assertOk();
        $this->assertStringContainsString('spreadsheetml', $r->headers->get('Content-Type'));

        $tmp = tempnam(sys_get_temp_dir(), 'rk').'.xlsx';
        file_put_contents($tmp, $r->streamedContent());
        $lembar = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);

        $this->assertSame('Rekap nilai: UTS Matematika', $lembar->getCell('A1')->getValue());
        $cari = collect(range(5, 7))->map(fn ($n) => $lembar->getCell("B{$n}"))->first(fn ($c) => str_contains((string) $c->getValue(), 'HYPERLINK'));
        $this->assertNotNull($cari);
        $this->assertSame('s', $cari->getDataType());
        $this->assertSame(94.0, (float) collect(range(5, 7))->map(fn ($n) => $lembar->getCell("L{$n}")->getValue())->first(fn ($v) => $v !== null));
    }

    public function test_kunci_ditolak_bila_ada_yang_sedang_mengerjakan_atau_esai_belum_dikoreksi(): void
    {
        $this->mulaiSesi($this->siswa[0]); // sedang mengerjakan
        $this->actingAs($this->guru, 'web')->post("/staf/rekap/{$this->jadwal->id}/kunci")->assertSessionHas('gagal');
        $this->assertFalse($this->jadwal->fresh()->nilaiDikunci());

        app(\App\Services\LayananSesi::class)->selesaikan($this->sesiDari($this->siswa[0]), 'kirim');
        $this->selesaikan(1, 'Perlu koreksi');
        $this->post("/staf/rekap/{$this->jadwal->id}/kunci")->assertSessionHas('gagal');
        $this->assertFalse($this->jadwal->fresh()->nilaiDikunci());
    }

    public function test_kunci_berhasil_lalu_mengubah_nilai_membuka_kunci_dan_guru_bisa_membuka_sendiri(): void
    {
        $sesi = $this->selesaikan(0, 'Jawaban A');
        $this->nilaiEsai($sesi, 80);

        $this->actingAs($this->guru, 'web')->post("/staf/rekap/{$this->jadwal->id}/kunci")->assertSessionHas('berhasil');
        $j = $this->jadwal->fresh();
        $this->assertTrue($j->nilaiDikunci());
        $this->assertSame($this->guru->id, $j->kunciNilai()->first()->dikunci_oleh);
        $this->assertSame(1, AuditLog::where('aksi', 'rekap.kunci')->count());

        $esai = Jawaban::where('sesi_id', $sesi->id)->where('soal_id', $this->soal['esai']->id)->first();

        $this->post("/staf/rekap/{$this->jadwal->id}/kunci")->assertSessionHas('gagal'); // sudah dikunci

        // Guru membuka kunci sendiri (tercatat), tanpa admin.
        $this->post("/staf/rekap/{$this->jadwal->id}/buka-kunci")->assertSessionHas('berhasil');
        $this->assertFalse($this->jadwal->fresh()->nilaiDikunci());
        $this->assertSame(1, AuditLog::where('aksi', 'rekap.buka_kunci')->count());
        $this->post("/staf/rekap/{$this->jadwal->id}/buka-kunci")->assertSessionHas('gagal'); // tidak sedang dikunci

        // Kunci lagi, lalu mengubah nilai membuka kunci otomatis.
        $this->post("/staf/rekap/{$this->jadwal->id}/kunci")->assertSessionHas('berhasil');
        $this->postJson("/staf/koreksi/{$this->jadwal->id}/jawaban/{$esai->id}/nilai", ['nilai' => 10])
            ->assertOk()->assertJsonPath('kunci_dibuka', true);
        $this->assertSame('10.00', $esai->fresh()->nilai_esai);
        $this->assertFalse($this->jadwal->fresh()->nilaiDikunci());
        $this->assertSame(1, AuditLog::where('aksi', 'rekap.buka_kunci_otomatis')->count());

        $this->postJson("/staf/koreksi/{$this->jadwal->id}/jawaban/{$esai->id}/nilai", ['nilai' => 20])->assertOk()->assertJsonPath('kunci_dibuka', false);
        $this->post("/staf/rekap/{$this->jadwal->id}/kunci")->assertSessionHas('berhasil'); // dikunci lagi
        $this->assertTrue($this->jadwal->fresh()->nilaiDikunci());
    }

    public function test_hasil_ditahan_baru_tampil_ke_siswa_setelah_ditampilkan(): void
    {
        $sesi = $this->selesaikan(0, 'Jawaban A');
        $this->nilaiEsai($sesi, 80);

        $this->actingAs($this->siswa[0], 'siswa')->get(route('siswa.ujian.hasil', $this->jadwal))->assertOk()
            ->assertSee('Nilai akan diumumkan oleh guru')->assertDontSee('Nilai akhir');

        $this->actingAs($this->guru, 'web')->post("/staf/rekap/{$this->jadwal->id}/tampilkan")->assertSessionHas('berhasil');
        $this->assertSame('langsung', $this->jadwal->fresh()->tampilkan_hasil);
        $this->post("/staf/rekap/{$this->jadwal->id}/tampilkan")->assertSessionHas('gagal');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->siswa[0], 'siswa')->get(route('siswa.ujian.hasil', $this->jadwal))->assertOk()
            ->assertSee('Nilai akhir')->assertSee('94');
    }

    public function test_siswa_tidak_bisa_memakai_rekap(): void
    {
        $this->actingAs($this->siswa[0], 'siswa')->post("/staf/rekap/{$this->jadwal->id}/tampilkan")->assertRedirect();
        $this->assertSame('ditahan', $this->jadwal->fresh()->tampilkan_hasil);
    }
}
