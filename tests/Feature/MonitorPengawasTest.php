<?php

namespace Tests\Feature;

use App\Models\AksiPengawas;
use App\Models\Pelanggaran;
use App\Models\Pengampu;
use App\Models\SesiUjian;
use App\Models\User;
use App\Services\LayananPelanggaran;
use App\Services\LayananSesi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class MonitorPengawasTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private User $pengawas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 3);
        $this->pengawas = User::factory()->pengawas()->create();
        $this->jadwal->pengawas()->sync([$this->pengawas->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function urlAksi(SesiUjian $sesi, string $aksi): string
    {
        return "/staf/monitor/{$this->jadwal->id}/sesi/{$sesi->id}/{$aksi}";
    }

    private function aksi(User $pelaku, SesiUjian $sesi, string $aksi, array $data = [])
    {
        return $this->actingAs($pelaku, 'web')->postJson($this->urlAksi($sesi, $aksi), $data);
    }

    /** Sesi berlangsung yang sudah melewati masa abaikan awal. */
    private function berjalan(int $i = 0): array
    {
        $token = $this->mulaiSesi($this->siswa[$i]);
        Carbon::setTestNow(now()->addSeconds(10));

        return [$this->sesiDari($this->siswa[$i]), $token];
    }

    private function blokirkan(SesiUjian $sesi): void
    {
        $layanan = app(LayananPelanggaran::class);
        for ($i = 0; $i < $this->jadwal->batas_pelanggaran; $i++) {
            $layanan->catat($sesi->fresh(), 'pindah_tab', null);
            Carbon::setTestNow(now()->addSeconds(10));
        }
        $this->assertSame(SesiUjian::DIBLOKIR, $sesi->fresh()->status);
    }

    // ── Hak akses ───────────────────────────────────────────────────────────

    public function test_admin_dan_pengawas_tertugas_boleh_melihat_pengawas_lain_tidak(): void
    {
        $lain = User::factory()->pengawas()->create();

        $this->actingAs($this->admin, 'web')->get("/staf/monitor/{$this->jadwal->id}")->assertOk()->assertSee($this->jadwal->token);
        $this->actingAs($this->pengawas, 'web')->get("/staf/monitor/{$this->jadwal->id}")->assertOk();
        $this->actingAs($lain, 'web')->get("/staf/monitor/{$this->jadwal->id}")->assertNotFound();
        $this->actingAs($lain, 'web')->getJson("/staf/monitor/{$this->jadwal->id}/data")->assertNotFound();
    }

    public function test_daftar_hanya_memuat_jadwal_yang_boleh_dilihat(): void
    {
        $lain = User::factory()->pengawas()->create();

        $this->actingAs($this->pengawas, 'web')->get('/staf/monitor')->assertOk()->assertSee('UTS Matematika');
        $this->actingAs($lain, 'web')->get('/staf/monitor')->assertOk()->assertDontSee('UTS Matematika');
    }

    public function test_guru_mapel_boleh_aktifkan_kembali_dan_paksa_selesai_tapi_tidak_reset_atau_tambah_waktu(): void
    {
        $guru = User::factory()->create();
        Pengampu::create(['guru_id' => $guru->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->kelas->id]);
        $guruLain = User::factory()->create();
        [$sesi] = $this->berjalan();
        $this->blokirkan($sesi);
        $sesi = $sesi->fresh();

        $this->aksi($guruLain, $sesi, 'aktifkan')->assertForbidden();
        $this->aksi($guru, $sesi, 'reset')->assertForbidden();
        $this->aksi($guru, $sesi, 'waktu', ['menit' => 5])->assertForbidden();
        $this->assertSame(SesiUjian::DIBLOKIR, $sesi->fresh()->status);

        $this->aksi($guru, $sesi, 'aktifkan')->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(SesiUjian::BERLANGSUNG, $sesi->fresh()->status);
        $this->assertDatabaseHas('aksi_pengawas', ['sesi_id' => $sesi->id, 'user_id' => $guru->id, 'aksi' => 'aktifkan_kembali']);

        $this->aksi($guru, $sesi, 'selesai')->assertOk();
        $this->assertSame(SesiUjian::SELESAI, $sesi->fresh()->status);

        $html = $this->actingAs($guru, 'web')->get("/staf/monitor/{$this->jadwal->id}")->assertOk()->assertSee('data-boleh="aktifkan,selesai"', false)->getContent();
        $this->assertStringNotContainsString($this->jadwal->token, $html);
    }

    public function test_guru_mapel_tidak_melihat_token_dan_tidak_bisa_reset(): void
    {
        $guru = User::factory()->create();
        Pengampu::create(['guru_id' => $guru->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->kelas->id]);
        $guruLain = User::factory()->create();
        [$sesi] = $this->berjalan();

        $this->actingAs($guru, 'web')->get("/staf/monitor/{$this->jadwal->id}")->assertOk()->assertDontSee($this->jadwal->token);
        $this->actingAs($guruLain, 'web')->get("/staf/monitor/{$this->jadwal->id}")->assertNotFound();
        $this->aksi($guru, $sesi, 'reset')->assertForbidden();
        $this->assertNotNull($sesi->fresh()->token_perangkat);
        $this->assertSame(0, AksiPengawas::count());
    }

    public function test_siswa_dan_tamu_tidak_bisa_masuk(): void
    {
        $this->get("/staf/monitor/{$this->jadwal->id}")->assertRedirect();
        $this->actingAs($this->siswa[0], 'siswa')->get("/staf/monitor/{$this->jadwal->id}")->assertRedirect();
    }

    public function test_pengawas_lain_tidak_bisa_bertindak(): void
    {
        $lain = User::factory()->pengawas()->create();
        [$sesi] = $this->berjalan();

        $this->aksi($lain, $sesi, 'selesai')->assertForbidden();
        $this->assertSame(SesiUjian::BERLANGSUNG, $sesi->fresh()->status);
    }

    public function test_sesi_dari_jadwal_lain_ditolak(): void
    {
        [$sesi] = $this->berjalan();
        $lain = $this->jadwal->replicate(['token', 'media_kode']);
        $lain->nama = 'Lain';
        $lain->save();
        $lain->pengawas()->sync([$this->pengawas->id]);

        $this->actingAs($this->pengawas, 'web')->postJson("/staf/monitor/{$lain->id}/sesi/{$sesi->id}/reset")->assertNotFound();
    }

    // ── Data ────────────────────────────────────────────────────────────────

    public function test_data_merangkum_peserta(): void
    {
        [$sesi, $token] = $this->berjalan();
        $this->mulaiSesi($this->siswa[1]);
        app(LayananPelanggaran::class)->catat($sesi->fresh(), 'pindah_tab', 'ke aplikasi lain');
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$this->soal['s1']->id => ['o' => 'A', 'w' => now()->getTimestampMs()]]], $this->header($token))->assertOk();

        $d = $this->actingAs($this->pengawas, 'web')->getJson("/staf/monitor/{$this->jadwal->id}/data")->assertOk()->json();

        $this->assertSame(['mengerjakan' => 2, 'selesai' => 0, 'melanggar' => 1, 'terblokir' => 0, 'belum' => 1, 'senyap' => 0], $d['ringkasan']);
        $this->assertSame(3, $d['total_soal']);
        $this->assertCount(3, $d['peserta']);
        $baris = collect($d['peserta'])->firstWhere('id', $sesi->id);
        $this->assertSame(1, $baris['pelanggaran']);
        $this->assertSame('Pindah aplikasi atau tab', $baris['p_jenis']);
        $this->assertSame(1, $baris['dijawab']);
        $this->assertSame('X A', $baris['kelas']);
        $this->assertSame('peramban', $baris['platform']);
        $this->assertArrayNotHasKey('token_perangkat', $baris);
    }

    public function test_senyap_bila_lama_tanpa_kabar(): void
    {
        [$sesi] = $this->berjalan();
        Carbon::setTestNow(now()->addSeconds(120));

        $d = $this->actingAs($this->pengawas, 'web')->getJson("/staf/monitor/{$this->jadwal->id}/data")->json();

        $this->assertSame(1, $d['ringkasan']['senyap']);
        $this->assertTrue(collect($d['peserta'])->firstWhere('id', $sesi->id)['senyap']);
    }

    public function test_data_disimpan_sebentar_di_cache_dan_diperbarui_oleh_tindakan(): void
    {
        [$sesi] = $this->berjalan();
        $this->actingAs($this->pengawas, 'web')->getJson("/staf/monitor/{$this->jadwal->id}/data")->assertJsonPath('ringkasan.mengerjakan', 1);

        $this->mulaiSesi($this->siswa[1]); // perubahan tanpa tindakan pengawas: belum terlihat selama cache
        $this->getJson("/staf/monitor/{$this->jadwal->id}/data")->assertJsonPath('ringkasan.mengerjakan', 1);

        $this->aksi($this->pengawas, $sesi, 'waktu', ['menit' => 5])->assertOk(); // tindakan membuang cache
        $this->getJson("/staf/monitor/{$this->jadwal->id}/data")->assertJsonPath('ringkasan.mengerjakan', 2);
    }

    public function test_pembaruan_monitor_menutup_sesi_kedaluwarsa(): void
    {
        [$sesi] = $this->berjalan();
        Carbon::setTestNow(now()->addMinutes(70));

        $this->actingAs($this->pengawas, 'web')->getJson("/staf/monitor/{$this->jadwal->id}/data")->assertOk()
            ->assertJsonPath('ringkasan.selesai', 1);
        $this->assertSame(SesiUjian::SELESAI, $sesi->fresh()->status);
        $this->assertNotNull($sesi->fresh()->hasil);
    }

    public function test_data_jadwal_tidak_aktif_ditolak(): void
    {
        $this->jadwal->update(['status' => 'selesai']);

        // Jadwal selesai tidak lagi termasuk daftar pantauan.
        $this->actingAs($this->admin, 'web')->getJson("/staf/monitor/{$this->jadwal->id}/data")->assertNotFound();
    }

    // ── Tindakan ────────────────────────────────────────────────────────────

    public function test_aktifkan_kembali_membuka_blokir_dengan_satu_kesempatan(): void
    {
        [$sesi, $token] = $this->berjalan();
        $this->blokirkan($sesi);

        $this->aksi($this->pengawas, $sesi, 'aktifkan')->assertOk()->assertJsonPath('ok', true);

        $s = $sesi->fresh();
        $this->assertSame(SesiUjian::BERLANGSUNG, $s->status);
        $this->assertSame($this->jadwal->batas_pelanggaran - 1, $s->jumlah_pelanggaran);
        $this->assertDatabaseHas('aksi_pengawas', ['sesi_id' => $sesi->id, 'user_id' => $this->pengawas->id, 'aksi' => 'aktifkan_kembali']);
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", [], $this->header($token))->assertOk()->assertJsonPath('status', 'berlangsung');

        // Satu pelanggaran lagi memblokir kembali.
        app(LayananPelanggaran::class)->catat($s, 'pindah_tab', null);
        $this->assertSame(SesiUjian::DIBLOKIR, $sesi->fresh()->status);
    }

    public function test_aktifkan_kembali_ditolak_bila_tidak_terblokir(): void
    {
        [$sesi] = $this->berjalan();

        $this->aksi($this->pengawas, $sesi, 'aktifkan')->assertStatus(409)->assertJsonPath('kode', 'bukan_blokir');
        $this->assertSame(0, AksiPengawas::count());
    }

    public function test_reset_perangkat_memindahkan_sesi_ke_perangkat_baru(): void
    {
        [$sesi, $lama] = $this->berjalan();
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$this->soal['s1']->id => ['o' => 'A', 'w' => now()->getTimestampMs()]]], $this->header($lama))->assertOk();
        $batas = $sesi->fresh()->batas_selesai;

        $this->aksi($this->pengawas, $sesi, 'reset')->assertOk();

        $this->assertNull($sesi->fresh()->token_perangkat);
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", [], $this->header($lama))->assertStatus(401);

        // Perangkat baru (tanpa cookie) masuk dengan token ujian dan mengambil alih sesi yang sama.
        $baru = app(LayananSesi::class)->mulai($sesi->fresh(), null, Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'HpPengganti']));
        $this->assertNotSame($lama, $baru);
        $this->assertSame('HpPengganti', $sesi->fresh()->info_peramban);
        $this->assertEquals($batas, $sesi->fresh()->batas_selesai);
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", [], $this->header($baru))->assertOk();
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", [], $this->header($lama))->assertStatus(401);
        $this->assertSame('A', $sesi->jawaban()->first()->opsi_label);

        // Perangkat ketiga kini ditolak lagi.
        $this->expectExceptionMessage('perangkat lain');
        app(LayananSesi::class)->mulai($sesi->fresh(), null, Request::create('/'));
    }

    public function test_siswa_dengan_perangkat_dilepas_diarahkan_ke_halaman_token(): void
    {
        [$sesi] = $this->berjalan();

        // Sebelum reset: perangkat lain ditolak.
        $this->actingAs($this->siswa[0], 'siswa')->get(route('siswa.ujian.token', $this->jadwal))->assertOk()->assertSee('perangkat lain');

        $this->aksi($this->pengawas, $sesi, 'reset')->assertOk();

        $this->actingAs($this->siswa[0], 'siswa')->get(route('siswa.ujian.token', $this->jadwal))->assertOk()->assertDontSee('perangkat lain')->assertSee('Token');
        $this->post(route('siswa.ujian.token.kirim', $this->jadwal), ['token' => $this->jadwal->token])->assertRedirect(route('siswa.ujian.siap', $this->jadwal));
        $r = $this->post(route('siswa.ujian.mulai', $this->jadwal))->assertRedirect(route('siswa.ujian.kerjakan', $this->jadwal));
        $this->assertNotNull($r->getCookie("ujian_p{$sesi->id}"));
        $this->assertNotNull($sesi->fresh()->token_perangkat);
    }

    public function test_reset_perangkat_hanya_untuk_sesi_berjalan(): void
    {
        $belum = $this->sesiDari($this->siswa[2]);

        $this->aksi($this->pengawas, $belum, 'reset')->assertStatus(409);
        $this->assertSame(0, AksiPengawas::count());
    }

    public function test_tambah_waktu_memperpanjang_batas_dan_tercatat(): void
    {
        [$sesi, $token] = $this->berjalan();
        $batas = $sesi->batas_selesai;

        $this->aksi($this->pengawas, $sesi, 'waktu', ['menit' => 15])->assertOk();

        $s = $sesi->fresh();
        $this->assertSame(15, $s->tambahan_menit);
        $this->assertEquals($batas->copy()->addMinutes(15), $s->batas_selesai);
        $this->assertDatabaseHas('aksi_pengawas', ['sesi_id' => $sesi->id, 'aksi' => 'tambah_waktu', 'detail' => '+15 menit']);

        $this->aksi($this->pengawas, $sesi, 'waktu', ['menit' => 5])->assertOk();
        $this->assertSame(20, $sesi->fresh()->tambahan_menit);
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", [], $this->header($token))->assertOk()
            ->assertJsonPath('batas_selesai', $sesi->fresh()->batas_selesai->getTimestampMs());
    }

    public function test_tambah_waktu_untuk_siswa_belum_mulai_berlaku_saat_mulai(): void
    {
        $sesi = $this->sesiDari($this->siswa[2]);

        $this->aksi($this->pengawas, $sesi, 'waktu', ['menit' => 10])->assertOk();
        $this->assertNull($sesi->fresh()->batas_selesai);

        $this->mulaiSesi($this->siswa[2]);
        $s = $sesi->fresh();
        $this->assertEquals($s->mulai_pada->copy()->addMinutes(70), $s->batas_selesai);
    }

    public function test_tambah_waktu_memvalidasi_menit(): void
    {
        [$sesi] = $this->berjalan();

        foreach ([0, -5, 121, 'abc', null] as $menit) {
            $this->aksi($this->pengawas, $sesi, 'waktu', ['menit' => $menit])->assertStatus(422);
        }
        $this->assertSame(0, $sesi->fresh()->tambahan_menit);
        $this->assertSame(0, AksiPengawas::count());
    }

    public function test_tambah_waktu_ditolak_untuk_sesi_selesai(): void
    {
        [$sesi] = $this->berjalan();
        app(LayananSesi::class)->selesaikan($sesi, 'kirim');

        $this->aksi($this->pengawas, $sesi, 'waktu', ['menit' => 5])->assertStatus(409)->assertJsonPath('kode', 'sudah_selesai');
    }

    public function test_paksa_selesai_menilai_dan_menutup_sesi(): void
    {
        [$sesi, $token] = $this->berjalan();
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => $this->jawabanBenar()], $this->header($token))->assertOk();

        $this->aksi($this->pengawas, $sesi, 'selesai')->assertOk();

        $s = $sesi->fresh();
        $this->assertSame(SesiUjian::SELESAI, $s->status);
        $this->assertSame('dipaksa', $s->alasan_selesai);
        $this->assertSame('100.00', $s->hasil->skor_pg);
        $this->assertDatabaseHas('aksi_pengawas', ['sesi_id' => $sesi->id, 'aksi' => 'paksa_selesai']);
        $this->aksi($this->pengawas, $sesi, 'selesai')->assertStatus(409); // sudah selesai
    }

    public function test_paksa_selesai_juga_untuk_sesi_terblokir(): void
    {
        [$sesi] = $this->berjalan();
        $this->blokirkan($sesi);

        $this->aksi($this->pengawas, $sesi, 'selesai')->assertOk();
        $this->assertSame(SesiUjian::SELESAI, $sesi->fresh()->status);
    }

    public function test_aksi_tidak_dikenal_ditolak(): void
    {
        [$sesi] = $this->berjalan();

        $this->actingAs($this->pengawas, 'web')->postJson($this->urlAksi($sesi, 'hapus'))->assertNotFound();
        $this->assertSame(0, Pelanggaran::where('sesi_id', 999)->count());
    }

    public function test_tindakan_butuh_login(): void
    {
        [$sesi] = $this->berjalan();

        $this->postJson($this->urlAksi($sesi, 'reset'))->assertUnauthorized();
        $this->assertNotNull($sesi->fresh()->token_perangkat);
    }
}
