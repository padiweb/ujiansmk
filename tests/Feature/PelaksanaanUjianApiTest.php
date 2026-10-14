<?php

namespace Tests\Feature;

use App\Models\Jawaban;
use App\Models\SesiUjian;
use App\Models\Siswa;
use App\Services\LayananSesi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class PelaksanaanUjianApiTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Autentikasi perangkat ───────────────────────────────────────────────

    public function test_api_menolak_tanpa_atau_dengan_token_perangkat_salah(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        $this->getJson("/api/ujian/{$sesi->id}/paket")->assertStatus(401);
        $this->getJson("/api/ujian/{$sesi->id}/paket", ['X-Perangkat' => 'salah'])->assertStatus(401);
        $this->getJson("/api/ujian/{$sesi->id}/paket", ['X-Perangkat' => ''])->assertStatus(401);
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", [], ['X-Perangkat' => 'salah'])->assertStatus(401);
        $this->postJson("/api/ujian/{$sesi->id}/kirim", [], ['X-Perangkat' => 'salah'])->assertStatus(401);
        $this->getJson("/api/ujian/{$sesi->id}/paket", $this->header($token))->assertOk();
    }

    public function test_token_siswa_lain_tidak_bisa_dipakai_dan_jawaban_sama_untuk_sesi_tidak_ada(): void
    {
        $this->siapkanUjian();
        $tokenA = $this->mulaiSesi($this->siswa[0]);
        $this->mulaiSesi($this->siswa[1]);
        $sesiB = $this->sesiDari($this->siswa[1]);

        $this->getJson("/api/ujian/{$sesiB->id}/paket", $this->header($tokenA))->assertStatus(401);
        // Sesi yang tidak ada dijawab persis sama (tidak membocorkan keberadaan sesi).
        $a = $this->getJson("/api/ujian/{$sesiB->id}/paket", $this->header($tokenA));
        $b = $this->getJson('/api/ujian/999999/paket', $this->header($tokenA));
        $this->assertSame($a->status(), $b->status());
        $this->assertSame($a->json(), $b->json());
    }

    public function test_sesi_yang_belum_dimulai_atau_direset_perangkatnya_tidak_bisa_diakses(): void
    {
        $this->siapkanUjian();
        $sesi = $this->sesiDari($this->siswa[0]);

        $this->getJson("/api/ujian/{$sesi->id}/paket", $this->header('apa-saja'))->assertStatus(401);

        $token = $this->mulaiSesi($this->siswa[0]);
        $this->getJson("/api/ujian/{$sesi->id}/paket", $this->header($token))->assertOk();

        // Pengawas mereset perangkat: token lama tidak berlaku lagi.
        $this->sesiDari($this->siswa[0])->update(['token_perangkat' => null]);
        $this->getJson("/api/ujian/{$sesi->id}/paket", $this->header($token))->assertStatus(401);
    }

    public function test_api_tidak_memakai_sesi_cookie_atau_csrf(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        $r = $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => []], $this->header($token))->assertOk();

        $this->assertEmpty($r->headers->getCookies(), 'Titik akhir ujian tidak boleh menulis cookie/sesi.');
    }

    // ── Paket soal ──────────────────────────────────────────────────────────

    public function test_paket_tidak_memuat_kunci_atau_jawaban_acuan(): void
    {
        $this->siapkanUjian(denganEsai: true);
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        $teks = $this->getJson("/api/ujian/{$sesi->id}/paket", $this->header($token))->assertOk()->getContent();

        foreach (['benar', 'kunci', 'rubrik', 'RAHASIA-RUBRIK', 'bobot'] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $teks, "Paket tidak boleh memuat \"{$terlarang}\".");
        }
    }

    public function test_paket_berisi_semua_soal_pg_dulu_lalu_esai_dan_opsi_lengkap(): void
    {
        $this->siapkanUjian(denganEsai: true);
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        $paket = $this->getJson("/api/ujian/{$sesi->id}/paket", $this->header($token))->assertOk()->json();

        $this->assertSame(['pg', 'pg', 'pg', 'esai'], array_column($paket['soal'], 'tipe'));
        $this->assertEqualsCanonicalizing(array_map(fn ($s) => $s->id, $this->soal), array_column($paket['soal'], 'id'));
        foreach (array_filter($paket['soal'], fn ($s) => $s['tipe'] === 'pg') as $s) {
            $this->assertEqualsCanonicalizing(['A', 'B', 'C', 'D'], array_column($s['opsi'], 'label'));
        }
        $this->assertSame([], array_values(array_filter($paket['soal'], fn ($s) => $s['tipe'] === 'esai')[3]['opsi']));
        $this->assertSame('Matematika', $paket['jadwal']['mapel']);
        $this->assertSame('berlangsung', $paket['keadaan']['status']);
    }

    public function test_urutan_soal_dan_opsi_berbeda_antar_siswa_tetapi_tetap_untuk_siswa_yang_sama(): void
    {
        $this->siapkanUjian(jumlahSiswa: 2);
        // Tambah soal agar peluang urutan kebetulan sama sangat kecil.
        $bank = $this->soal['s1']->bank;
        $layanan = app(\App\Services\LayananSoal::class);
        $this->jadwal->update(['status' => 'draf', 'token' => null]);
        $this->jadwal->sesi()->delete();
        $this->jadwal->soalBeku()->delete();
        foreach (range(4, 12) as $n) {
            $s = $bank->soal()->create(['tipe' => 'pg', 'pertanyaan' => "Soal tambahan {$n}", 'bobot' => 1, 'status' => 'siap', 'sumber' => 'manual', 'urutan' => $n]);
            $layanan->setOpsi($s, ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'], 'A');
        }
        app(\App\Services\LayananJadwal::class)->aktifkan($this->jadwal);
        $this->jadwal->refresh();

        $tokenA = $this->mulaiSesi($this->siswa[0]);
        $tokenB = $this->mulaiSesi($this->siswa[1]);
        $a = $this->sesiDari($this->siswa[0]);
        $b = $this->sesiDari($this->siswa[1]);

        $paketA = $this->getJson("/api/ujian/{$a->id}/paket", $this->header($tokenA))->json();
        $paketA2 = $this->getJson("/api/ujian/{$a->id}/paket", $this->header($tokenA))->json();
        $paketB = $this->getJson("/api/ujian/{$b->id}/paket", $this->header($tokenB))->json();

        $this->assertSame(array_column($paketA['soal'], 'id'), array_column($paketA2['soal'], 'id'), 'Urutan siswa yang sama harus stabil.');
        $this->assertNotSame(array_column($paketA['soal'], 'id'), array_column($paketB['soal'], 'id'), 'Urutan soal harus berbeda antar siswa.');
        $urutanOpsi = fn ($p) => array_map(fn ($s) => implode('', array_column($s['opsi'], 'label')), $p['soal']);
        $this->assertNotSame($urutanOpsi($paketA), $urutanOpsi($paketB), 'Urutan opsi harus berbeda antar siswa.');
    }

    public function test_acak_dimatikan_menjaga_urutan_asli(): void
    {
        $this->siapkanUjian(['acak_soal' => false, 'acak_opsi' => false]);
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        $paket = $this->getJson("/api/ujian/{$sesi->id}/paket", $this->header($token))->json();

        $this->assertSame([$this->soal['s1']->id, $this->soal['s2']->id, $this->soal['s3']->id], array_column($paket['soal'], 'id'));
        $this->assertSame(['A', 'B', 'C', 'D'], array_column($paket['soal'][0]['opsi'], 'label'));
    }

    public function test_paket_memuat_jawaban_yang_sudah_tersimpan(): void
    {
        $this->siapkanUjian(denganEsai: true);
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [
            $this->soal['s1']->id => ['o' => 'C', 'w' => now()->getTimestampMs()],
            $this->soal['esai']->id => ['t' => 'Jawaban saya', 'w' => now()->getTimestampMs()],
        ]], $this->header($token))->assertOk();

        $paket = $this->getJson("/api/ujian/{$sesi->id}/paket", $this->header($token))->json();

        $this->assertSame('C', $paket['jawaban'][$this->soal['s1']->id]['o']);
        $this->assertSame('Jawaban saya', $paket['jawaban'][$this->soal['esai']->id]['t']);
    }

    // ── Sinkron jawaban ─────────────────────────────────────────────────────

    public function test_sinkron_menyimpan_jawaban_dan_memperbarui_terakhir_aktif(): void
    {
        $this->siapkanUjian(denganEsai: true);
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $sesi->update(['terakhir_aktif' => now()->subMinutes(5)]);

        $r = $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [
            $this->soal['s1']->id => ['o' => 'A', 'w' => now()->getTimestampMs()],
            $this->soal['esai']->id => ['t' => 'Bilangan prima.', 'w' => now()->getTimestampMs()],
        ]], $this->header($token))->assertOk();

        $this->assertSame('A', Jawaban::where('sesi_id', $sesi->id)->where('soal_id', $this->soal['s1']->id)->value('opsi_label'));
        $this->assertSame('Bilangan prima.', Jawaban::where('sesi_id', $sesi->id)->where('soal_id', $this->soal['esai']->id)->value('teks_esai'));
        $this->assertTrue($sesi->fresh()->terakhir_aktif->gt(now()->subMinute()));

        $keadaan = $r->json();
        $this->assertSame('berlangsung', $keadaan['status']);
        $this->assertEqualsWithDelta(microtime(true) * 1000, $keadaan['waktu_server'], 5000);
        $this->assertSame($sesi->fresh()->batas_selesai->getTimestampMs(), $keadaan['batas_selesai']);
        $this->assertSame(20, $keadaan['selang']);
    }

    public function test_sinkron_mengubah_jawaban_yang_sama_tanpa_menggandakan_baris(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $id = $this->soal['s1']->id;

        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$id => ['o' => 'A', 'w' => now()->getTimestampMs()]]], $this->header($token));
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$id => ['o' => 'D', 'w' => now()->getTimestampMs() + 1000]]], $this->header($token));

        $this->assertSame(1, Jawaban::where('sesi_id', $sesi->id)->count());
        $this->assertSame('D', Jawaban::where('sesi_id', $sesi->id)->value('opsi_label'));
    }

    public function test_jawaban_boleh_dikosongkan(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $id = $this->soal['s1']->id;
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$id => ['o' => 'A', 'w' => now()->getTimestampMs()]]], $this->header($token));

        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$id => ['o' => null, 'w' => now()->getTimestampMs() + 500]]], $this->header($token));

        $this->assertNull(Jawaban::where('sesi_id', $sesi->id)->value('opsi_label'));
    }

    public function test_kiriman_basi_tidak_menimpa_jawaban_yang_lebih_baru(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $id = $this->soal['s1']->id;
        $baru = now()->getTimestampMs();

        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$id => ['o' => 'B', 'w' => $baru]]], $this->header($token));
        // Kiriman lama (jaringan lambat/ulang) tiba belakangan.
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$id => ['o' => 'A', 'w' => $baru - 30000]]], $this->header($token));

        $this->assertSame('B', Jawaban::where('sesi_id', $sesi->id)->value('opsi_label'));
    }

    public function test_masukan_tidak_valid_diabaikan(): void
    {
        $this->siapkanUjian(denganEsai: true);
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [
            $this->soal['s1']->id => ['o' => 'Z', 'w' => now()->getTimestampMs()],     // label bukan opsi soal
            $this->soal['s2']->id => ['o' => ['A'], 'w' => now()->getTimestampMs()],   // bukan teks
            999999 => ['o' => 'A', 'w' => now()->getTimestampMs()],                    // soal bukan bagian ujian
            $this->soal['s3']->id => 'bukan-larik',
            $this->soal['esai']->id => ['t' => str_repeat('x', 6000), 'w' => now()->getTimestampMs()], // terlalu panjang: dipotong
        ]], $this->header($token))->assertOk();

        $this->assertSame(1, Jawaban::where('sesi_id', $sesi->id)->count());
        $this->assertSame(5000, mb_strlen(Jawaban::where('sesi_id', $sesi->id)->value('teks_esai')));
    }

    public function test_jawaban_soal_milik_ujian_lain_tidak_bisa_disusupkan(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $asing = \App\Models\Soal::create(['bank_soal_id' => $this->soal['s1']->bank_soal_id, 'tipe' => 'pg', 'pertanyaan' => 'Bukan bagian ujian', 'bobot' => 1, 'status' => 'siap', 'sumber' => 'manual', 'urutan' => 99]);

        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$asing->id => ['o' => 'A', 'w' => now()->getTimestampMs()]]], $this->header($token))->assertOk();

        $this->assertSame(0, Jawaban::where('sesi_id', $sesi->id)->count());
    }

    public function test_jawaban_per_sinkron_dibatasi_lima_ratus(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $banyak = [];
        foreach (range(1, 600) as $i) {
            $banyak[1000000 + $i] = ['o' => 'A'];
        }

        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => $banyak], $this->header($token))->assertOk();

        $this->assertSame(0, Jawaban::where('sesi_id', $sesi->id)->count()); // semuanya bukan soal ujian ini
    }

    public function test_permintaan_terlalu_besar_ditolak(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [], 'sampah' => str_repeat('x', 3_100_000)], $this->header($token))->assertStatus(413);
    }

    // ── Waktu ───────────────────────────────────────────────────────────────

    public function test_batas_selesai_dihitung_server_dari_saat_mulai_ditambah_durasi(): void
    {
        $this->siapkanUjian(['durasi_menit' => 45]);
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->jadwal->update(['mulai_pada' => now()->subHour(), 'tutup_pada' => now()->addHours(3)]);

        $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        $this->assertSame('2026-10-20 09:00:00', $sesi->mulai_pada->toDateTimeString());
        $this->assertSame('2026-10-20 09:45:00', $sesi->batas_selesai->toDateTimeString());
    }

    public function test_sinkron_dalam_masa_toleransi_diterima_dan_ditandai_terlambat(): void
    {
        $this->siapkanUjian(['durasi_menit' => 30]);
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        Carbon::setTestNow($sesi->batas_selesai->copy()->addSeconds(40)); // lewat batas, belum lewat 90 dtk

        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$this->soal['s1']->id => ['o' => 'A', 'w' => now()->getTimestampMs()]]], $this->header($token))
            ->assertOk()->assertJsonPath('status', 'berlangsung');

        $this->assertTrue((bool) Jawaban::where('sesi_id', $sesi->id)->value('terlambat'));
    }

    public function test_sinkron_setelah_toleransi_menutup_sesi_dan_menolak_jawaban(): void
    {
        $this->siapkanUjian(['durasi_menit' => 30]);
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$this->soal['s1']->id => ['o' => 'A', 'w' => now()->getTimestampMs()]]], $this->header($token));
        Carbon::setTestNow($sesi->batas_selesai->copy()->addSeconds(120));

        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$this->soal['s2']->id => ['o' => 'B', 'w' => now()->getTimestampMs()]]], $this->header($token))
            ->assertOk()->assertJsonPath('status', 'selesai');

        $sesi->refresh();
        $this->assertSame('selesai', $sesi->status);
        $this->assertSame('waktu_habis', $sesi->alasan_selesai);
        $this->assertSame(1, Jawaban::where('sesi_id', $sesi->id)->count(), 'Jawaban setelah toleransi tidak boleh tersimpan.');
        $this->assertNotNull($sesi->hasil);
    }

    public function test_sapuan_menutup_sesi_kedaluwarsa_tanpa_cron_dan_hanya_satu_penyapu_per_30_detik(): void
    {
        $this->siapkanUjian(['durasi_menit' => 30], jumlahSiswa: 3);
        foreach ($this->siswa as $s) {
            $this->mulaiSesi($s);
        }
        $sesi = $this->sesiDari($this->siswa[0]);
        Carbon::setTestNow($sesi->batas_selesai->copy()->addMinutes(5));
        Cache::forget('ujian:sapu');

        $layanan = app(LayananSesi::class);
        $this->assertSame(3, $layanan->sapuKedaluwarsa());
        $this->assertSame(0, SesiUjian::where('status', 'berlangsung')->count());
        $this->assertSame(3, \App\Models\HasilUjian::count());

        // Penyapu kedua dalam 30 detik tidak berjalan walau ada sesi kedaluwarsa baru.
        SesiUjian::query()->update(['status' => 'berlangsung']);
        $this->assertSame(0, $layanan->sapuKedaluwarsa());
    }

    public function test_sesi_yang_belum_kedaluwarsa_tidak_disapu(): void
    {
        $this->siapkanUjian();
        $this->mulaiSesi($this->siswa[0]);
        Cache::forget('ujian:sapu');

        $this->assertSame(0, app(LayananSesi::class)->sapuKedaluwarsa());
        $this->assertSame('berlangsung', $this->sesiDari($this->siswa[0])->status);
    }

    // ── Kirim dan penilaian ─────────────────────────────────────────────────

    public function test_kirim_menilai_pg_dengan_bobot(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        // s1 (bobot 1) benar, s2 (bobot 1) salah, s3 (bobot 2) benar => (1 + 2) / 4 = 75.
        $r = $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => [
            $this->soal['s1']->id => ['o' => 'A', 'w' => now()->getTimestampMs()],
            $this->soal['s2']->id => ['o' => 'D', 'w' => now()->getTimestampMs()],
            $this->soal['s3']->id => ['o' => 'C', 'w' => now()->getTimestampMs()],
        ]], $this->header($token))->assertOk();

        $r->assertJsonPath('selesai', true)->assertJsonPath('status', 'selesai')
            ->assertJsonPath('hasil.skor_pg', 75)->assertJsonPath('hasil.benar', 2)->assertJsonPath('hasil.salah', 1)
            ->assertJsonPath('hasil.kosong', 0)->assertJsonPath('hasil.menunggu_esai', false);

        $sesi->refresh();
        $this->assertSame('selesai', $sesi->status);
        $this->assertSame('kirim', $sesi->alasan_selesai);
        $hasil = $sesi->hasil;
        $this->assertEquals(75, $hasil->skor_pg);
        $this->assertEquals(75, $hasil->skor_akhir);
        $this->assertSame('tanpa_esai', $hasil->status_koreksi);
        $this->assertSame('Matematika', $hasil->mapel_nama);
        $this->assertSame('X A', $hasil->kelas_nama);
        $this->assertSame('UTS Matematika', $hasil->jadwal_nama);
    }

    public function test_soal_kosong_dihitung_kosong_dan_semua_benar_bernilai_seratus(): void
    {
        $this->siapkanUjian(jumlahSiswa: 2);
        $a = $this->mulaiSesi($this->siswa[0]);
        $b = $this->mulaiSesi($this->siswa[1]);
        $sesiA = $this->sesiDari($this->siswa[0]);
        $sesiB = $this->sesiDari($this->siswa[1]);

        $this->postJson("/api/ujian/{$sesiA->id}/kirim", ['jawaban' => []], $this->header($a))
            ->assertJsonPath('hasil.skor_pg', 0)->assertJsonPath('hasil.kosong', 3);
        $this->postJson("/api/ujian/{$sesiB->id}/kirim", ['jawaban' => $this->jawabanBenar()], $this->header($b))
            ->assertJsonPath('hasil.skor_pg', 100)->assertJsonPath('hasil.benar', 3);
    }

    public function test_jadwal_dengan_esai_menunggu_koreksi_dan_skor_akhir_kosong(): void
    {
        $this->siapkanUjian(denganEsai: true);
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        $r = $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => $this->jawabanBenar() + [
            $this->soal['esai']->id => ['t' => 'Jawaban esai', 'w' => now()->getTimestampMs()],
        ]], $this->header($token));

        $r->assertJsonPath('hasil.skor_pg', 100)->assertJsonPath('hasil.menunggu_esai', true);
        $hasil = $sesi->fresh()->hasil;
        $this->assertSame('belum_dikoreksi', $hasil->status_koreksi);
        $this->assertNull($hasil->skor_akhir);
        $this->assertSame('Jawaban esai', Jawaban::where('sesi_id', $sesi->id)->where('soal_id', $this->soal['esai']->id)->value('teks_esai'));
    }

    public function test_nilai_tidak_dikirim_bila_jadwal_menahan_hasil_tetapi_tetap_tersimpan(): void
    {
        $this->siapkanUjian(['tampilkan_hasil' => 'ditahan']);
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        $r = $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => $this->jawabanBenar()], $this->header($token))->assertOk();

        $r->assertJsonPath('hasil', null);
        $this->assertStringNotContainsString('skor', $r->getContent());
        $this->assertEquals(100, $sesi->fresh()->hasil->skor_pg);
    }

    public function test_kirim_dua_kali_tidak_menggandakan_atau_mengubah_nilai(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => $this->jawabanBenar()], $this->header($token))->assertOk();
        // Kiriman kedua (mis. jaringan mengulang) dengan isi berbeda tidak boleh mengubah apa pun.
        $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => [$this->soal['s1']->id => ['o' => 'D', 'w' => now()->getTimestampMs()]]], $this->header($token))
            ->assertOk()->assertJsonPath('hasil.skor_pg', 100);

        $this->assertSame(1, \App\Models\HasilUjian::count());
        $this->assertSame('A', Jawaban::where('sesi_id', $sesi->id)->where('soal_id', $this->soal['s1']->id)->value('opsi_label'));
    }

    public function test_sinkron_setelah_selesai_tidak_mengubah_jawaban(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => $this->jawabanBenar()], $this->header($token));

        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$this->soal['s1']->id => ['o' => 'D', 'w' => now()->getTimestampMs()]]], $this->header($token))
            ->assertOk()->assertJsonPath('status', 'selesai');

        $this->assertSame('A', Jawaban::where('sesi_id', $sesi->id)->where('soal_id', $this->soal['s1']->id)->value('opsi_label'));
    }

    public function test_kirim_setelah_toleransi_tidak_menyimpan_jawaban_baru_tetapi_menilai_yang_sudah_ada(): void
    {
        $this->siapkanUjian(['durasi_menit' => 30]);
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$this->soal['s1']->id => ['o' => 'A', 'w' => now()->getTimestampMs()]]], $this->header($token));
        Carbon::setTestNow($sesi->batas_selesai->copy()->addMinutes(10));

        $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => $this->jawabanBenar()], $this->header($token))->assertOk()->assertJsonPath('hasil.benar', 1);

        $this->assertSame('waktu_habis', $sesi->fresh()->alasan_selesai);
        $this->assertSame(1, Jawaban::where('sesi_id', $sesi->id)->count());
    }

    public function test_sesi_diblokir_tetap_menyimpan_jawaban_dan_melaporkan_statusnya(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $sesi->update(['status' => 'diblokir', 'jumlah_pelanggaran' => 3]);

        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => [$this->soal['s1']->id => ['o' => 'A', 'w' => now()->getTimestampMs()]]], $this->header($token))
            ->assertOk()->assertJsonPath('status', 'diblokir')->assertJsonPath('pelanggaran', 3);

        $this->assertSame(1, Jawaban::where('sesi_id', $sesi->id)->count());
    }

    // ── Selang sinkron menurut beban ────────────────────────────────────────

    public function test_selang_sinkron_menyesuaikan_jumlah_siswa_yang_mengerjakan(): void
    {
        $this->siapkanUjian();
        $layanan = app(LayananSesi::class);

        foreach ([[0, 20], [299, 20], [300, 40], [799, 40], [800, 60], [1499, 60], [1500, 90], [5000, 90]] as [$aktif, $selang]) {
            Cache::put('ujian:jumlah_aktif', $aktif, 15);

            $this->assertSame($selang, $layanan->selang(), "Dengan {$aktif} siswa aktif selang seharusnya {$selang} detik.");
        }
    }

    public function test_jumlah_siswa_aktif_dihitung_dari_basis_data_lalu_di_cache(): void
    {
        $this->siapkanUjian(jumlahSiswa: 2);
        Cache::forget('ujian:jumlah_aktif');
        $this->mulaiSesi($this->siswa[0]);
        $this->mulaiSesi($this->siswa[1]);

        app(LayananSesi::class)->selang();

        $this->assertSame(2, Cache::get('ujian:jumlah_aktif'));
    }
}
