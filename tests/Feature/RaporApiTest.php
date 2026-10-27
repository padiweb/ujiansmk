<?php

namespace Tests\Feature;

use App\Models\KlienApi;
use App\Models\RaporSiswa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class RaporApiTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private string $kunci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 3);
        [, $this->kunci] = KlienApi::terbitkan('EduWeb', $this->admin->id);
        $this->kelas->update(['eduweb_id' => 77]);
        foreach ($this->siswa as $i => $s) {
            $s->update(['eduweb_id' => 9000 + $i]);
        }
    }

    private function api(string $jalur, ?string $kunci = null)
    {
        return $this->withHeader('Authorization', 'Bearer '.($kunci ?? $this->kunci))->getJson('/api/rapor/v1/'.$jalur);
    }

    private function final(int $i, ?string $keputusan = null, string $status = 'final'): void
    {
        RaporSiswa::create(['siswa_id' => $this->siswa[$i]->id, 'periode_id' => $this->jadwal->periode_id, 'kelas_id' => $this->kelas->id, 'status' => $status, 'keputusan' => $keputusan]);
    }

    public function test_kunci_salah_dicabut_atau_tanpa_kunci_ditolak_seragam(): void
    {
        $this->getJson('/api/rapor/v1/ping')->assertStatus(401);
        $this->api('ping', 'rpr_'.str_repeat('x', 40))->assertStatus(401)->assertExactJson(['message' => 'Tidak sah.']);
        $this->api('ping', 'exu_'.str_repeat('x', 40))->assertStatus(401);
        $this->api('ping')->assertOk()->assertJsonPath('ok', true);

        KlienApi::query()->update(['dicabut_pada' => now()]);
        $this->api('ping')->assertStatus(401)->assertExactJson(['message' => 'Tidak sah.']);
        $this->api('status-kelas?classroom_id=77')->assertStatus(401);
    }

    public function test_kunci_tersimpan_sebagai_hash(): void
    {
        $this->assertDatabaseMissing('klien_api', ['hash' => $this->kunci]);
        $this->assertDatabaseHas('klien_api', ['hash' => hash('sha256', $this->kunci), 'awalan' => substr($this->kunci, 0, 8)]);
        $this->assertNotNull(KlienApi::first()->makeVisible('hash')->hash);
        $this->assertArrayNotHasKey('hash', KlienApi::first()->toArray());
    }

    public function test_status_belum_final_lalu_final_dengan_keputusan(): void
    {
        $res = $this->api('status-kelas?classroom_id=77')->assertOk();
        $this->assertSame('belum_final', $res->json('status'));
        $this->assertCount(3, $res->json('students'));

        $this->final(0, 'naik');
        $this->final(1, 'tinggal');
        $this->final(2, 'naik', 'draf'); // draf tidak dihitung final dan keputusannya tidak dibocorkan
        $res = $this->api('status-kelas?classroom_id=77')->assertOk();
        $this->assertSame('belum_final', $res->json('status'));
        $baris = collect($res->json('students'))->keyBy('student_id');
        $this->assertSame(['final', 'naik'], [$baris[9000]['status'], $baris[9000]['keputusan']]);
        $this->assertSame(['final', 'tinggal'], [$baris[9001]['status'], $baris[9001]['keputusan']]);
        $this->assertSame(['belum_final', null], [$baris[9002]['status'], $baris[9002]['keputusan']]);

        RaporSiswa::where('siswa_id', $this->siswa[2]->id)->update(['status' => 'final']);
        $this->assertSame('final', $this->api('status-kelas?classroom_id=77')->json('status'));
    }

    public function test_semester_yang_tidak_diisi_di_sini_dilaporkan_tidak_dipakai(): void
    {
        $this->jadwal->periode->update(['rapor_di_sini' => false]);

        $res = $this->api('status-kelas?classroom_id=77')->assertOk();
        $this->assertSame('tidak_dipakai', $res->json('status'));
        $this->assertSame([], $res->json('students'));
    }

    public function test_kelas_tidak_dikenal_dan_parameter_salah(): void
    {
        $this->assertSame('tidak_dikenal', $this->api('status-kelas?classroom_id=123456')->assertOk()->json('status'));
        $this->api('status-kelas')->assertStatus(422);
        $this->api('status-kelas?classroom_id=abc')->assertStatus(422);
    }

    public function test_admin_membuat_dan_mencabut_kunci_dari_pengaturan_rapor(): void
    {
        $this->actingAs($this->admin, 'web')->post('/staf/pengaturan-rapor/klien-api', ['nama' => 'EduWeb Produksi'])
            ->assertSessionHas('kunci_api_baru', fn ($k) => str_starts_with($k, 'rpr_'));
        $klien = KlienApi::where('nama', 'EduWeb Produksi')->first();
        $this->get('/staf/pengaturan-rapor')->assertOk()->assertSee('EduWeb Produksi')->assertSee($klien->awalan);

        $this->post("/staf/pengaturan-rapor/klien-api/{$klien->id}/cabut")->assertSessionHas('berhasil');
        $this->assertNotNull($klien->fresh()->dicabut_pada);
        $this->assertDatabaseHas('audit_log', ['aksi' => 'rapor.api_kunci_cabut']);

        $this->actingAs(\App\Models\User::factory()->create(), 'web')->post('/staf/pengaturan-rapor/klien-api', ['nama' => 'X'])->assertForbidden();
    }

    private function isiFinal(int $i, string $keputusan = 'naik'): void
    {
        \App\Models\NilaiRapor::create([
            'siswa_id' => $this->siswa[$i]->id, 'mapel_id' => $this->mtk->id, 'periode_id' => $this->jadwal->periode_id, 'kelas_id' => $this->kelas->id,
            'np' => 80, 'nk' => 78, 'na' => 79, 'predikat' => 'B', 'status' => 'final',
        ]);
        RaporSiswa::create([
            'siswa_id' => $this->siswa[$i]->id, 'periode_id' => $this->jadwal->periode_id, 'kelas_id' => $this->kelas->id, 'status' => 'final', 'keputusan' => $keputusan,
            'sakit' => 2, 'izin' => 1, 'alpa' => 0, 'catatan_wali' => 'Rajin', 'ekskul' => [['nama' => 'Pramuka', 'nilai' => 88, 'predikat' => 'B']],
        ]);
    }

    public function test_rapor_hanya_memberi_siswa_final_beserta_isinya(): void
    {
        $this->isiFinal(0);
        $this->final(1, 'naik', 'draf'); // draf: tidak boleh bocor

        $res = $this->api('rapor?classroom_id=77')->assertOk();
        $this->assertSame('ok', $res->json('status'));
        $this->assertCount(1, $res->json('students'));
        $s = $res->json('students.0');
        $this->assertSame(9000, $s['student_id']);
        $this->assertSame('Matematika', $s['mapel'][0]['nama']);
        $this->assertSame(['umum', 79, 'B'], [$s['mapel'][0]['kelompok'], $s['mapel'][0]['na'], $s['mapel'][0]['predikat']]);
        $this->assertSame([['nama' => 'Pramuka', 'nilai' => 88, 'predikat' => 'B']], $s['ekskul']);
        $this->assertSame(['sakit' => 2, 'izin' => 1, 'alpa' => 0], $s['absensi']);
        $this->assertSame('Rajin', $s['catatan']);
        $this->assertNull($s['keputusan']); // semester ganjil: tidak ada keputusan
        $this->assertDatabaseHas('audit_log', ['aksi' => 'rapor.api']);
    }

    public function test_rapor_satu_siswa_semester_genap_dan_keputusan(): void
    {
        $this->isiFinal(0);
        $this->isiFinal(2, 'tinggal');
        $this->jadwal->periode->update(['semester' => 'genap']);

        $res = $this->api('rapor?classroom_id=77&student_id=9002')->assertOk();
        $this->assertCount(1, $res->json('students'));
        $this->assertSame('tinggal', $res->json('students.0.keputusan'));

        $this->assertSame([], $this->api('rapor?classroom_id=77&student_id=9001')->json('students')); // belum final
        $this->assertSame([], $this->api('rapor?classroom_id=77&student_id=424242')->json('students'));
    }

    public function test_rapor_semester_tak_dipakai_kelas_tak_dikenal_dan_kunci_wajib(): void
    {
        $this->isiFinal(0);
        $this->assertSame('tidak_dikenal', $this->api('rapor?classroom_id=999')->json('status'));
        $this->api('rapor')->assertStatus(422);
        $this->flushHeaders()->getJson('/api/rapor/v1/rapor?classroom_id=77')->assertStatus(401);
        $this->api('rapor?classroom_id=77&periode=999999')->assertJsonPath('status', 'tidak_dikenal');

        $this->jadwal->periode->update(['rapor_di_sini' => false]);
        $res = $this->api('rapor?classroom_id=77');
        $this->assertSame('tidak_dipakai', $res->json('status'));
        $this->assertSame([], $res->json('students'));
    }
}
