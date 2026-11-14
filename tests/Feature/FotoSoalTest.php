<?php

namespace Tests\Feature;

use Tests\Concerns\KonfirmasiRancangan;
use App\Models\BankSoal;
use App\Models\LogOtomatis;
use App\Models\Soal;
use App\Models\User;
use App\Otomatis\GeminiPenyusun;
use App\Otomatis\PenyaringHasil;
use App\Otomatis\PermintaanSoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as KlienHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class FotoSoalTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase, KonfirmasiRancangan;

    private BankSoal $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->siapkanUjian(jumlahSiswa: 1);
        $this->bank = BankSoal::first();
    }

    private function penyedia(): void
    {
        config(['ujian.otomatis' => ['driver' => 'gemini', 'model' => 'm', 'kunci' => 'kunci-rahasia-ABCD1234']]);
    }

    private function fakeJawaban(string $teksJson): void
    {
        Http::swap(new KlienHttp);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $teksJson]]]]]])]);
    }

    private function kirim(array $timpa = [], ?UploadedFile $foto = null)
    {
        $r = $this->post("/staf/bank-soal/{$this->bank->id}/foto", array_replace(['foto' => $foto ?? UploadedFile::fake()->image('soal.jpg', 800, 600), 'jumlah_opsi' => 4], $timpa));

        return $this->lanjutkanBilaBerhasil($r, $this->bank);
    }

    public function test_halaman_foto_ada_tombolnya_dan_menyatakan_belum_tersedia_tanpa_penyedia(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->get("/staf/bank-soal/{$this->bank->id}/foto")->assertOk()->assertSee('belum tersedia');

        $this->penyedia();
        $this->get("/staf/bank-soal/{$this->bank->id}")->assertSee('Pindai soal dari foto');
        $this->get("/staf/bank-soal/{$this->bank->id}/foto")->assertOk()->assertSee('Pindai foto')->assertSee('capture="environment"', false);
    }

    public function test_foto_dibaca_menjadi_soal_draf_dengan_rumus_latex_opsi_berurutan_dan_kunci_ditandai(): void
    {
        $this->penyedia();
        $this->actingAs($this->admin, 'web');
        // Model menulis LaTeX dengan SATU garis miring (JSON tidak sah): harus dipulihkan, bukan merusak \frac / \times.
        $mentah = '{"soal":['
            .'{"tipe":"pg","level":"L2","pertanyaan":"Hitung \\(\\frac{1}{2} \\times 4\\)","opsi":["1","2","3","semua benar"],"kunci":"B","pembahasan":"Setengah dikali empat."},'
            .'{"tipe":"pg","pertanyaan":"Pilih bangun yang benar","opsi":["a","b","c","d"],"kunci":null},'
            .'{"tipe":"esai","level":"L3","pertanyaan":"Buktikan \\(x^{2}\\ge 0\\)","rubrik":"Kuadrat bilangan real tak negatif."}'
            .']}';
        $this->fakeJawaban($mentah);

        $this->kirim()->assertSessionHas('berhasil');

        $soal = Soal::where('sumber', 'otomatis')->orderBy('id')->get();
        $this->assertCount(3, $soal);
        $this->assertSame(['draf'], $soal->pluck('status')->unique()->all());
        $this->assertSame('Hitung \(\frac{1}{2} \times 4\)', $soal[0]->pertanyaan);
        $this->assertSame(['1', '2', '3', 'semua benar'], $soal[0]->opsi->pluck('teks')->all()); // urutan opsi tidak diacak
        $this->assertSame('B', $soal[0]->kunci());
        $this->assertSame(['L2', 'Setengah dikali empat.'], [$soal[0]->level, $soal[0]->pembahasan]);

        // Kunci tidak pasti: diisi A dan ditandai agar diperiksa guru.
        $this->assertSame('A', $soal[1]->kunci());
        $this->assertStringContainsString('BELUM DIPASTIKAN', $soal[1]->pembahasan);

        $this->assertSame(['esai', 'L3', 'Kuadrat bilangan real tak negatif.'], [$soal[2]->tipe, $soal[2]->level, $soal[2]->rubrik_esai]);
        $this->assertNull($soal[0]->gambar);

        // Permintaan memuat gambar (inline_data JPEG) dan instruksi LaTeX, kunci lewat header.
        Http::assertSent(function ($r) {
            $bagian = $r->data()['contents'][0]['parts'] ?? [];

            return str_contains((string) ($bagian[0]['inline_data']['mime_type'] ?? ''), 'image/jpeg')
                && strlen((string) ($bagian[0]['inline_data']['data'] ?? '')) > 100
                && str_contains(json_encode($r->data()), 'LaTeX')
                && $r->hasHeader('x-goog-api-key');
        });
        $this->assertSame('berhasil', LogOtomatis::latest('id')->first()->status);
    }

    public function test_foto_dapat_dilampirkan_sebagai_gambar_setiap_soal(): void
    {
        $this->penyedia();
        $this->actingAs($this->admin, 'web');
        $this->fakeJawaban('{"soal":[{"tipe":"pg","pertanyaan":"Lihat gambar","opsi":["a","b","c","d"],"kunci":"A"},{"tipe":"pg","pertanyaan":"Lihat lagi","opsi":["a","b","c","d"],"kunci":"C"}]}');

        $this->kirim(['lampirkan' => '1'])->assertSessionHas('berhasil');

        $soal = Soal::where('sumber', 'otomatis')->get();
        $this->assertCount(2, $soal);
        $this->assertNotSame($soal[0]->gambar, $soal[1]->gambar); // salinan terpisah per soal
        foreach ($soal as $s) {
            Storage::disk('local')->assertExists($s->gambar);
        }
    }

    public function test_validasi_foto_akses_dan_galat_penyedia(): void
    {
        $this->penyedia();
        $this->actingAs($this->admin, 'web');

        $this->kirim(['foto' => null])->assertSessionHasErrors('foto');
        $this->kirim([], UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;'))->assertSessionHasErrors();
        $this->kirim(['jumlah_opsi' => 3])->assertSessionHasErrors('jumlah_opsi');
        $this->assertSame(0, Soal::where('sumber', 'otomatis')->count());

        // Guru lain tidak boleh memakai bank ini.
        $this->actingAs(User::factory()->create(), 'web')->get("/staf/bank-soal/{$this->bank->id}/foto")->assertForbidden();
        $this->post("/staf/bank-soal/{$this->bank->id}/foto", ['foto' => UploadedFile::fake()->image('a.jpg'), 'jumlah_opsi' => 4])->assertForbidden();

        // Penyedia galat: pesan aman, tidak ada soal.
        $this->actingAs($this->admin, 'web');
        Http::swap(new KlienHttp);
        Http::fake(['*' => Http::response('', 429)]);
        $this->kirim()->assertSessionHas('gagal', fn ($m) => str_contains($m, 'sibuk'));
        $this->assertSame(0, Soal::where('sumber', 'otomatis')->count());
        $this->assertSame('gagal', LogOtomatis::latest('id')->first()->status);
    }

    public function test_penyedia_selain_gemini_menolak_foto_dengan_pesan_jelas(): void
    {
        config(['ujian.otomatis' => ['driver' => 'openai', 'model' => 'm', 'kunci' => 'kunci-rahasia-ABCD1234']]);
        $this->actingAs($this->admin, 'web');

        $this->kirim()->assertSessionHas('gagal', fn ($m) => str_contains($m, 'belum tersedia'));
    }

    public function test_pemulihan_latex_tidak_merusak_json_yang_sudah_benar(): void
    {
        $this->penyedia();
        $penyedia = \App\Models\PenyediaOtomatis::aktif();
        $g = new class($penyedia) extends GeminiPenyusun
        {
            public function uji(string $json): string
            {
                return $this->perbaikiLatex($json);
            }
        };

        // Sudah benar (ganda): tidak berubah. Satu garis miring: digandakan. Teks biasa dengan \n sah: tidak tersentuh.
        $benar = '{"a":"\\\\frac{1}{2} \\\\(x\\\\)"}';
        $this->assertSame($benar, $g->uji($benar));
        $this->assertSame('{"a":"\\\\frac{1}{2}"}', $g->uji('{"a":"\\frac{1}{2}"}'));
        $this->assertSame('{"a":"baris\nbaru"}', $g->uji('{"a":"baris\nbaru"}'));
        $this->assertSame(['a' => '\\frac{1}{2} \\times 3'], json_decode($g->uji('{"a":"\\frac{1}{2} \\times 3"}'), true));
    }

    public function test_penyaring_foto_menerima_empat_atau_lima_opsi_dan_menolak_lainnya(): void
    {
        $p = new PermintaanSoal('Mtk', 'X', 'foto', 20, 'campuran', 4, 'sedang', null, 0, 'campuran', true);
        $hasil = (new PenyaringHasil)->saring([
            ['tipe' => 'pg', 'pertanyaan' => 'Lima opsi', 'opsi' => ['1', '2', '3', '4', '5'], 'kunci' => 'E'],
            ['tipe' => 'pg', 'pertanyaan' => 'Dua opsi', 'opsi' => ['benar', 'salah'], 'kunci' => 'A'],
            ['tipe' => 'pg', 'pertanyaan' => 'Opsi bernomor', 'opsi' => ['A. satu', 'B. dua', 'C. tiga', 'D. empat'], 'kunci' => 'z'],
        ], $p);

        $this->assertCount(2, $hasil);
        $this->assertSame(['1', '2', '3', '4', '5'], array_values($hasil[0]['opsi']));
        $this->assertSame('E', $hasil[0]['kunci']);
        $this->assertSame(['satu', 'dua', 'tiga', 'empat'], array_values($hasil[1]['opsi']));
        $this->assertFalse($hasil[1]['kunci_pasti']);
    }
}
