<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\User;
use App\Services\LayananKisiKisi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class KisiKisiTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private BankSoal $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(denganEsai: true, jumlahSiswa: 1);
        $this->bank = BankSoal::first();
        $this->soal['s1']->update(['pertanyaan' => 'Sebutkan ibu kota Indonesia. Pilih jawaban yang benar.']);
        $this->soal['s2']->update(['pertanyaan' => 'Hitung hasil dari 12 x 5.']);
        $this->soal['s3']->update(['pertanyaan' => 'Mengapa kabel UTP dipilih untuk jaringan lokal?']);
        $this->soal['esai']->update(['pertanyaan' => 'Tuliskan pendapat Anda tentang kejujuran.']);
    }

    public function test_level_kognitif_ditebak_dari_kata_kerja_dan_sebaran_dihitung(): void
    {
        $layanan = app(LayananKisiKisi::class);
        $this->assertSame('L1', $layanan->level('Sebutkan nama benua.'));
        $this->assertSame('L2', $layanan->level('Hitung luas persegi.'));
        $this->assertSame('L3', $layanan->level('Mengapa harga naik?'));
        $this->assertSame('L2', $layanan->level('Tuliskan pendapat Anda.', true));

        $k = $layanan->susun($this->bank);
        $this->assertSame([3, 1], [$k['jumlah_pg'], $k['jumlah_esai']]);
        $this->assertSame(['L1' => 1, 'L2' => 2, 'L3' => 1], $k['sebaran']);
        $this->assertSame(['Pilihan Ganda', 'Pilihan Ganda', 'Pilihan Ganda', 'Esai'], array_column($k['baris'], 'bentuk'));
        $this->assertNotNull($k['baris'][0]['kunci']);
        $this->assertNull($k['baris'][3]['kunci']);
    }

    public function test_kisi_kisi_dicetak_atau_diunduh_word_dan_hanya_oleh_pengelola_bank(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->get("/staf/bank-soal/{$this->bank->id}/kisi-kisi")->assertOk()->assertSee('KISI-KISI SOAL')->assertSee('Matematika')->assertSee('Sebaran level kognitif');
        $this->get("/staf/bank-soal/{$this->bank->id}/kisi-kisi?format=word")->assertOk()->assertHeader('Content-Disposition');
        $this->get("/staf/bank-soal/{$this->bank->id}")->assertSee('Cetak Kisi-Kisi');

        $lain = User::factory()->create();
        $this->actingAs($lain, 'web')->get("/staf/bank-soal/{$this->bank->id}/kisi-kisi")->assertForbidden();
    }
public function test_kisi_kisi_tidak_mencetak_kalimat_soal_dan_memakai_indikator_tersimpan(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->soal['s1']->update(['materi' => 'Geografi Indonesia', 'indikator' => 'Disajikan pertanyaan, peserta didik dapat mengidentifikasi pusat pemerintahan negara.']);

        $html = $this->get("/staf/bank-soal/{$this->bank->id}/kisi-kisi")->assertOk()->getContent();

        $this->assertStringNotContainsString('Sebutkan ibu kota Indonesia', $html);
        $this->assertStringNotContainsString('Hitung hasil dari 12', $html);
        $this->assertStringNotContainsString('kabel UTP', $html);
        $this->assertStringContainsString('Geografi Indonesia', $html);
        $this->assertStringContainsString('mengidentifikasi pusat pemerintahan negara', $html);
        $this->assertStringContainsString('Peserta didik dapat', $html); // indikator umum untuk soal yang belum disusun
    }

    public function test_indikator_otomatis_disimpan_dan_yang_menyalin_soal_dibuang(): void
    {
        config(['ujian.otomatis' => ['driver' => 'gemini', 'model' => 'gemini-uji', 'kunci' => 'kunci-rahasia-ABCD1234']]);
        $butir = [
            ['id' => $this->soal['s1']->id, 'materi' => 'Geografi', 'indikator' => 'Disajikan pertanyaan, peserta didik dapat mengenali ibu kota negara.', 'level' => 'L1'],
            ['id' => $this->soal['s2']->id, 'materi' => 'Aritmetika', 'indikator' => 'Hitung hasil dari 12 x 5.', 'level' => 'L2'], // salinan soal: dibuang
        ];
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['kisi' => $butir])]]]]]])]);

        $this->actingAs($this->admin, 'web')->postJson("/staf/bank-soal/{$this->bank->id}/kisi-kisi/susun")
            ->assertOk()->assertJsonPath('diproses', 1);

        $this->assertSame('Geografi', $this->soal['s1']->fresh()->materi);
        $this->assertNull($this->soal['s2']->fresh()->indikator);

        $this->actingAs(User::factory()->create(), 'web')->postJson("/staf/bank-soal/{$this->bank->id}/kisi-kisi/susun")->assertForbidden();
    }
}
