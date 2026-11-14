<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\Soal;
use App\Services\LayananKisiKisi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as KlienHttp;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class LevelSoalTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private BankSoal $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 1);
        $this->bank = BankSoal::first();
    }

    private function data(array $timpa = []): array
    {
        return array_replace(['tipe' => 'pg', 'pertanyaan' => 'Sebutkan nama protokol web.', 'bobot' => '1', 'status' => 'siap', 'jumlah_opsi' => '4',
            'opsi' => ['A' => 'HTTP', 'B' => 'FTP', 'C' => 'SMTP', 'D' => 'SSH'], 'kunci' => 'A'], $timpa);
    }

    public function test_soal_manual_menyimpan_level_dan_pembahasan_yang_bisa_diubah_lagi(): void
    {
        $this->actingAs($this->admin, 'web');
        $this->get("/staf/bank-soal/{$this->bank->id}/soal/tambah?tipe=pg")->assertOk()->assertSee('Level kognitif')->assertSee('Pembahasan / keterangan');

        $this->post("/staf/bank-soal/{$this->bank->id}/soal", $this->data(['level' => 'L3', 'pembahasan' => 'HTTP dipakai untuk web.']))->assertSessionHasNoErrors();
        $s = Soal::where('pertanyaan', 'Sebutkan nama protokol web.')->first();
        $this->assertSame(['L3', 'HTTP dipakai untuk web.'], [$s->level, $s->pembahasan]);
        $this->get("/staf/bank-soal/{$this->bank->id}")->assertSee('L3')->assertSee('Pembahasan:')->assertSee('HTTP dipakai untuk web.');

        $this->put("/staf/bank-soal/{$this->bank->id}/soal/{$s->id}", $this->data(['level' => 'L1', 'pembahasan' => 'Catatan baru saya.']))->assertSessionHasNoErrors();
        $this->assertSame(['L1', 'Catatan baru saya.'], [$s->fresh()->level, $s->fresh()->pembahasan]);

        $this->post("/staf/bank-soal/{$this->bank->id}/soal", $this->data(['level' => 'L9']))->assertSessionHasErrors('level');
        $this->put("/staf/bank-soal/{$this->bank->id}/soal/{$s->id}", $this->data(['level' => '', 'pembahasan' => '']))->assertSessionHasNoErrors();
        $this->assertSame([null, null], [$s->fresh()->level, $s->fresh()->pembahasan]);
    }

    public function test_penyusun_otomatis_menyimpan_level_dan_pembahasan_dan_meminta_level_pada_prompt(): void
    {
        config(['ujian.otomatis' => ['driver' => 'gemini', 'model' => 'm', 'kunci' => 'kunci-rahasia-ABCD1234']]);
        $json = json_encode(['soal' => [
            ['tipe' => 'pg', 'level' => 'L1', 'pertanyaan' => 'Apa itu IP?', 'opsi' => ['alamat', 'kabel', 'port', 'sandi'], 'kunci' => 'A', 'pembahasan' => 'IP adalah alamat logis.'],
            ['tipe' => 'pg', 'level' => 'l3', 'pertanyaan' => 'Mengapa subnetting dipakai?', 'opsi' => ['hemat', 'mahal', 'lambat', 'rumit'], 'kunci' => 'A'],
            ['tipe' => 'pg', 'level' => 'XYZ', 'pertanyaan' => 'Soal level aneh?', 'opsi' => ['1', '2', '3', '4'], 'kunci' => 'B', 'pembahasan' => ['bukan teks']],
        ]]);
        Http::swap(new KlienHttp);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $json]]]]]])]);

        $this->actingAs($this->admin, 'web')->get("/staf/bank-soal/{$this->bank->id}/otomatis")->assertOk()->assertSee('Level kognitif')->assertSee('L3 — Penalaran');
        $this->post("/staf/bank-soal/{$this->bank->id}/otomatis", ['topik' => 'Jaringan', 'jumlah' => 3, 'tipe' => 'pg', 'jumlah_opsi' => 4, 'kesulitan' => 'sedang', 'level' => 'L3'])
            ->assertSessionHas('berhasil');

        $s = Soal::where('sumber', 'otomatis')->orderBy('id')->get();
        $this->assertSame(['L1', 'L3', null], $s->pluck('level')->all());
        $this->assertSame(['IP adalah alamat logis.', null, null], $s->pluck('pembahasan')->all());
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), 'semua soal level L3') && str_contains(json_encode($r->data()), 'pembahasan'));

        $this->post("/staf/bank-soal/{$this->bank->id}/otomatis", ['topik' => 'Jaringan', 'jumlah' => 3, 'tipe' => 'pg', 'jumlah_opsi' => 4, 'kesulitan' => 'sedang', 'level' => 'campuran']);
        Http::assertSent(fn ($r) => str_contains(json_encode($r->data()), 'campuran, sebarkan'));
        $this->post("/staf/bank-soal/{$this->bank->id}/otomatis", ['topik' => 'Jaringan', 'jumlah' => 3, 'tipe' => 'pg', 'jumlah_opsi' => 4, 'kesulitan' => 'sedang', 'level' => 'L8'])->assertSessionHasErrors('level');
    }

    public function test_kisi_kisi_memakai_level_tersimpan_dan_menebak_bila_kosong(): void
    {
        $this->soal['s1']->update(['pertanyaan' => 'Sebutkan ibu kota.', 'level' => 'L3']); // tebakan akan L1, tersimpan L3
        $this->soal['s2']->update(['pertanyaan' => 'Sebutkan benua.', 'level' => null]);
        $this->soal['s3']->update(['pertanyaan' => 'Hitung 2+2.', 'level' => 'L1']);

        $k = app(LayananKisiKisi::class)->susun($this->bank);
        $this->assertSame(['L3', 'L1', 'L1'], array_column($k['baris'], 'level'));
    }
}
