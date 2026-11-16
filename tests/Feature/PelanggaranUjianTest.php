<?php

namespace Tests\Feature;

use App\Models\Pelanggaran;
use App\Models\SesiUjian;
use App\Models\Siswa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class PelanggaranUjianTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Majukan jam uji (kumulatif, karena jam sudah dibekukan setelah panggilan pertama). */
    private function maju(int $detik): void
    {
        Carbon::setTestNow(now()->addSeconds($detik));
    }

    /** Mulai sesi lalu lewati masa abaikan_awal agar pelanggaran bisa dihitung. */
    private function mulaiSiap(int $siswa = 0): array
    {
        $token = $this->mulaiSesi($this->siswa[$siswa]);
        $sesi = $this->sesiDari($this->siswa[$siswa]);
        $this->maju(10);

        return [$sesi, $token];
    }

    private function lapor(SesiUjian $sesi, string $token, string $jenis = 'pindah_tab', array $tambahan = [])
    {
        return $this->postJson("/api/ujian/{$sesi->id}/pelanggaran", ['jenis' => $jenis] + $tambahan, $this->header($token));
    }

    // ── Autentikasi dan validasi ────────────────────────────────────────────

    public function test_endpoint_butuh_token_perangkat(): void
    {
        $this->siapkanUjian();
        [$sesi] = $this->mulaiSiap();

        $this->postJson("/api/ujian/{$sesi->id}/pelanggaran", ['jenis' => 'pindah_tab'])->assertStatus(401);
        $this->postJson("/api/ujian/{$sesi->id}/pelanggaran", ['jenis' => 'pindah_tab'], ['X-Perangkat' => 'salah'])->assertStatus(401);
        $this->assertSame(0, Pelanggaran::count());
    }

    public function test_jenis_tidak_dikenal_atau_kosong_ditolak(): void
    {
        $this->siapkanUjian();
        [$sesi, $token] = $this->mulaiSiap();

        $this->lapor($sesi, $token, 'karangan_sendiri')->assertStatus(422);
        $this->postJson("/api/ujian/{$sesi->id}/pelanggaran", [], $this->header($token))->assertStatus(422);
        $this->lapor($sesi, $token, 'pindah_tab', ['keterangan' => str_repeat('x', 256)])->assertStatus(422);
        $this->assertSame(0, Pelanggaran::count());
        $this->assertSame(0, $sesi->fresh()->jumlah_pelanggaran);
    }

    // ── Hitungan dan blokir ─────────────────────────────────────────────────

    public function test_pelanggaran_pertama_dihitung_dan_dicatat(): void
    {
        $this->siapkanUjian();
        [$sesi, $token] = $this->mulaiSiap();

        $this->lapor($sesi, $token, 'pindah_tab', ['keterangan' => 'Pindah aplikasi', 'info' => ['durasi' => 4.2]])
            ->assertOk()->assertJsonPath('dicatat', true)->assertJsonPath('dihitung', true)
            ->assertJsonPath('ke', 1)->assertJsonPath('batas', 3)->assertJsonPath('status', 'berlangsung')->assertJsonPath('pelanggaran', 1);

        $this->assertSame(1, $sesi->fresh()->jumlah_pelanggaran);
        $baris = Pelanggaran::first();
        $this->assertSame('pindah_tab', $baris->jenis);
        $this->assertSame('Pindah aplikasi', $baris->keterangan);
        $this->assertTrue($baris->dihitung);
        $this->assertEquals(4.2, $baris->info['durasi']);
    }

    public function test_dua_peringatan_lalu_blokir_pada_pelanggaran_ketiga(): void
    {
        $this->siapkanUjian();
        [$sesi, $token] = $this->mulaiSiap();

        $this->lapor($sesi, $token, 'pindah_tab')->assertJsonPath('ke', 1)->assertJsonPath('status', 'berlangsung');
        $this->maju(10);
        $this->lapor($sesi, $token, 'keluar_layar_penuh')->assertJsonPath('ke', 2)->assertJsonPath('status', 'berlangsung');
        $this->maju(10);
        $this->lapor($sesi, $token, 'aplikasi_mengambang')->assertJsonPath('ke', 3)->assertJsonPath('status', 'diblokir');

        $this->assertSame('diblokir', $sesi->fresh()->status);
        $this->assertSame(3, $sesi->fresh()->jumlah_pelanggaran);
    }

    public function test_batas_pelanggaran_mengikuti_pengaturan_jadwal(): void
    {
        $this->siapkanUjian(['batas_pelanggaran' => 2]);
        [$sesi, $token] = $this->mulaiSiap();

        $this->lapor($sesi, $token)->assertJsonPath('status', 'berlangsung');
        $this->maju(10);
        $this->lapor($sesi, $token, 'keluar_layar_penuh')->assertJsonPath('status', 'diblokir');
    }

    public function test_batas_lima_membutuhkan_lima_pelanggaran(): void
    {
        $this->siapkanUjian(['batas_pelanggaran' => 5]);
        [$sesi, $token] = $this->mulaiSiap();

        foreach (['pindah_tab', 'keluar_layar_penuh', 'split_screen', 'tombol_kembali'] as $jenis) {
            $this->lapor($sesi, $token, $jenis)->assertJsonPath('status', 'berlangsung');
            $this->maju(10);
        }
        $this->lapor($sesi, $token, 'screenshot')->assertJsonPath('status', 'diblokir')->assertJsonPath('ke', 5);
    }

    public function test_hitungan_adalah_milik_server_dan_per_sesi(): void
    {
        $this->siapkanUjian(jumlahSiswa: 2);
        [$sesiA, $tokenA] = $this->mulaiSiap(0);
        [$sesiB, $tokenB] = $this->mulaiSiap(1);

        $this->lapor($sesiA, $tokenA)->assertJsonPath('ke', 1);
        $this->maju(10);
        $this->lapor($sesiA, $tokenA, 'keluar_layar_penuh')->assertJsonPath('ke', 2);
        $this->lapor($sesiB, $tokenB)->assertJsonPath('ke', 1);

        $this->assertSame(2, $sesiA->fresh()->jumlah_pelanggaran);
        $this->assertSame(1, $sesiB->fresh()->jumlah_pelanggaran);
        $this->assertSame('berlangsung', $sesiB->fresh()->status);
    }

    // ── Dedup dan jeda ──────────────────────────────────────────────────────

    public function test_pelanggaran_sejenis_dalam_8_detik_tidak_dihitung_dua_kali_tetapi_dicatat(): void
    {
        $this->siapkanUjian();
        [$sesi, $token] = $this->mulaiSiap();

        $this->lapor($sesi, $token, 'pindah_tab')->assertJsonPath('dihitung', true);
        $this->maju(5);
        $this->lapor($sesi, $token, 'pindah_tab')->assertJsonPath('dicatat', true)->assertJsonPath('dihitung', false)->assertJsonPath('ke', 1);
        $this->maju(4); // 9 detik sejak yang dihitung
        $this->lapor($sesi, $token, 'pindah_tab')->assertJsonPath('dihitung', true)->assertJsonPath('ke', 2);

        $this->assertSame(3, Pelanggaran::count());
        $this->assertSame(2, Pelanggaran::where('dihitung', true)->count());
    }

    public function test_jenis_berbeda_dalam_3_detik_dianggap_satu_kejadian(): void
    {
        $this->siapkanUjian();
        [$sesi, $token] = $this->mulaiSiap();

        $this->lapor($sesi, $token, 'pindah_tab')->assertJsonPath('ke', 1);
        $this->maju(1);
        $this->lapor($sesi, $token, 'aplikasi_mengambang')->assertJsonPath('dihitung', false)->assertJsonPath('ke', 1);
        $this->maju(3); // 4 detik sejak yang dihitung
        $this->lapor($sesi, $token, 'aplikasi_mengambang')->assertJsonPath('dihitung', true)->assertJsonPath('ke', 2);
    }

    public function test_beberapa_detik_pertama_setelah_mulai_diabaikan(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        $this->lapor($sesi, $token, 'keluar_layar_penuh')->assertJsonPath('dicatat', true)->assertJsonPath('dihitung', false)->assertJsonPath('ke', 0);
        $this->maju(6);
        $this->lapor($sesi, $token, 'keluar_layar_penuh')->assertJsonPath('dihitung', true)->assertJsonPath('ke', 1);
    }

    // ── Jenis informasi ─────────────────────────────────────────────────────

    public function test_jenis_informasi_hanya_dicatat_dan_tidak_pernah_memblokir(): void
    {
        $this->siapkanUjian(['batas_pelanggaran' => 1]);
        [$sesi, $token] = $this->mulaiSiap();

        foreach (['sematan_dilepas', 'koneksi_putus', 'sematan_dilepas'] as $jenis) {
            $this->lapor($sesi, $token, $jenis)->assertOk()->assertJsonPath('dicatat', true)->assertJsonPath('dihitung', false)
                ->assertJsonPath('status', 'berlangsung')->assertJsonPath('ke', 0);
            $this->maju(10);
        }

        $this->assertSame(0, $sesi->fresh()->jumlah_pelanggaran);
        $this->assertSame('berlangsung', $sesi->fresh()->status);
        $this->assertSame(3, Pelanggaran::where('dihitung', false)->count());
    }

    public function test_alat_pengembang_dan_halaman_ditinggalkan_dihitung_dan_bisa_memblokir(): void
    {
        $this->siapkanUjian(['batas_pelanggaran' => 2]);
        [$sesi, $token] = $this->mulaiSiap();

        $this->lapor($sesi, $token, 'devtools')->assertOk()->assertJsonPath('dihitung', true)->assertJsonPath('ke', 1)->assertJsonPath('status', 'berlangsung');
        $this->maju(10);
        $this->lapor($sesi, $token, 'halaman_ditinggalkan')->assertOk()->assertJsonPath('dihitung', true)->assertJsonPath('ke', 2)->assertJsonPath('status', 'diblokir');
        $this->assertSame('diblokir', $sesi->fresh()->status);
    }

    public function test_pelanggaran_informasi_tidak_menggeser_jeda_hitungan(): void
    {
        $this->siapkanUjian();
        [$sesi, $token] = $this->mulaiSiap();

        $this->lapor($sesi, $token, 'sematan_dilepas');
        $this->maju(1);
        // Pelanggaran yang dihitung tidak terhalang oleh catatan informasi sebelumnya.
        $this->lapor($sesi, $token, 'pindah_tab')->assertJsonPath('dihitung', true);
    }

    // ── Status sesi ─────────────────────────────────────────────────────────

    public function test_sesi_diblokir_tetap_dicatat_tetapi_tidak_menambah_hitungan(): void
    {
        $this->siapkanUjian(['batas_pelanggaran' => 1]);
        [$sesi, $token] = $this->mulaiSiap();
        $this->lapor($sesi, $token)->assertJsonPath('status', 'diblokir');
        $this->maju(10);

        $this->lapor($sesi, $token, 'keluar_layar_penuh')->assertOk()->assertJsonPath('dicatat', true)
            ->assertJsonPath('dihitung', false)->assertJsonPath('ke', 1)->assertJsonPath('status', 'diblokir');

        $this->assertSame(2, Pelanggaran::count());
    }

    public function test_sinkron_dan_paket_melaporkan_hitungan_dan_status_blokir(): void
    {
        $this->siapkanUjian(['batas_pelanggaran' => 2]);
        [$sesi, $token] = $this->mulaiSiap();
        $this->lapor($sesi, $token);

        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => []], $this->header($token))->assertJsonPath('pelanggaran', 1)->assertJsonPath('status', 'berlangsung');
        $this->getJson("/api/ujian/{$sesi->id}/paket", $this->header($token))->assertJsonPath('keadaan.pelanggaran', 1)->assertJsonPath('jadwal.batas_pelanggaran', 2);

        $this->maju(10);
        $this->lapor($sesi, $token, 'keluar_layar_penuh');
        $this->postJson("/api/ujian/{$sesi->id}/sinkron", ['jawaban' => []], $this->header($token))->assertJsonPath('status', 'diblokir');
    }

    public function test_pelanggaran_setelah_sesi_selesai_diabaikan(): void
    {
        $this->siapkanUjian();
        [$sesi, $token] = $this->mulaiSiap();
        $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => []], $this->header($token))->assertOk();

        $this->lapor($sesi, $token)->assertOk()->assertJsonPath('dicatat', false)->assertJsonPath('status', 'selesai');

        $this->assertSame(0, Pelanggaran::count());
    }

    public function test_pelanggaran_setelah_batas_waktu_menutup_sesi_tanpa_mencatat(): void
    {
        $this->siapkanUjian(['durasi_menit' => 30]);
        [$sesi, $token] = $this->mulaiSiap();
        Carbon::setTestNow($sesi->fresh()->batas_selesai->copy()->addMinutes(5));

        $this->lapor($sesi, $token)->assertOk()->assertJsonPath('status', 'selesai')->assertJsonPath('dicatat', false);

        $this->assertSame('selesai', $sesi->fresh()->status);
        $this->assertSame('waktu_habis', $sesi->fresh()->alasan_selesai);
        $this->assertSame(0, Pelanggaran::count());
    }

    // ── Perlindungan ────────────────────────────────────────────────────────

    public function test_banjir_laporan_dibatasi_20_catatan_per_menit(): void
    {
        $this->siapkanUjian(['batas_pelanggaran' => 10]);
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        foreach (range(1, 30) as $i) {
            $this->lapor($sesi, $token, 'devtools')->assertOk();
        }

        $this->assertSame(20, Pelanggaran::count());
        $this->assertSame('berlangsung', $sesi->fresh()->status);

        // Setelah semenit, pencatatan berjalan lagi.
        $this->maju(61);
        $this->lapor($sesi, $token, 'devtools')->assertJsonPath('dicatat', true);
        $this->assertSame(21, Pelanggaran::count());
    }

    public function test_info_disaring_hanya_kunci_dan_nilai_sederhana(): void
    {
        $this->siapkanUjian();
        [$sesi, $token] = $this->mulaiSiap();

        $this->lapor($sesi, $token, 'split_screen', ['info' => [
            'lebar' => 500, 'tinggi' => 800.5, 'rasio' => 0.5, 'alasan' => str_repeat('a', 200),
            'durasi' => ['bersarang' => 'x'],            // larik: dibuang
            'skrip' => '<script>alert(1)</script>',       // kunci tidak diizinkan: dibuang
            'layar_lebar' => 'bukan angka tetapi teks',   // teks pendek: boleh
        ]])->assertOk();

        $info = Pelanggaran::first()->info;
        $this->assertEqualsCanonicalizing(['lebar', 'tinggi', 'rasio', 'alasan', 'layar_lebar'], array_keys($info));
        $this->assertSame(60, mb_strlen($info['alasan']));
        $this->assertStringNotContainsString('script', json_encode($info));
    }

    public function test_katalog_jenis_memiliki_label_dan_status_hitung(): void
    {
        foreach (Pelanggaran::JENIS as $kode => [$label, $dihitung]) {
            $this->assertNotSame('', $label, "Jenis {$kode} perlu label.");
            $this->assertIsBool($dihitung);
            $this->assertSame($label, Pelanggaran::label($kode));
        }
        $this->assertSame('jenis_asing', Pelanggaran::label('jenis_asing'));
        // Hanya dua sinyal yang bukan perbuatan siswa atau sudah terwakili jenis lain yang tidak dihitung.
        foreach (['sematan_dilepas', 'koneksi_putus'] as $informasi) {
            $this->assertFalse(Pelanggaran::JENIS[$informasi][1]);
        }
        foreach (['devtools', 'halaman_ditinggalkan'] as $dihitung) {
            $this->assertTrue(Pelanggaran::JENIS[$dihitung][1]);
        }
    }

    public function test_siswa_lain_tidak_bisa_melapor_atas_nama_sesi_orang_lain(): void
    {
        $this->siapkanUjian(jumlahSiswa: 2);
        [$sesiA] = $this->mulaiSiap(0);
        [, $tokenB] = $this->mulaiSiap(1);

        $this->lapor($sesiA, $tokenB)->assertStatus(401);

        $this->assertSame(0, Pelanggaran::count());
        $this->assertSame(0, $sesiA->fresh()->jumlah_pelanggaran);
    }

    public function test_waktu_catatan_memakai_jam_aplikasi(): void
    {
        $this->siapkanUjian();
        [$sesi, $token] = $this->mulaiSiap();
        $waktu = $sesi->fresh()->mulai_pada->copy()->addMinutes(20)->setSecond(10)->setMicrosecond(0);
        Carbon::setTestNow($waktu);

        $this->lapor($sesi, $token);

        $this->assertSame($waktu->toDateTimeString(), Pelanggaran::first()->created_at->toDateTimeString());
    }
}
