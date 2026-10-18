<?php

namespace Tests\Feature;

use App\Models\Jawaban;
use App\Models\Kelas;
use App\Models\Pengampu;
use App\Models\SesiUjian;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

/** Koreksi dan rekap dibatasi pada mapel DAN kelas yang diampu guru; kunci nilai per kelas. */
class KoreksiPerKelasTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private User $guruA;

    private User $guruB;

    private Kelas $kelasB;

    /** @var list<Siswa> */
    private array $siswaB = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(denganEsai: true, jumlahSiswa: 2); // kelas X A: dua siswa
        $this->kelasB = Kelas::create(['tahun_ajaran_id' => $this->kelas->tahun_ajaran_id, 'tingkat' => 'X', 'nama' => 'X B']);
        $this->jadwal->kelas()->attach($this->kelasB->id);
        $this->siswaB = Siswa::factory()->count(2)->create()->each(function (Siswa $s) {
            $s->kelas()->attach($this->kelasB->id, ['status' => 'aktif']);
            SesiUjian::create(['jadwal_id' => $this->jadwal->id, 'siswa_id' => $s->id, 'status' => SesiUjian::BELUM]);
        })->all();

        $this->guruA = User::factory()->create();
        $this->guruB = User::factory()->create();
        Pengampu::create(['guru_id' => $this->guruA->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->kelas->id]);
        Pengampu::create(['guru_id' => $this->guruB->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->kelasB->id]);
    }

    private function selesaikan(Siswa $siswa, string $esai): SesiUjian
    {
        $token = $this->mulaiSesi($siswa);
        $sesi = $this->sesiDari($siswa);
        $jawaban = $this->jawabanBenar() + [$this->soal['esai']->id => ['t' => $esai, 'w' => now()->getTimestampMs()]];
        $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => $jawaban], $this->header($token))->assertOk();

        return $sesi->fresh();
    }

    private function jawaban(SesiUjian $s): Jawaban
    {
        return Jawaban::where('sesi_id', $s->id)->where('soal_id', $this->soal['esai']->id)->firstOrFail();
    }

    private function urlSoal(string $akhir = ''): string
    {
        return "/staf/koreksi/{$this->jadwal->id}/soal/{$this->soal['esai']->id}{$akhir}";
    }

    public function test_guru_hanya_melihat_jawaban_siswa_kelasnya_lengkap_dengan_nama(): void
    {
        $a = $this->selesaikan($this->siswa[0], 'Jawaban dari kelas A');
        $b = $this->selesaikan($this->siswaB[0], 'Jawaban dari kelas B');

        $html = $this->actingAs($this->guruA, 'web')->get($this->urlSoal())->assertOk()->getContent();
        $this->assertStringContainsString('Jawaban dari kelas A', $html);
        $this->assertStringContainsString($this->siswa[0]->nama, $html);
        $this->assertStringNotContainsString('Jawaban dari kelas B', $html);
        $this->assertStringNotContainsString($this->siswaB[0]->nama, $html);
        $this->assertStringNotContainsString('Jawaban 1', $html);

        $this->actingAs($this->guruB, 'web')->get($this->urlSoal())->assertOk()->assertSee('Jawaban dari kelas B')->assertDontSee('Jawaban dari kelas A');

        // Menilai jawaban kelas lain ditolak.
        $this->actingAs($this->guruA, 'web')->postJson("/staf/koreksi/{$this->jadwal->id}/jawaban/{$this->jawaban($b)->id}/nilai", ['nilai' => 50])->assertNotFound();
        $this->assertNull($this->jawaban($b)->nilai_esai);
        $this->actingAs($this->guruA, 'web')->postJson("/staf/koreksi/{$this->jadwal->id}/jawaban/{$this->jawaban($a)->id}/nilai", ['nilai' => 50])->assertOk();

        // Admin melihat semuanya.
        $this->actingAs($this->admin, 'web')->get($this->urlSoal())->assertOk()->assertSee('Jawaban dari kelas A')->assertSee('Jawaban dari kelas B');
    }

    public function test_guru_tidak_bisa_membuka_kelas_yang_tidak_diampu_lewat_parameter(): void
    {
        $this->actingAs($this->guruA, 'web')->get($this->urlSoal("?kelas={$this->kelasB->id}"))->assertNotFound();
        $this->actingAs($this->guruA, 'web')->get("/staf/rekap/{$this->jadwal->id}?kelas={$this->kelasB->id}")->assertNotFound();
        $this->actingAs($this->guruA, 'web')->get("/staf/rekap/{$this->jadwal->id}?kelas={$this->kelas->id}")->assertOk();
    }

    public function test_halaman_pilihan_menampilkan_pasangan_mapel_dan_kelas_yang_diampu(): void
    {
        $this->actingAs($this->guruA, 'web')->get('/staf/koreksi')->assertOk()->assertSee('Matematika · X A')->assertDontSee('X B');
        $this->actingAs($this->guruA, 'web')->get('/staf/rekap')->assertOk()->assertSee('Matematika · X A')->assertDontSee('X B');
        $this->actingAs($this->admin, 'web')->get('/staf/koreksi')->assertOk()->assertSee('Matematika · X A')->assertSee('Matematika · X B');

        // Guru yang mengampu mapel lain tidak melihat apa pun.
        $lain = User::factory()->create();
        $this->actingAs($lain, 'web')->get('/staf/koreksi')->assertOk()->assertDontSee('Matematika');
    }

    public function test_semester_bawaan_adalah_yang_aktif(): void
    {
        $periode = \App\Models\Periode::create(['tahun_ajaran_id' => $this->kelas->tahun_ajaran_id, 'semester' => 'genap', 'tipe' => 'reguler', 'aktif' => false]);
        $this->jadwal->update(['periode_id' => $periode->id]);

        $this->actingAs($this->admin, 'web')->get('/staf/koreksi')->assertOk()->assertDontSee('Matematika · X A');
        $this->get("/staf/koreksi?periode={$periode->id}")->assertOk()->assertSee('Matematika · X A');
        $this->get('/staf/koreksi?periode=semua')->assertOk()->assertSee('Matematika · X A');
    }

    public function test_nilai_yang_sama_untuk_semua_jawaban_hanya_di_kelas_guru(): void
    {
        $a0 = $this->selesaikan($this->siswa[0], 'A nol');
        $a1 = $this->selesaikan($this->siswa[1], 'A satu');
        $b0 = $this->selesaikan($this->siswaB[0], 'B nol');
        $this->actingAs($this->guruA, 'web')->postJson("/staf/koreksi/{$this->jadwal->id}/jawaban/{$this->jawaban($a1)->id}/nilai", ['nilai' => 60])->assertOk();

        $this->post($this->urlSoal('/nilai-semua'), ['nilai' => '80,5'])->assertSessionHas('berhasil');
        $this->assertSame('80.50', $this->jawaban($a0)->nilai_esai);
        $this->assertSame('60.00', $this->jawaban($a1)->nilai_esai); // sudah dinilai tidak ditimpa
        $this->assertNull($this->jawaban($b0)->nilai_esai);          // kelas lain tidak tersentuh

        $this->post($this->urlSoal('/nilai-semua'), ['nilai' => 90, 'timpa' => '1'])->assertSessionHas('berhasil');
        $this->assertSame('90.00', $this->jawaban($a1)->nilai_esai);
        $this->assertNull($this->jawaban($b0)->nilai_esai);

        $this->post($this->urlSoal('/nilai-semua'), ['nilai' => 150])->assertSessionHas('gagal');
        $this->post($this->urlSoal('/nilai-semua'), ['nilai' => 'abc'])->assertSessionHas('gagal');
        $this->assertSame('90.00', $this->jawaban($a0)->nilai_esai);

        $this->actingAs($this->guruB, 'web')->post($this->urlSoal('/nilai-semua'), ['nilai' => 70, 'kelas' => $this->kelas->id])->assertNotFound();
        $this->assertNull($this->jawaban($b0)->nilai_esai);
        $this->assertDatabaseHas('audit_log', ['aksi' => 'koreksi.nilai_semua']);
    }

    public function test_nilai_semua_menghitung_ulang_skor_akhir(): void
    {
        $a0 = $this->selesaikan($this->siswa[0], 'A nol');
        $this->actingAs($this->guruA, 'web')->post($this->urlSoal('/nilai-semua'), ['nilai' => 100])->assertSessionHas('berhasil');

        $this->assertSame('100.00', $a0->fresh()->hasil->skor_akhir);
    }

    public function test_kunci_dan_buka_kunci_berlaku_per_kelas(): void
    {
        $a0 = $this->selesaikan($this->siswa[0], 'A nol');
        $this->selesaikan($this->siswa[1], 'A satu');
        $this->actingAs($this->guruA, 'web')->post($this->urlSoal('/nilai-semua'), ['nilai' => 80]);

        // Kelas B belum selesai/dikoreksi, tetapi guru A tetap bisa mengunci kelasnya sendiri.
        $this->post("/staf/rekap/{$this->jadwal->id}/kunci", ['kelas' => $this->kelas->id])->assertSessionHas('berhasil');
        $this->assertTrue($this->jadwal->fresh()->nilaiDikunci([$this->kelas->id]));
        $this->assertFalse($this->jadwal->fresh()->nilaiDikunci([$this->kelasB->id]));
        $this->assertFalse($this->jadwal->fresh()->nilaiDikunci());

        // Guru B tidak bisa menyentuh kunci kelas A.
        $this->actingAs($this->guruB, 'web')->post("/staf/rekap/{$this->jadwal->id}/buka-kunci", ['kelas' => $this->kelas->id])->assertNotFound();
        $this->assertTrue($this->jadwal->fresh()->nilaiDikunci([$this->kelas->id]));

        // Kelas B: baru bisa dikunci setelah semua esainya dikoreksi.
        $this->actingAs($this->guruB, 'web');
        $this->selesaikan($this->siswaB[0], 'B nol');
        $this->selesaikan($this->siswaB[1], 'B satu');
        $this->post("/staf/rekap/{$this->jadwal->id}/kunci", ['kelas' => $this->kelasB->id])->assertSessionHas('gagal');
        $this->post($this->urlSoal('/nilai-semua'), ['nilai' => 70]);
        $this->post("/staf/rekap/{$this->jadwal->id}/kunci", ['kelas' => $this->kelasB->id])->assertSessionHas('berhasil');
        $this->assertTrue($this->jadwal->fresh()->nilaiDikunci());

        // Guru A merevisi nilai kelasnya: hanya kunci kelas A yang terbuka.
        $this->actingAs($this->guruA, 'web')->post($this->urlSoal('/nilai-semua'), ['nilai' => 85, 'timpa' => '1'])->assertSessionHas('berhasil');
        $this->assertFalse($this->jadwal->fresh()->nilaiDikunci([$this->kelas->id]));
        $this->assertTrue($this->jadwal->fresh()->nilaiDikunci([$this->kelasB->id]));
    }

    public function test_rekap_hanya_memuat_siswa_kelas_yang_dipilih(): void
    {
        $this->selesaikan($this->siswa[0], 'A nol');
        $this->selesaikan($this->siswaB[0], 'B nol');

        $d = app(\App\Services\LayananRekap::class)->data($this->jadwal->fresh()->load('mapel'), [$this->kelas->id]);
        $this->assertSame(2, $d['ringkasan']['peserta']);
        $this->assertEqualsCanonicalizing([$this->siswa[0]->nama, $this->siswa[1]->nama], collect($d['baris'])->pluck('nama')->all());

        $semua = app(\App\Services\LayananRekap::class)->data($this->jadwal->fresh()->load('mapel'));
        $this->assertSame(4, $semua['ringkasan']['peserta']);
    }
}
