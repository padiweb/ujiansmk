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
        $this->assertSame('Sebutkan ibu kota Indonesia.', $layanan->indikator("Sebutkan ibu kota Indonesia.  Pilih jawaban\nyang benar."));

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
        $this->get("/staf/bank-soal/{$this->bank->id}/kisi-kisi")->assertOk()->assertSee('KISI-KISI SOAL')->assertSee('Matematika')->assertSee('Sebutkan ibu kota Indonesia.')->assertSee('Sebaran level kognitif');
        $this->get("/staf/bank-soal/{$this->bank->id}/kisi-kisi?format=word")->assertOk()->assertHeader('Content-Disposition');
        $this->get("/staf/bank-soal/{$this->bank->id}")->assertSee('Cetak Kisi-Kisi');

        $lain = User::factory()->create();
        $this->actingAs($lain, 'web')->get("/staf/bank-soal/{$this->bank->id}/kisi-kisi")->assertForbidden();
    }
}
