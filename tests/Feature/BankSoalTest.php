<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Pengampu;
use App\Models\Soal;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BankSoalTest extends TestCase
{
    use RefreshDatabase;

    private Mapel $mtk;

    private Mapel $ipa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mtk = Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika', 'kelompok' => 'A', 'kkm' => 75]);
        $this->ipa = Mapel::create(['kode' => 'IPA', 'nama' => 'IPA', 'kelompok' => 'A', 'kkm' => 75]);
    }

    /** Guru yang mengampu Matematika kelas X pada tahun ajaran aktif. */
    private function guruMtk(): User
    {
        $guru = User::factory()->create();
        $tahun = TahunAjaran::firstOrCreate(['nama' => '2025/2026'], ['aktif' => true]);
        $kelas = Kelas::firstOrCreate(['tahun_ajaran_id' => $tahun->id, 'nama' => 'X A'], ['tingkat' => 'X']);
        Pengampu::create(['guru_id' => $guru->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $kelas->id]);

        return $guru;
    }

    private function bank(User $pemilik, ?Mapel $mapel = null, string $tingkat = 'X'): BankSoal
    {
        return BankSoal::create([
            'mapel_id' => ($mapel ?? $this->mtk)->id, 'tingkat' => $tingkat, 'nama' => 'Bank uji',
            'jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $pemilik->id,
        ]);
    }

    private function dataPg(array $timpa = []): array
    {
        return array_replace_recursive([
            'tipe' => 'pg', 'pertanyaan' => 'Hasil 2 + 2 adalah ...', 'bobot' => '1', 'status' => 'siap',
            'jumlah_opsi' => 4, 'opsi' => ['A' => '3', 'B' => '4', 'C' => '5', 'D' => '6'], 'kunci' => 'B',
        ], $timpa);
    }

    // ── Akses ───────────────────────────────────────────────────────────────

    public function test_tamu_dan_pengawas_tidak_bisa_masuk(): void
    {
        $this->get('/staf/bank-soal')->assertRedirect(route('staf.masuk'));
        $this->actingAs(User::factory()->pengawas()->create(), 'web')->get('/staf/bank-soal')->assertForbidden();
    }

    public function test_guru_hanya_melihat_bank_miliknya_dan_admin_melihat_semua(): void
    {
        $a = $this->guruMtk();
        $b = User::factory()->create();
        $this->bank($a)->update(['nama' => 'Milik A']);
        $this->bank($b)->update(['nama' => 'Milik B']);

        $this->actingAs($a, 'web')->get('/staf/bank-soal')->assertSee('Milik A')->assertDontSee('Milik B');
        $this->actingAs(User::factory()->admin()->create(), 'web')->get('/staf/bank-soal')->assertSee('Milik A')->assertSee('Milik B');
    }

    public function test_guru_tidak_bisa_membuka_mengubah_atau_menghapus_bank_guru_lain(): void
    {
        $bank = $this->bank($this->guruMtk());
        $lain = User::factory()->create();
        $this->actingAs($lain, 'web');

        $this->get("/staf/bank-soal/{$bank->id}")->assertForbidden();
        $this->get("/staf/bank-soal/{$bank->id}/ubah")->assertForbidden();
        $this->put("/staf/bank-soal/{$bank->id}", ['nama' => 'x', 'jumlah_opsi_bawaan' => 4])->assertForbidden();
        $this->delete("/staf/bank-soal/{$bank->id}")->assertForbidden();
        $this->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg())->assertForbidden();
        $this->assertNotNull(BankSoal::find($bank->id));
    }

    public function test_guru_hanya_bisa_membuat_bank_untuk_mapel_dan_tingkat_yang_diampu(): void
    {
        $guru = $this->guruMtk();
        $this->actingAs($guru, 'web');

        $this->post('/staf/bank-soal', ['nama' => 'Bank', 'mapel_id' => $this->mtk->id, 'tingkat' => 'X', 'jumlah_opsi_bawaan' => 4])
            ->assertRedirect();
        $this->assertSame(1, BankSoal::count());

        // Mapel lain, dan tingkat lain untuk mapel yang sama, ditolak.
        $this->post('/staf/bank-soal', ['nama' => 'B2', 'mapel_id' => $this->ipa->id, 'tingkat' => 'X', 'jumlah_opsi_bawaan' => 4])
            ->assertSessionHasErrors('mapel_id');
        $this->post('/staf/bank-soal', ['nama' => 'B3', 'mapel_id' => $this->mtk->id, 'tingkat' => 'XII', 'jumlah_opsi_bawaan' => 4])
            ->assertSessionHasErrors('mapel_id');
        $this->assertSame(1, BankSoal::count());
    }

    public function test_admin_bisa_membuat_bank_untuk_mapel_mana_pun(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'web')
            ->post('/staf/bank-soal', ['nama' => 'Bank', 'mapel_id' => $this->ipa->id, 'tingkat' => 'XI', 'jumlah_opsi_bawaan' => 5])
            ->assertRedirect();

        $this->assertSame(5, BankSoal::first()->jumlah_opsi_bawaan);
    }

    public function test_mapel_dan_tingkat_tidak_bisa_diubah_setelah_dibuat(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);

        $this->actingAs($guru, 'web')->put("/staf/bank-soal/{$bank->id}", [
            'nama' => 'Nama baru', 'jumlah_opsi_bawaan' => 5, 'mapel_id' => $this->ipa->id, 'tingkat' => 'XII',
        ]);

        $bank->refresh();
        $this->assertSame('Nama baru', $bank->nama);
        $this->assertSame($this->mtk->id, $bank->mapel_id);
        $this->assertSame('X', $bank->tingkat);
    }

    // ── Soal ────────────────────────────────────────────────────────────────

    public function test_tambah_soal_pg_empat_opsi(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);

        $this->actingAs($guru, 'web')->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg())
            ->assertRedirect(route('staf.bank-soal.show', $bank));

        $soal = Soal::with('opsi')->first();
        $this->assertSame('pg', $soal->tipe);
        $this->assertCount(4, $soal->opsi);
        $this->assertSame('B', $soal->kunci());
        $this->assertSame('4', $soal->opsi->firstWhere('label', 'B')->teks);
        $this->assertSame(1, $soal->opsi->where('benar', true)->count());
    }

    public function test_tambah_soal_pg_lima_opsi_wajib_mengisi_opsi_e(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);
        $this->actingAs($guru, 'web');

        $this->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg(['jumlah_opsi' => 5]))->assertSessionHasErrors('opsi.E');

        $this->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg(['jumlah_opsi' => 5, 'opsi' => ['E' => '7'], 'kunci' => 'E']))
            ->assertSessionHasNoErrors();
        $this->assertCount(5, Soal::first()->opsi);
        $this->assertSame('E', Soal::first()->kunci());
    }

    public function test_kunci_e_ditolak_pada_soal_empat_opsi(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);

        $this->actingAs($guru, 'web')->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg(['kunci' => 'E']))
            ->assertSessionHasErrors('kunci');
        $this->assertSame(0, Soal::count());
    }

    public function test_validasi_soal_pg(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);
        $this->actingAs($guru, 'web');

        $this->post("/staf/bank-soal/{$bank->id}/soal", ['tipe' => 'pg'])
            ->assertSessionHasErrors(['pertanyaan', 'bobot', 'kunci', 'opsi.A', 'opsi.B', 'opsi.C', 'opsi.D']);
        $this->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg(['bobot' => '0']))->assertSessionHasErrors('bobot');
        $this->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg(['bobot' => '101']))->assertSessionHasErrors('bobot');
        $this->assertSame(0, Soal::count());
    }

    public function test_tambah_soal_esai_dengan_jawaban_acuan_tanpa_opsi(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);

        $this->actingAs($guru, 'web')->post("/staf/bank-soal/{$bank->id}/soal", [
            'tipe' => 'esai', 'pertanyaan' => 'Jelaskan teorema Pythagoras.', 'bobot' => '5', 'status' => 'siap',
            'rubrik_esai' => 'a^2 + b^2 = c^2',
        ])->assertSessionHasNoErrors();

        $soal = Soal::with('opsi')->first();
        $this->assertSame('esai', $soal->tipe);
        $this->assertSame('a^2 + b^2 = c^2', $soal->rubrik_esai);
        $this->assertCount(0, $soal->opsi);
    }

    public function test_ubah_soal_mengganti_opsi_dan_kunci(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);
        $this->actingAs($guru, 'web')->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg());
        $soal = Soal::first();

        $this->put("/staf/bank-soal/{$bank->id}/soal/{$soal->id}", $this->dataPg([
            'pertanyaan' => 'Diubah', 'opsi' => ['A' => 'a1', 'B' => 'b1', 'C' => 'c1', 'D' => 'd1'], 'kunci' => 'D',
        ]))->assertSessionHasNoErrors();

        $soal->refresh()->load('opsi');
        $this->assertSame('Diubah', $soal->pertanyaan);
        $this->assertSame('D', $soal->kunci());
        $this->assertCount(4, $soal->opsi);
        $this->assertSame('a1', $soal->opsi->firstWhere('label', 'A')->teks);
    }

    public function test_tipe_soal_tidak_bisa_diubah_lewat_ubah(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);
        $this->actingAs($guru, 'web')->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg());
        $soal = Soal::first();

        $this->put("/staf/bank-soal/{$bank->id}/soal/{$soal->id}", $this->dataPg(['tipe' => 'esai']));

        $this->assertSame('pg', $soal->fresh()->tipe);
    }

    public function test_soal_dari_bank_lain_tidak_bisa_diakses_lewat_bank_sendiri(): void
    {
        $guru = $this->guruMtk();
        $bankSaya = $this->bank($guru);
        $bankLain = $this->bank(User::factory()->create());
        $this->actingAs(User::factory()->admin()->create(), 'web')->post("/staf/bank-soal/{$bankLain->id}/soal", $this->dataPg());
        $soalLain = Soal::first();

        // Soal harus milik bank yang ada di URL; id soal dari bank lain dianggap tidak ada.
        $this->actingAs($guru, 'web')->get("/staf/bank-soal/{$bankSaya->id}/soal/{$soalLain->id}/ubah")->assertNotFound();
        $this->delete("/staf/bank-soal/{$bankSaya->id}/soal/{$soalLain->id}")->assertNotFound();
        $this->assertNotNull(Soal::find($soalLain->id));
    }

    public function test_hapus_soal_dan_bank_bersifat_lunak(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);
        $this->actingAs($guru, 'web')->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg());
        $soal = Soal::first();

        $this->delete("/staf/bank-soal/{$bank->id}/soal/{$soal->id}");
        $this->assertNull(Soal::find($soal->id));
        $this->assertNotNull(Soal::withTrashed()->find($soal->id));

        $this->delete("/staf/bank-soal/{$bank->id}");
        $this->assertNull(BankSoal::find($bank->id));
        $this->assertNotNull(BankSoal::withTrashed()->find($bank->id));
    }

    public function test_status_dan_tandai_semua_draf_siap(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);
        $this->actingAs($guru, 'web');
        $this->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg(['status' => 'draf']));
        $this->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg(['status' => 'draf', 'pertanyaan' => 'Kedua']));
        $this->assertSame(2, Soal::where('status', 'draf')->count());

        $pertama = Soal::first();
        $this->post("/staf/bank-soal/{$bank->id}/soal/{$pertama->id}/status", ['status' => 'siap']);
        $this->assertSame('siap', $pertama->fresh()->status);
        $this->post("/staf/bank-soal/{$bank->id}/soal/{$pertama->id}/status", ['status' => 'rahasia'])->assertSessionHasErrors('status');

        $this->post("/staf/bank-soal/{$bank->id}/siapkan-semua");
        $this->assertSame(0, Soal::where('status', 'draf')->count());
    }

    public function test_teks_soal_di_halaman_di_escape(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);
        $this->actingAs($guru, 'web')->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg([
            'pertanyaan' => '<script>alert(1)</script> baris1'."\n".'baris2',
            'opsi' => ['A' => '<img src=x onerror=alert(2)>'],
        ]));

        $html = $this->get("/staf/bank-soal/{$bank->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('baris1<br />', $html);
    }

    // ── Gambar ──────────────────────────────────────────────────────────────

    public function test_gambar_dibuat_ulang_dan_disimpan_privat(): void
    {
        Storage::fake('local');
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);

        $this->actingAs($guru, 'web')->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg() + [
            'gambar' => UploadedFile::fake()->image('Soal Asli.png', 3000, 1000),
        ])->assertSessionHasNoErrors();

        $soal = Soal::first();
        $this->assertNotNull($soal->gambar);
        $this->assertMatchesRegularExpression('#^soal/[A-Za-z0-9]{40}\.(webp|jpg)$#', $soal->gambar);
        Storage::disk('local')->assertExists($soal->gambar);
        [$lebar] = getimagesizefromstring(Storage::disk('local')->get($soal->gambar));
        $this->assertSame(1600, $lebar);
    }

    public function test_berkas_palsu_berekstensi_gambar_ditolak(): void
    {
        Storage::fake('local');
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);

        $this->actingAs($guru, 'web')->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg() + [
            'gambar' => UploadedFile::fake()->createWithContent('bukan.png', '<?php echo "x"; ?>'),
        ])->assertSessionHasErrors('gambar');

        $this->assertSame(0, Soal::count());
        $this->assertSame([], Storage::disk('local')->allFiles('soal'));
    }

    public function test_gambar_diganti_dan_dihapus_membersihkan_berkas_lama(): void
    {
        Storage::fake('local');
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);
        $this->actingAs($guru, 'web')->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg() + ['gambar' => UploadedFile::fake()->image('a.png', 100, 100)]);
        $soal = Soal::first();
        $lama = $soal->gambar;

        $this->put("/staf/bank-soal/{$bank->id}/soal/{$soal->id}", $this->dataPg() + ['gambar' => UploadedFile::fake()->image('b.jpg', 100, 100)]);
        $baru = $soal->fresh()->gambar;
        $this->assertNotSame($lama, $baru);
        Storage::disk('local')->assertMissing($lama);
        Storage::disk('local')->assertExists($baru);

        $this->put("/staf/bank-soal/{$bank->id}/soal/{$soal->id}", $this->dataPg() + ['hapus_gambar' => '1']);
        $this->assertNull($soal->fresh()->gambar);
        Storage::disk('local')->assertMissing($baru);
    }

    public function test_gambar_hanya_dapat_dibuka_oleh_yang_berhak(): void
    {
        Storage::fake('local');
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);
        $this->actingAs($guru, 'web')->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg() + ['gambar' => UploadedFile::fake()->image('a.png', 50, 50)]);
        $soal = Soal::first();

        $this->get("/staf/gambar/soal/{$soal->id}")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->admin()->create(), 'web')->get("/staf/gambar/soal/{$soal->id}")->assertOk();
        $this->actingAs(User::factory()->create(), 'web')->get("/staf/gambar/soal/{$soal->id}")->assertNotFound();
        $this->app['auth']->guard('web')->logout();
        $this->get("/staf/gambar/soal/{$soal->id}")->assertRedirect(route('staf.masuk'));
    }

    // ── Salin bank ──────────────────────────────────────────────────────────

    public function test_salin_bank_menyalin_soal_opsi_dan_gambar(): void
    {
        Storage::fake('local');
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);
        $this->actingAs($guru, 'web');
        $this->post("/staf/bank-soal/{$bank->id}/soal", $this->dataPg() + ['gambar' => UploadedFile::fake()->image('a.png', 50, 50)]);
        $this->post("/staf/bank-soal/{$bank->id}/soal", ['tipe' => 'esai', 'pertanyaan' => 'Esai', 'bobot' => '3', 'status' => 'siap']);

        $this->post("/staf/bank-soal/{$bank->id}/salin")->assertRedirect();

        $salinan = BankSoal::where('id', '!=', $bank->id)->first();
        $this->assertStringEndsWith('(salinan)', $salinan->nama);
        $this->assertSame($guru->id, $salinan->dibuat_oleh);
        $this->assertSame(2, $salinan->soal()->count());

        $asli = $bank->soal()->where('tipe', 'pg')->first();
        $baru = $salinan->soal()->where('tipe', 'pg')->with('opsi')->first();
        $this->assertSame('B', $baru->kunci());
        $this->assertCount(4, $baru->opsi);
        $this->assertNotSame($asli->gambar, $baru->gambar);
        Storage::disk('local')->assertExists($baru->gambar);
        Storage::disk('local')->assertExists($asli->gambar);

        // Mengubah salinan tidak memengaruhi bank asli.
        $baru->update(['pertanyaan' => 'Diubah']);
        $this->assertNotSame('Diubah', $asli->fresh()->pertanyaan);
    }

    public function test_salin_bank_milik_guru_lain_ditolak(): void
    {
        $bank = $this->bank($this->guruMtk());

        $this->actingAs(User::factory()->create(), 'web')->post("/staf/bank-soal/{$bank->id}/salin")->assertForbidden();
        $this->assertSame(1, BankSoal::count());
    }

    // ── Impor Excel ─────────────────────────────────────────────────────────

    private function xlsx(array $baris): UploadedFile
    {
        $header = ['Tipe', 'Pertanyaan', 'Opsi A', 'Opsi B', 'Opsi C', 'Opsi D', 'Opsi E', 'Kunci', 'Bobot', 'Jawaban Acuan'];
        $buku = new Spreadsheet;
        $lembar = $buku->getActiveSheet();
        foreach (array_merge([$header], $baris) as $r => $isi) {
            foreach (array_values($isi) as $c => $nilai) {
                if ($nilai !== null) {
                    $lembar->setCellValueExplicit([$c + 1, $r + 1], (string) $nilai, DataType::TYPE_STRING);
                }
            }
        }
        $jalur = tempnam(sys_get_temp_dir(), 'soal').'.xlsx';
        (new Xlsx($buku))->save($jalur);

        return new UploadedFile($jalur, 'soal.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function impor(BankSoal $bank, UploadedFile $berkas)
    {
        $r = $this->post("/staf/bank-soal/{$bank->id}/impor", ['berkas' => $berkas])->assertOk();
        $token = $r->viewData('token');
        if (! $token) {
            return $r;
        }

        return $this->post("/staf/bank-soal/{$bank->id}/impor/{$token}")->assertOk();
    }

    public function test_impor_soal_pg_dan_esai(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);
        $this->actingAs($guru, 'web');

        $r = $this->impor($bank, $this->xlsx([
            ['PG', '1 + 1 = ?', '1', '2', '3', '4', null, 'b', '2', null],
            ['pg', 'Lima opsi', 'a', 'b', 'c', 'd', 'e', 'E', null, null],
            ['Esai', 'Jelaskan.', null, null, null, null, null, null, '5', 'Jawaban acuan'],
        ]));

        $this->assertSame(3, $r->viewData('hasil')->baru);
        $soal = $bank->soal()->with('opsi')->get();
        $this->assertCount(3, $soal);
        $this->assertSame('B', $soal[0]->kunci());
        $this->assertCount(4, $soal[0]->opsi);
        $this->assertEquals(2, $soal[0]->bobot);
        $this->assertCount(5, $soal[1]->opsi);
        $this->assertSame('E', $soal[1]->kunci());
        $this->assertEquals(1, $soal[1]->bobot);
        $this->assertSame('Jawaban acuan', $soal[2]->rubrik_esai);
        $this->assertSame(['impor'], $soal->pluck('sumber')->unique()->all());
        $this->assertSame(['siap'], $soal->pluck('status')->unique()->all());
    }

    public function test_impor_soal_dengan_satu_baris_salah_membatalkan_semua(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);
        $this->actingAs($guru, 'web');

        $r = $this->impor($bank, $this->xlsx([
            ['PG', 'Baik', '1', '2', '3', '4', null, 'A', null, null],
            ['PG', 'Opsi D kosong', '1', '2', '3', null, null, 'A', null, null],
            ['PG', 'Kunci ke opsi kosong', '1', '2', '3', '4', null, 'E', null, null],
            ['PG', 'Tanpa kunci', '1', '2', '3', '4', null, null, null, null],
            ['Soal', 'Tipe salah', null, null, null, null, null, null, null, null],
        ]));

        $hasil = $r->viewData('hasil');
        $this->assertFalse($hasil->berhasil());
        $this->assertCount(4, $hasil->galat);
        $this->assertSame([3, 4, 5, 6], array_column($hasil->galat, 'baris'));
        $this->assertSame(0, Soal::count());
    }

    public function test_impor_soal_ke_bank_guru_lain_ditolak(): void
    {
        $bank = $this->bank($this->guruMtk());

        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/staf/bank-soal/{$bank->id}/impor")->assertForbidden();
        $this->get("/staf/bank-soal/{$bank->id}/impor/templat")->assertForbidden();
        $this->post("/staf/bank-soal/{$bank->id}/impor", ['berkas' => $this->xlsx([['PG', 'x', '1', '2', '3', '4', null, 'A', null, null]])])
            ->assertForbidden();
    }

    public function test_token_impor_bank_lain_tidak_berlaku(): void
    {
        $guru = $this->guruMtk();
        $a = $this->bank($guru);
        $b = $this->bank($guru);
        $this->actingAs($guru, 'web');
        $token = $this->post("/staf/bank-soal/{$a->id}/impor", ['berkas' => $this->xlsx([['PG', 'x', '1', '2', '3', '4', null, 'A', null, null]])])
            ->viewData('token');

        $this->post("/staf/bank-soal/{$b->id}/impor/{$token}")->assertNotFound();
        $this->assertSame(0, Soal::count());
    }

    public function test_templat_impor_soal_dapat_diunduh(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);

        $this->actingAs($guru, 'web')->get("/staf/bank-soal/{$bank->id}/impor/templat")->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_halaman_bank_soal_tampil(): void
    {
        $guru = $this->guruMtk();
        $bank = $this->bank($guru);
        $this->actingAs($guru, 'web');

        $this->get('/staf/bank-soal')->assertOk();
        $this->get('/staf/bank-soal/tambah')->assertOk()->assertSee('Matematika');
        $this->get("/staf/bank-soal/{$bank->id}")->assertOk();
        $this->get("/staf/bank-soal/{$bank->id}/ubah")->assertOk();
        $this->get("/staf/bank-soal/{$bank->id}/soal/tambah?tipe=pg")->assertOk()->assertSee('Pilihan Jawaban')->assertSee('Kunci Jawaban');
        $this->get("/staf/bank-soal/{$bank->id}/soal/tambah?tipe=esai")->assertOk()->assertSee('Jawaban Acuan');
        $this->get("/staf/bank-soal/{$bank->id}/impor")->assertOk();
    }

    public function test_guru_tanpa_pengampu_tidak_melihat_mapel_di_formulir_bank(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->get('/staf/bank-soal/tambah')
            ->assertOk()->assertDontSee('<option value="'.$this->mtk->id.'"', false)->assertSee('Belum ada mapel yang Anda ampu');
    }
}
