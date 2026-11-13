<?php

namespace Tests\Feature;

use App\Models\BacaanSoal;
use App\Models\BankSoal;
use App\Models\Mapel;
use App\Models\Soal;
use App\Models\User;
use App\Services\LayananPaket;
use App\Services\LayananSoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as KlienHttp;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class BacaanSoalTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private BankSoal $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 1);
        $this->bank = BankSoal::first();
    }

    private function dataSoal(array $timpa = []): array
    {
        return array_replace(['tipe' => 'pg', 'pertanyaan' => 'Apa maksud paragraf kedua?', 'bobot' => '1', 'status' => 'siap', 'jumlah_opsi' => '4',
            'opsi' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'], 'kunci' => 'A'], $timpa);
    }

    // ── Kelola manual ───────────────────────────────────────────────────────

    public function test_bacaan_ditulis_sekali_dan_dipakai_beberapa_soal_dan_hanya_oleh_pengelola(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->get("/staf/bank-soal/{$this->bank->id}/bacaan/tambah")->assertOk()->assertSee('Teks bacaan');
        $this->post("/staf/bank-soal/{$this->bank->id}/bacaan", ['judul' => 'Pak Tani', 'teks' => 'Pak Tani menanam padi setiap musim hujan.'])->assertSessionHas('berhasil');
        $this->post("/staf/bank-soal/{$this->bank->id}/bacaan", ['judul' => '', 'teks' => ''])->assertSessionHasErrors('teks');

        $b = BacaanSoal::first();
        $this->assertSame(['Pak Tani', 'manual'], [$b->judul, $b->sumber]);

        foreach (['Apa pekerjaan Pak Tani?', 'Kapan Pak Tani menanam padi?'] as $q) {
            $this->post("/staf/bank-soal/{$this->bank->id}/soal", $this->dataSoal(['pertanyaan' => $q, 'bacaan_id' => $b->id]))->assertSessionHasNoErrors();
        }
        $this->assertSame(2, $b->soal()->count());

        $this->get("/staf/bank-soal/{$this->bank->id}")->assertOk()->assertSee('Bacaan / Stimulus')->assertSee('Pak Tani')->assertSee('2 soal')->assertSee('Bacaan: Pak Tani');
        $this->get("/staf/bank-soal/{$this->bank->id}/soal/tambah?tipe=pg&bacaan={$b->id}")->assertOk()->assertSee('value="'.$b->id.'" selected', false);

        // Mengubah bacaan memperbarui semua soal yang memakainya; guru lain tidak boleh mengelola.
        $this->put("/staf/bank-soal/{$this->bank->id}/bacaan/{$b->id}", ['judul' => 'Pak Tani', 'teks' => 'Teks baru.'])->assertSessionHas('berhasil');
        $this->assertSame('Teks baru.', $b->fresh()->teks);
        $lain = User::factory()->create();
        $this->actingAs($lain, 'web')->get("/staf/bank-soal/{$this->bank->id}/bacaan/tambah")->assertForbidden();
        $this->delete("/staf/bank-soal/{$this->bank->id}/bacaan/{$b->id}")->assertForbidden();

        // Menghapus bacaan: soal tetap ada tanpa bacaan.
        $this->actingAs($this->admin, 'web')->delete("/staf/bank-soal/{$this->bank->id}/bacaan/{$b->id}")->assertSessionHas('berhasil', fn ($m) => str_contains($m, '2 soal'));
        $this->assertSame(0, BacaanSoal::count());
        $this->assertSame(2, Soal::whereNull('bacaan_id')->where('pertanyaan', 'like', '%Pak Tani%')->count());
    }

    public function test_soal_tidak_boleh_memakai_bacaan_bank_lain(): void
    {
        $this->actingAs($this->admin, 'web');
        $lain = BankSoal::create(['mapel_id' => $this->mtk->id, 'tingkat' => 'X', 'nama' => 'Bank Lain', 'jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $this->admin->id]);
        $asing = $lain->bacaan()->create(['teks' => 'Bacaan bank lain']);

        $this->post("/staf/bank-soal/{$this->bank->id}/soal", $this->dataSoal(['bacaan_id' => $asing->id]))->assertSessionHasErrors('bacaan_id');
        $this->get("/staf/bank-soal/{$this->bank->id}/bacaan/{$asing->id}/ubah")->assertNotFound();
    }

    public function test_salin_bank_menyalin_bacaan_dan_memetakan_ulang_soalnya(): void
    {
        $b = $this->bank->bacaan()->create(['judul' => 'Cerita', 'teks' => 'Isi cerita.']);
        $this->soal['s1']->update(['bacaan_id' => $b->id]);
        $this->soal['s2']->update(['bacaan_id' => $b->id]);

        $baru = app(LayananSoal::class)->salinBank($this->bank, $this->admin, 'Salinan');

        $salinan = $baru->bacaan()->get();
        $this->assertCount(1, $salinan);
        $this->assertNotSame($b->id, $salinan[0]->id);
        $this->assertSame(2, $baru->soal()->where('bacaan_id', $salinan[0]->id)->count());
        $this->assertSame(2, $b->soal()->count()); // bank asal tidak berubah
    }

    // ── Pelaksanaan ujian ───────────────────────────────────────────────────

    public function test_paket_memuat_bacaan_dan_soal_satu_bacaan_tetap_berdampingan_saat_diacak(): void
    {
        $b = $this->bank->bacaan()->create(['judul' => 'Cerita', 'teks' => 'Isi cerita.']);
        $this->soal['s1']->update(['bacaan_id' => $b->id]);
        $this->soal['s2']->update(['bacaan_id' => $b->id]);
        $tambahan = [];
        foreach (range(1, 6) as $i) {
            $s = $this->bank->soal()->create(['tipe' => 'pg', 'pertanyaan' => "Soal lepas {$i}", 'bobot' => 1, 'status' => 'siap', 'sumber' => 'manual', 'urutan' => 10 + $i]);
            app(LayananSoal::class)->setOpsi($s, ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'], 'A');
            $tambahan[] = $s->id;
        }
        // Bekukan ulang agar soal tambahan ikut jadwal.
        $this->jadwal->soalBeku()->delete();
        foreach ($this->bank->soal()->get() as $i => $s) {
            $this->jadwal->soalBeku()->create(['soal_id' => $s->id, 'urutan' => $i + 1, 'bobot' => 1]);
        }
        $layanan = app(LayananPaket::class);
        $layanan->lupakan($this->jadwal);
        $this->jadwal->update(['acak_soal' => true]);

        $paket = collect($layanan->paket($this->jadwal)['soal'])->keyBy('id');
        $this->assertSame(['id' => $b->id, 'judul' => 'Cerita', 'teks' => 'Isi cerita.'], $paket[$this->soal['s1']->id]['bacaan']);
        $this->assertNull($paket[$tambahan[0]]['bacaan']);

        for ($k = 0; $k < 25; $k++) {
            $urut = $layanan->susunan($this->jadwal->fresh())['soal'];
            $p1 = array_search($this->soal['s1']->id, $urut, true);
            $p2 = array_search($this->soal['s2']->id, $urut, true);
            $this->assertSame(1, abs($p1 - $p2), 'Dua soal satu bacaan harus berdampingan: '.implode(',', $urut));
        }
    }

    // ── Penyusun otomatis ───────────────────────────────────────────────────

    public function test_penyusun_otomatis_membuat_satu_bacaan_untuk_beberapa_soal(): void
    {
        config(['ujian.otomatis' => ['driver' => 'gemini', 'model' => 'm', 'kunci' => 'kunci-rahasia-ABCD1234']]);
        $json = json_encode([
            'bacaan' => [['kode' => 'B1', 'judul' => 'Cerita Pak Tani', 'teks' => 'Pak Tani menanam padi.'], ['kode' => 'B2', 'judul' => null, 'teks' => 'Harga cabai naik.']],
            'soal' => [
                ['tipe' => 'pg', 'bacaan' => 'B1', 'pertanyaan' => 'Apa yang ditanam?', 'opsi' => ['padi', 'jagung', 'tebu', 'kopi'], 'kunci' => 'A'],
                ['tipe' => 'pg', 'bacaan' => 'B1', 'pertanyaan' => 'Siapa yang menanam?', 'opsi' => ['Pak Tani', 'Bu Guru', 'Pak RT', 'Bu Lurah'], 'kunci' => 'A'],
                ['tipe' => 'pg', 'bacaan' => 'B2', 'pertanyaan' => 'Apa yang naik?', 'opsi' => ['cabai', 'beras', 'gula', 'minyak'], 'kunci' => 'A'],
                ['tipe' => 'pg', 'pertanyaan' => 'Soal tanpa bacaan.', 'opsi' => ['1', '2', '3', '4'], 'kunci' => 'B'],
            ],
        ]);
        Http::swap(new KlienHttp);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $json]]]]]])]);

        $this->actingAs($this->admin, 'web')->post("/staf/bank-soal/{$this->bank->id}/otomatis", [
            'topik' => 'Cerita', 'jumlah' => 4, 'tipe' => 'pg', 'jumlah_opsi' => 4, 'kesulitan' => 'sedang', 'soal_per_bacaan' => 2,
        ])->assertSessionHas('berhasil');

        $otomatis = Soal::where('sumber', 'otomatis')->get();
        $this->assertCount(4, $otomatis);
        $b1 = BacaanSoal::where('judul', 'Cerita Pak Tani')->first();
        $this->assertSame(['otomatis', 2], [$b1->sumber, $b1->soal()->count()]);
        $this->assertSame(2, BacaanSoal::count()); // satu bacaan per kode, bukan per soal
        $this->assertSame(1, $otomatis->whereNull('bacaan_id')->count());
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), 'Soal berbasis bacaan: buat 2 bacaan'));
    }

    public function test_penyusun_otomatis_tanpa_bacaan_tidak_berubah_dan_bacaan_tanpa_teks_dibuang(): void
    {
        config(['ujian.otomatis' => ['driver' => 'gemini', 'model' => 'm', 'kunci' => 'kunci-rahasia-ABCD1234']]);
        $json = json_encode(['soal' => [
            ['tipe' => 'pg', 'bacaan' => 'X9', 'pertanyaan' => 'Soal dengan kode tak dikenal.', 'opsi' => ['1', '2', '3', '4'], 'kunci' => 'A'],
        ]]);
        Http::swap(new KlienHttp);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $json]]]]]])]);

        $this->actingAs($this->admin, 'web')->post("/staf/bank-soal/{$this->bank->id}/otomatis", [
            'topik' => 'Cerita', 'jumlah' => 1, 'tipe' => 'pg', 'jumlah_opsi' => 4, 'kesulitan' => 'sedang', 'soal_per_bacaan' => 3,
        ])->assertSessionHas('berhasil');

        $this->assertSame(0, BacaanSoal::count());
        $this->assertNull(Soal::where('sumber', 'otomatis')->first()->bacaan_id);
    }

    // ── Impor Excel ─────────────────────────────────────────────────────────

    public function test_impor_excel_mengelompokkan_soal_berdasarkan_kode_bacaan(): void
    {
        $buku = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $lembar = $buku->getActiveSheet();
        $baris = [
            ['Tipe', 'Pertanyaan', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E', 'Kunci', 'Bobot', 'Jawaban Acuan', 'Kode Bacaan', 'Teks Bacaan'],
            ['PG', 'Soal 1 bacaan', 'a', 'b', 'c', 'd', null, 'A', null, null, 'B1', 'Teks bacaan satu.'],
            ['PG', 'Soal 2 bacaan', 'a', 'b', 'c', 'd', null, 'B', null, null, 'B1', null],
            ['Esai', 'Soal 3 lepas', null, null, null, null, null, null, null, 'acuan', null, null],
        ];
        foreach ($baris as $r => $isi) {
            foreach ($isi as $c => $v) {
                if ($v !== null) {
                    $lembar->setCellValueExplicit([$c + 1, $r + 1], (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }
        $jalur = tempnam(sys_get_temp_dir(), 'bac').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($buku))->save($jalur);
        $berkas = new \Illuminate\Http\UploadedFile($jalur, 'soal.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->admin, 'web');
        $r = $this->post("/staf/bank-soal/{$this->bank->id}/impor", ['berkas' => $berkas])->assertOk();
        $token = $r->viewData('token');
        $this->assertNotNull($token);
        $this->assertSame(0, BacaanSoal::count()); // pratinjau tidak menyimpan
        $this->post("/staf/bank-soal/{$this->bank->id}/impor/{$token}")->assertOk();

        $this->assertSame(1, BacaanSoal::count());
        $b = BacaanSoal::first();
        $this->assertSame(['Teks bacaan satu.', 'impor', 2], [$b->teks, $b->sumber, $b->soal()->count()]);
    }

    public function test_impor_menolak_kode_bacaan_tanpa_teks(): void
    {
        $buku = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $lembar = $buku->getActiveSheet();
        foreach ([['Tipe', 'Pertanyaan', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Kunci', 'Kode Bacaan'], ['PG', 'Soal', 'a', 'b', 'c', 'd', 'A', 'Z']] as $r => $isi) {
            foreach ($isi as $c => $v) {
                $lembar->setCellValueExplicit([$c + 1, $r + 1], (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        $jalur = tempnam(sys_get_temp_dir(), 'bac').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($buku))->save($jalur);

        $r = $this->actingAs($this->admin, 'web')->post("/staf/bank-soal/{$this->bank->id}/impor", ['berkas' => new \Illuminate\Http\UploadedFile($jalur, 's.xlsx', null, null, true)])->assertOk();
        $this->assertNull($r->viewData('token'));
        $this->assertStringContainsString('belum punya Teks Bacaan', json_encode($r->viewData('hasil')->galat ?? $r->getContent()));
    }
}
