<?php

namespace Tests\Feature;

use App\Models\BacaanSoal;
use App\Models\BankSoal;
use App\Models\OpsiSoal;
use App\Models\Soal;
use App\Models\User;
use App\Services\LayananGambarUjian;
use App\Services\LayananPaket;
use App\Services\LayananSoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class GambarOpsiTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private BankSoal $bank;

    private string $media;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->siapkanUjian(jumlahSiswa: 1);
        $this->bank = BankSoal::first();
        $this->media = sys_get_temp_dir().DIRECTORY_SEPARATOR.'media-uji-'.uniqid();
        config(['ujian.jalur_media' => $this->media, 'ujian.url_media' => 'http://uji.test/media/u']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->media);
        parent::tearDown();
    }

    private function data(array $timpa = []): array
    {
        return array_replace(['tipe' => 'pg', 'pertanyaan' => 'Manakah grafik fungsi kuadrat?', 'bobot' => '1', 'status' => 'siap', 'jumlah_opsi' => '4',
            'opsi' => ['A' => '', 'B' => 'garis lurus', 'C' => 'lingkaran', 'D' => 'parabola'], 'kunci' => 'A'], $timpa);
    }

    private function gambar(string $nama = 'g.png'): UploadedFile
    {
        return UploadedFile::fake()->image($nama, 120, 80);
    }

    public function test_opsi_boleh_berupa_gambar_saja_tetapi_tidak_boleh_kosong_sama_sekali(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->get("/staf/bank-soal/{$this->bank->id}/soal/tambah?tipe=pg")->assertOk()->assertSee('gambar_opsi[A]', false);

        // Opsi A tanpa teks dan tanpa gambar ditolak.
        $this->post("/staf/bank-soal/{$this->bank->id}/soal", $this->data())->assertSessionHasErrors('opsi.A');

        $this->post("/staf/bank-soal/{$this->bank->id}/soal", $this->data(['gambar_opsi' => ['A' => $this->gambar()]]))->assertSessionHasNoErrors();
        $soal = Soal::where('pertanyaan', 'like', 'Manakah grafik%')->first();
        $a = $soal->opsi->firstWhere('label', 'A');
        $this->assertSame('', $a->teks);
        $this->assertNotNull($a->gambar);
        Storage::disk('local')->assertExists($a->gambar);
        $this->assertNull($soal->opsi->firstWhere('label', 'B')->gambar);

        $this->get("/staf/bank-soal/{$this->bank->id}")->assertOk()->assertSee('Gambar opsi A')->assertSee(route('staf.soal.gambar-opsi', $a), false);
        $this->get(route('staf.soal.gambar-opsi', $a))->assertOk();
    }

    public function test_ubah_soal_mempertahankan_mengganti_dan_menghapus_gambar_opsi(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->post("/staf/bank-soal/{$this->bank->id}/soal", $this->data(['gambar_opsi' => ['A' => $this->gambar()]]));
        $soal = Soal::where('pertanyaan', 'like', 'Manakah grafik%')->first();
        $lama = $soal->opsi->firstWhere('label', 'A')->gambar;

        // Tanpa unggahan baru: gambar lama tetap (opsi A tetap sah walau teks kosong).
        $this->put("/staf/bank-soal/{$this->bank->id}/soal/{$soal->id}", $this->data())->assertSessionHasNoErrors();
        $this->assertSame($lama, $soal->fresh()->opsi->firstWhere('label', 'A')->gambar);

        // Diganti: berkas lama terhapus.
        $this->put("/staf/bank-soal/{$this->bank->id}/soal/{$soal->id}", $this->data(['gambar_opsi' => ['A' => $this->gambar('baru.png')]]))->assertSessionHasNoErrors();
        $baru = $soal->fresh()->opsi->firstWhere('label', 'A')->gambar;
        $this->assertNotSame($lama, $baru);
        Storage::disk('local')->assertMissing($lama);
        Storage::disk('local')->assertExists($baru);

        // Dihapus tanpa teks: ditolak; dihapus dengan teks: berkas terhapus.
        $this->put("/staf/bank-soal/{$this->bank->id}/soal/{$soal->id}", $this->data(['hapus_gambar_opsi' => ['A' => '1']]))->assertSessionHasErrors('opsi.A');
        $this->put("/staf/bank-soal/{$this->bank->id}/soal/{$soal->id}", $this->data(['opsi' => ['A' => 'teks pengganti', 'B' => 'b', 'C' => 'c', 'D' => 'd'], 'hapus_gambar_opsi' => ['A' => '1']]))->assertSessionHasNoErrors();
        $this->assertNull($soal->fresh()->opsi->firstWhere('label', 'A')->gambar);
        Storage::disk('local')->assertMissing($baru);
    }

    public function test_gambar_opsi_hanya_untuk_pengelola_dan_berkas_palsu_ditolak(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->post("/staf/bank-soal/{$this->bank->id}/soal", $this->data(['gambar_opsi' => ['A' => $this->gambar()]]));
        $a = OpsiSoal::whereNotNull('gambar')->first();

        $this->actingAs(User::factory()->create(), 'web')->get(route('staf.soal.gambar-opsi', $a))->assertNotFound();

        $palsu = UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;');
        $this->actingAs($this->admin, 'web')->post("/staf/bank-soal/{$this->bank->id}/soal", $this->data(['pertanyaan' => 'Soal lain', 'gambar_opsi' => ['A' => $palsu]]))->assertSessionHasErrors();
        $this->assertSame(0, Soal::where('pertanyaan', 'Soal lain')->count());
    }

    public function test_bacaan_dapat_diberi_gambar_dan_gambar_ikut_terhapus_saat_bacaan_dihapus(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->post("/staf/bank-soal/{$this->bank->id}/bacaan", ['judul' => 'Grafik', 'teks' => 'Perhatikan grafik.', 'gambar' => $this->gambar()])->assertSessionHasNoErrors();
        $b = BacaanSoal::first();
        Storage::disk('local')->assertExists($b->gambar);
        $this->get(route('staf.bacaan.gambar', $b))->assertOk();
        $berkas = $b->gambar;

        $this->delete("/staf/bank-soal/{$this->bank->id}/bacaan/{$b->id}")->assertSessionHas('berhasil');
        Storage::disk('local')->assertMissing($berkas);
    }

    public function test_salin_bank_menyalin_berkas_gambar_opsi_dan_bacaan(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->post("/staf/bank-soal/{$this->bank->id}/bacaan", ['teks' => 'Bacaan', 'gambar' => $this->gambar()]);
        $this->post("/staf/bank-soal/{$this->bank->id}/soal", $this->data(['bacaan_id' => BacaanSoal::first()->id, 'gambar_opsi' => ['A' => $this->gambar()]]));

        $baru = app(LayananSoal::class)->salinBank($this->bank, $this->admin, 'Salinan');

        $opsiAsal = OpsiSoal::whereHas('soal', fn ($q) => $q->where('bank_soal_id', $this->bank->id))->whereNotNull('gambar')->first();
        $opsiSalinan = OpsiSoal::whereHas('soal', fn ($q) => $q->where('bank_soal_id', $baru->id))->whereNotNull('gambar')->first();
        $this->assertNotSame($opsiAsal->gambar, $opsiSalinan->gambar);
        Storage::disk('local')->assertExists($opsiSalinan->gambar);
        $bacaanSalinan = $baru->bacaan()->first();
        $this->assertNotSame(BacaanSoal::first()->gambar, $bacaanSalinan->gambar);
        Storage::disk('local')->assertExists($bacaanSalinan->gambar);
    }

    public function test_paket_dan_api_memuat_url_gambar_opsi_dan_bacaan_dan_berkas_disalin_saat_aktif(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->post("/staf/bank-soal/{$this->bank->id}/bacaan", ['teks' => 'Bacaan ber-gambar', 'gambar' => $this->gambar()]);
        $s = $this->soal['s1'];
        $s->update(['bacaan_id' => BacaanSoal::first()->id]);
        $opsiA = $s->opsi()->where('label', 'A')->first();
        $opsiA->update(['gambar' => \App\Support\GambarAman::simpan($this->gambar('o.png'), 'x', 'opsi')]);

        $layananGambar = app(LayananGambarUjian::class);
        $jumlah = $layananGambar->salinKePublik($this->jadwal->refresh());
        $this->assertGreaterThanOrEqual(2, $jumlah);
        app(LayananPaket::class)->lupakan($this->jadwal);

        $paket = collect(app(LayananPaket::class)->paket($this->jadwal)['soal'])->keyBy('id')[$s->id];
        $urlOpsi = $paket['opsi_gambar']['A'];
        $urlBacaan = $paket['bacaan']['gambar'];
        $this->assertStringStartsWith('http://uji.test/media/u/'.$this->jadwal->media_kode.'/', $urlOpsi);
        $this->assertStringStartsWith('http://uji.test/media/u/'.$this->jadwal->media_kode.'/', $urlBacaan);
        $this->assertFileExists($this->media.DIRECTORY_SEPARATOR.$this->jadwal->media_kode.DIRECTORY_SEPARATOR.basename($urlOpsi));
        $this->assertFileExists($this->media.DIRECTORY_SEPARATOR.$this->jadwal->media_kode.DIRECTORY_SEPARATOR.basename($urlBacaan));

        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $r = $this->getJson("/api/ujian/{$sesi->id}/paket", $this->header($token))->assertOk();
        $soalApi = collect($r->json('soal'))->firstWhere('id', $s->id);
        $this->assertSame($urlBacaan, $soalApi['bacaan']['gambar']);
        $gambarOpsi = collect($soalApi['opsi'])->pluck('gambar', 'label');
        $this->assertSame($urlOpsi, $gambarOpsi['A']);
        $this->assertNull($gambarOpsi['B']);
        $this->assertStringNotContainsString('kunci', json_encode($soalApi));

        $layananGambar->bersihkan($this->jadwal);
        $this->assertDirectoryDoesNotExist($this->media.DIRECTORY_SEPARATOR.$this->jadwal->media_kode);
    }

    // ── Rumus, furigana, dan alat bantu penulisan ─────────────────────────────

    public function test_form_soal_dan_bacaan_memuat_alat_rumus_dan_kolom_berpratinjau(): void
    {
        $this->actingAs($this->admin, 'web');

        foreach (["/staf/bank-soal/{$this->bank->id}/soal/tambah?tipe=pg", "/staf/bank-soal/{$this->bank->id}/bacaan/tambah"] as $url) {
            $this->get($url)->assertOk()->assertSee('id="alatRumus"', false)->assertSee('Editor rumus')->assertSee('js/editor-rumus.js')
                ->assertSee('vendor/katex/katex.min.js')->assertSee('vendor/mathlive/mathlive.min.js')->assertSee('data-rumus', false)->assertSee('ふりがな');
        }
        $this->get("/staf/bank-soal/{$this->bank->id}/soal/tambah?tipe=esai")->assertOk()->assertSee('id="rubrik_esai" data-rumus', false);
    }

    public function test_halaman_bank_koreksi_dan_ujian_siswa_merender_rumus_secara_lokal(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->get("/staf/bank-soal/{$this->bank->id}")->assertOk()->assertSee('vendor/katex/katex.min.js')->assertSee('class="soal-teks render-rumus"', false);

        // Pustaka tersedia sebagai berkas lokal (tanpa CDN) dan lisensinya disertakan.
        foreach (['vendor/katex/katex.min.js', 'vendor/katex/contrib/auto-render.min.js', 'vendor/katex/katex.min.css', 'vendor/mathlive/mathlive.min.js', 'vendor/katex/LICENSE', 'vendor/mathlive/LICENSE.txt', 'js/rumus.js', 'js/editor-rumus.js'] as $berkas) {
            $this->assertFileExists(public_path($berkas));
        }
        $this->assertFileExists(public_path('vendor/katex/fonts/KaTeX_Main-Regular.woff2'));
        $this->assertStringNotContainsString('cdn.', file_get_contents(resource_path('views/staf/partials/alat-rumus.blade.php')));

        // Halaman ujian siswa memuat KaTeX dan rumus.js sebelum ujian.js (urutan defer).
        $html = file_get_contents(resource_path('views/siswa/ujian/kerjakan.blade.php'));
        $this->assertLessThan(strpos($html, "js/ujian.js"), strpos($html, "js/rumus.js"));
        $this->assertLessThan(strpos($html, "js/rumus.js"), strpos($html, "vendor/katex/katex.min.js"));
        $this->assertStringContainsString("window.Rumus.render(\$('formSoal'))", file_get_contents(public_path('js/ujian.js')));
    }
}
