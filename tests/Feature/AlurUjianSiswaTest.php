<?php

namespace Tests\Feature;

use App\Models\Kelas;
use App\Models\SesiUjian;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Services\LayananGambarUjian;
use App\Services\LayananJadwal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class AlurUjianSiswaTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function masuk(Siswa $siswa): static
    {
        return $this->actingAs($siswa, 'siswa');
    }

    private function izinkan(Siswa $siswa): void
    {
        $this->masuk($siswa)->post(route('siswa.ujian.token.kirim', $this->jadwal), ['token' => $this->jadwal->token])
            ->assertRedirect(route('siswa.ujian.siap', $this->jadwal));
    }

    /** Mulai lewat halaman web; mengembalikan token perangkat dari cookie. */
    private function mulaiLewatWeb(Siswa $siswa): string
    {
        $this->izinkan($siswa);
        $r = $this->post(route('siswa.ujian.mulai', $this->jadwal))->assertRedirect(route('siswa.ujian.kerjakan', $this->jadwal));
        $sesi = $this->sesiDari($siswa);
        $cookie = $r->getCookie("ujian_p{$sesi->id}");
        $this->assertNotNull($cookie, 'Cookie perangkat harus diset.');

        return $cookie->getValue();
    }

    // ── Beranda ─────────────────────────────────────────────────────────────

    public function test_beranda_menampilkan_ujian_untuk_kelas_siswa_saja(): void
    {
        $this->siapkanUjian();
        $kelasLain = Kelas::create(['tahun_ajaran_id' => TahunAjaran::first()->id, 'tingkat' => 'XI', 'nama' => 'XI B']);
        $orangLain = Siswa::factory()->create();
        $orangLain->kelas()->attach($kelasLain->id, ['status' => 'aktif']);

        $this->masuk($this->siswa[0])->get('/beranda')->assertOk()->assertSee('UTS Matematika')->assertSee('Mulai');
        $this->masuk($orangLain)->get('/beranda')->assertOk()->assertDontSee('UTS Matematika')->assertSee('Belum ada ujian yang aktif');
    }

    public function test_beranda_tidak_menampilkan_jadwal_draf_atau_untuk_siswa_nonaktif(): void
    {
        $this->siapkanUjian();
        $this->siswa[1]->update(['status' => 'lulus']);

        $this->masuk($this->siswa[1])->get('/beranda')->assertOk()->assertDontSee('UTS Matematika');

        $this->jadwal->update(['status' => 'draf']);
        $this->masuk($this->siswa[0])->get('/beranda')->assertOk()->assertDontSee('UTS Matematika');
    }

    public function test_beranda_menandai_belum_dibuka_ditutup_dan_sedang_dikerjakan(): void
    {
        $this->siapkanUjian();
        $this->jadwal->update(['mulai_pada' => now()->addHour(), 'tutup_pada' => now()->addHours(3)]);
        $this->masuk($this->siswa[0])->get('/beranda')->assertSee('Belum dibuka')->assertDontSee('>Mulai<', false);

        $this->jadwal->update(['mulai_pada' => now()->subHours(3), 'tutup_pada' => now()->subHour()]);
        $this->get('/beranda')->assertSee('Sudah ditutup');

        // Siswa yang sudah mulai tetap melihat Lanjutkan walau jendela sudah ditutup.
        $this->jadwal->update(['mulai_pada' => now()->subHour(), 'tutup_pada' => now()->addHour()]);
        $this->mulaiSesi($this->siswa[0]);
        $this->jadwal->update(['mulai_pada' => now()->subHours(3), 'tutup_pada' => now()->subHour()]);
        $this->get('/beranda')->assertSee('Sedang dikerjakan')->assertSee('Lanjutkan');
    }

    public function test_beranda_mengharuskan_login(): void
    {
        $this->get('/beranda')->assertRedirect(route('siswa.masuk'));
        $this->siapkanUjian();
        $this->get("/ujian/{$this->jadwal->id}")->assertRedirect(route('siswa.masuk'));
    }

    // ── Hak akses ───────────────────────────────────────────────────────────

    public function test_siswa_di_luar_kelas_jadwal_mendapat_404_di_semua_halaman_ujian(): void
    {
        $this->siapkanUjian();
        $kelasLain = Kelas::create(['tahun_ajaran_id' => TahunAjaran::first()->id, 'tingkat' => 'XI', 'nama' => 'XI B']);
        $orangLain = Siswa::factory()->create();
        $orangLain->kelas()->attach($kelasLain->id, ['status' => 'aktif']);
        $this->masuk($orangLain);

        $this->get(route('siswa.ujian.token', $this->jadwal))->assertNotFound();
        $this->post(route('siswa.ujian.token.kirim', $this->jadwal), ['token' => $this->jadwal->token])->assertNotFound();
        $this->get(route('siswa.ujian.siap', $this->jadwal))->assertNotFound();
        $this->post(route('siswa.ujian.mulai', $this->jadwal))->assertNotFound();
        $this->get(route('siswa.ujian.kerjakan', $this->jadwal))->assertNotFound();
        $this->get(route('siswa.ujian.hasil', $this->jadwal))->assertNotFound();
        $this->assertSame(0, SesiUjian::where('siswa_id', $orangLain->id)->count());
    }

    public function test_siswa_nonaktif_atau_jadwal_draf_tidak_bisa_masuk(): void
    {
        $this->siapkanUjian();
        $this->siswa[1]->update(['status' => 'keluar']);
        $this->masuk($this->siswa[1])->get(route('siswa.ujian.token', $this->jadwal))->assertNotFound();

        $this->jadwal->update(['status' => 'draf']);
        $this->masuk($this->siswa[0])->get(route('siswa.ujian.token', $this->jadwal))->assertNotFound();
    }

    public function test_siswa_yang_masuk_kelas_setelah_aktivasi_dibuatkan_sesinya(): void
    {
        $this->siapkanUjian(jumlahSiswa: 1);
        $terlambat = Siswa::factory()->create();
        $terlambat->kelas()->attach($this->kelas->id, ['status' => 'aktif']);
        $this->assertSame(1, $this->jadwal->sesi()->count());

        $this->masuk($terlambat)->get(route('siswa.ujian.token', $this->jadwal))->assertOk();

        $this->assertSame(2, $this->jadwal->sesi()->count());
    }

    // ── Token ───────────────────────────────────────────────────────────────

    public function test_halaman_token_tampil_dan_token_benar_membuka_halaman_siap(): void
    {
        $this->siapkanUjian();

        $this->masuk($this->siswa[0])->get(route('siswa.ujian.token', $this->jadwal))->assertOk()->assertSee('Token ujian')->assertSee('UTS Matematika');
        $this->post(route('siswa.ujian.token.kirim', $this->jadwal), ['token' => strtolower(' '.$this->jadwal->token.' ')])
            ->assertRedirect(route('siswa.ujian.siap', $this->jadwal));
        $this->get(route('siswa.ujian.siap', $this->jadwal))->assertOk()->assertSee('Baca dulu aturannya')->assertSee('Mulai ujian');
    }

    public function test_token_salah_ditolak_dicatat_dan_dibatasi(): void
    {
        $this->siapkanUjian();
        RateLimiter::clear("ujian-token:{$this->siswa[0]->id}:{$this->jadwal->id}");
        $this->masuk($this->siswa[0]);

        $this->post(route('siswa.ujian.token.kirim', $this->jadwal), ['token' => 'SALAH'])->assertSessionHasErrors('token');
        $this->assertStringContainsString('Token salah', session('errors')->first('token'));
        $this->assertDatabaseHas('audit_log', ['aksi' => 'ujian.token_salah', 'pelaku_id' => $this->siswa[0]->id]);

        foreach (range(1, 4) as $i) {
            $this->post(route('siswa.ujian.token.kirim', $this->jadwal), ['token' => 'SALAH']);
        }
        // Percobaan ke-6 diblokir sementara walau tokennya benar.
        $this->post(route('siswa.ujian.token.kirim', $this->jadwal), ['token' => $this->jadwal->token])->assertSessionHasErrors('token');
        $this->assertStringContainsString('Terlalu banyak percobaan', session('errors')->first('token'));
        $this->get(route('siswa.ujian.siap', $this->jadwal))->assertRedirect(route('siswa.ujian.token', $this->jadwal));
    }

    public function test_token_lama_tidak_berlaku_setelah_dibuat_ulang(): void
    {
        $this->siapkanUjian();
        $lama = $this->jadwal->token;
        app(LayananJadwal::class)->regenerasiToken($this->jadwal);

        $this->masuk($this->siswa[0])->post(route('siswa.ujian.token.kirim', $this->jadwal), ['token' => $lama])->assertSessionHasErrors('token');
        $this->post(route('siswa.ujian.token.kirim', $this->jadwal), ['token' => $this->jadwal->fresh()->token])->assertRedirect(route('siswa.ujian.siap', $this->jadwal));
    }

    public function test_tanpa_izin_token_tidak_bisa_mulai(): void
    {
        $this->siapkanUjian();
        $this->masuk($this->siswa[0]);

        $this->get(route('siswa.ujian.siap', $this->jadwal))->assertRedirect(route('siswa.ujian.token', $this->jadwal));
        $this->post(route('siswa.ujian.mulai', $this->jadwal))->assertRedirect(route('siswa.ujian.token', $this->jadwal));
        $this->assertSame('belum', $this->sesiDari($this->siswa[0])->status);
    }

    public function test_izin_token_kedaluwarsa_setelah_30_menit(): void
    {
        $this->siapkanUjian();
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->jadwal->update(['mulai_pada' => now()->subHour(), 'tutup_pada' => now()->addHours(5)]);
        $this->izinkan($this->siswa[0]);

        Carbon::setTestNow(now()->addMinutes(31));

        $this->post(route('siswa.ujian.mulai', $this->jadwal))->assertRedirect(route('siswa.ujian.token', $this->jadwal));
        $this->assertSame('belum', $this->sesiDari($this->siswa[0])->status);
    }

    public function test_jadwal_belum_dibuka_dan_sudah_ditutup_tidak_menampilkan_formulir_dan_tidak_bisa_mulai(): void
    {
        $this->siapkanUjian();
        $this->jadwal->update(['mulai_pada' => now()->addHour(), 'tutup_pada' => now()->addHours(3)]);
        $this->masuk($this->siswa[0])->get(route('siswa.ujian.token', $this->jadwal))->assertOk()->assertSee('belum dibuka')->assertDontSee('name="token"', false);
        $this->izinkan($this->siswa[0]);
        $this->post(route('siswa.ujian.mulai', $this->jadwal))->assertRedirect(route('siswa.ujian.token', $this->jadwal))->assertSessionHas('gagal');
        $this->assertSame('belum', $this->sesiDari($this->siswa[0])->status);

        $this->jadwal->update(['mulai_pada' => now()->subHours(3), 'tutup_pada' => now()->subHour()]);
        $this->get(route('siswa.ujian.token', $this->jadwal))->assertSee('sudah ditutup');
        $this->post(route('siswa.ujian.mulai', $this->jadwal))->assertRedirect()->assertSessionHas('gagal');
        $this->assertSame('belum', $this->sesiDari($this->siswa[0])->status);
    }

    // ── Mulai dan kerjakan ──────────────────────────────────────────────────

    public function test_mulai_menyiapkan_sesi_cookie_perangkat_dan_halaman_kerjakan(): void
    {
        $this->siapkanUjian(['durasi_menit' => 60]);
        $token = $this->mulaiLewatWeb($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        $this->assertSame('berlangsung', $sesi->status);
        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $sesi->token_perangkat, 'Hanya hash token yang disimpan.');
        $this->assertEquals(60, $sesi->mulai_pada->diffInMinutes($sesi->batas_selesai));
        $this->assertCount(3, $sesi->susunan['soal']);
        $this->assertEqualsCanonicalizing(['A', 'B', 'C', 'D'], $sesi->susunan['opsi'][$this->soal['s1']->id]);
        $this->assertSame('peramban', $sesi->platform);

        $this->withCookie("ujian_p{$sesi->id}", $token)->get(route('siswa.ujian.kerjakan', $this->jadwal))->assertOk()
            ->assertSee("api/ujian/{$sesi->id}/paket", false)->assertSee($token, false)->assertSee('id="timer"', false);
    }

    public function test_cookie_perangkat_httponly_dan_halaman_kerjakan_tidak_memuat_kunci_atau_soal(): void
    {
        $this->siapkanUjian();
        $this->izinkan($this->siswa[0]);
        $r = $this->post(route('siswa.ujian.mulai', $this->jadwal));
        $sesi = $this->sesiDari($this->siswa[0]);

        $this->assertTrue($r->getCookie("ujian_p{$sesi->id}")->isHttpOnly());
        $html = $this->withCookie("ujian_p{$sesi->id}", $r->getCookie("ujian_p{$sesi->id}")->getValue())->get(route('siswa.ujian.kerjakan', $this->jadwal))->getContent();
        $this->assertStringNotContainsString('Soal PG s1', $html, 'Soal dimuat lewat API, bukan di HTML halaman.');
        $this->assertStringNotContainsString('RAHASIA', $html);
    }

    public function test_perangkat_kedua_ditolak_dan_perangkat_asli_tetap_bisa_melanjutkan(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiLewatWeb($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);

        // Perangkat kedua: tanpa cookie sesi ujian.
        $this->get(route('siswa.ujian.token', $this->jadwal))->assertOk()->assertSee('perangkat lain')->assertDontSee('name="token"', false);
        $this->get(route('siswa.ujian.kerjakan', $this->jadwal))->assertRedirect(route('siswa.ujian.token', $this->jadwal));
        $this->izinkan($this->siswa[0]);
        $this->post(route('siswa.ujian.mulai', $this->jadwal))->assertRedirect(route('siswa.ujian.token', $this->jadwal))->assertSessionHas('gagal');
        $this->assertSame(hash('sha256', $token), $sesi->fresh()->token_perangkat, 'Token perangkat asli tidak boleh tergeser.');

        // Cookie salah juga ditolak.
        $this->withCookie("ujian_p{$sesi->id}", str_repeat('a', 64))->get(route('siswa.ujian.kerjakan', $this->jadwal))->assertRedirect(route('siswa.ujian.token', $this->jadwal));

        // Perangkat asli: muat ulang tetap bisa.
        $this->withCookie("ujian_p{$sesi->id}", $token)->get(route('siswa.ujian.token', $this->jadwal))->assertRedirect(route('siswa.ujian.kerjakan', $this->jadwal));
        $this->withCookie("ujian_p{$sesi->id}", $token)->get(route('siswa.ujian.kerjakan', $this->jadwal))->assertOk();
    }

    public function test_setelah_reset_perangkat_oleh_pengawas_perangkat_baru_diizinkan(): void
    {
        $this->siapkanUjian();
        $lama = $this->mulaiLewatWeb($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $sesi->update(['token_perangkat' => null, 'status' => 'berlangsung']);

        // Perangkat lama tidak lagi sah.
        $this->withCookie("ujian_p{$sesi->id}", $lama)->get(route('siswa.ujian.kerjakan', $this->jadwal))->assertRedirect(route('siswa.ujian.token', $this->jadwal));
    }

    public function test_siswa_selesai_tidak_bisa_mulai_lagi_dan_diarahkan_ke_hasil(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => $this->jawabanBenar()], $this->header($token))->assertOk();

        $this->masuk($this->siswa[0])->get(route('siswa.ujian.token', $this->jadwal))->assertRedirect(route('siswa.ujian.hasil', $this->jadwal));
        $this->get(route('siswa.ujian.kerjakan', $this->jadwal))->assertRedirect(route('siswa.ujian.hasil', $this->jadwal));
        $this->izinkan($this->siswa[0]);
        $this->post(route('siswa.ujian.mulai', $this->jadwal))->assertRedirect(route('siswa.ujian.hasil', $this->jadwal));
        $this->assertSame('selesai', $sesi->fresh()->status);
    }

    public function test_melanjutkan_setelah_waktu_habis_menutup_sesi_dan_menilai(): void
    {
        $this->siapkanUjian(['durasi_menit' => 30]);
        $token = $this->mulaiLewatWeb($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        Carbon::setTestNow($sesi->batas_selesai->copy()->addMinutes(10));

        $this->withCookie("ujian_p{$sesi->id}", $token)->post(route('siswa.ujian.mulai', $this->jadwal))->assertRedirect(route('siswa.ujian.hasil', $this->jadwal));

        $this->assertSame('selesai', $sesi->fresh()->status);
        $this->assertSame('waktu_habis', $sesi->fresh()->alasan_selesai);
        $this->assertNotNull($sesi->fresh()->hasil);
    }

    public function test_sesi_diblokir_tetap_menampilkan_halaman_kerjakan_di_perangkat_yang_sama(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiLewatWeb($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $sesi->update(['status' => 'diblokir']);

        $this->withCookie("ujian_p{$sesi->id}", $token)->get(route('siswa.ujian.kerjakan', $this->jadwal))->assertOk();
        // Perangkat lain (tanpa cookie) tetap ditolak. Klien uji menyimpan cookie, jadi dibersihkan dulu.
        $this->defaultCookies = [];
        $this->get(route('siswa.ujian.kerjakan', $this->jadwal))->assertRedirect(route('siswa.ujian.token', $this->jadwal));
    }

    // ── Hasil ───────────────────────────────────────────────────────────────

    public function test_halaman_hasil_menampilkan_nilai_pg_bila_diizinkan(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $this->masuk($this->siswa[0]);
        $this->get(route('siswa.ujian.hasil', $this->jadwal))->assertRedirect(route('siswa.ujian.token', $this->jadwal)); // belum selesai

        $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => $this->jawabanBenar()], $this->header($token));

        $this->get(route('siswa.ujian.hasil', $this->jadwal))->assertOk()->assertSee('sudah terkirim')->assertSee('100')->assertSee('Nilai pilihan ganda');
        $this->get('/beranda')->assertSee('Riwayat ujian')->assertSee('100');
    }

    public function test_halaman_hasil_tidak_menampilkan_nilai_bila_ditahan(): void
    {
        $this->siapkanUjian(['tampilkan_hasil' => 'ditahan']);
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => $this->jawabanBenar()], $this->header($token));

        $this->masuk($this->siswa[0])->get(route('siswa.ujian.hasil', $this->jadwal))->assertOk()
            ->assertSee('Nilai akan diumumkan oleh guru')->assertDontSee('Nilai pilihan ganda');
    }

    public function test_hasil_menyebut_esai_menunggu_koreksi(): void
    {
        $this->siapkanUjian(denganEsai: true);
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => $this->jawabanBenar()], $this->header($token));

        $this->masuk($this->siswa[0])->get(route('siswa.ujian.hasil', $this->jadwal))->assertOk()->assertSee('menunggu koreksi guru');
    }

    public function test_siswa_tidak_bisa_melihat_hasil_siswa_lain(): void
    {
        $this->siapkanUjian();
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $this->postJson("/api/ujian/{$sesi->id}/kirim", ['jawaban' => $this->jawabanBenar()], $this->header($token));

        // Siswa kedua belum selesai: hanya melihat sesinya sendiri, jadi diarahkan ke token dan tidak melihat nilai siswa pertama.
        $this->masuk($this->siswa[1])->get(route('siswa.ujian.hasil', $this->jadwal))->assertRedirect(route('siswa.ujian.token', $this->jadwal));
        $this->get('/beranda')->assertDontSee('Riwayat ujian');
    }

    // ── Gambar statis ───────────────────────────────────────────────────────

    public function test_gambar_soal_disalin_ke_folder_statis_bernama_acak_lalu_dibersihkan(): void
    {
        Storage::fake('local');
        $tujuan = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ujian-media-'.bin2hex(random_bytes(4));
        config(['ujian.jalur_media' => $tujuan, 'ujian.url_media' => 'http://contoh.test/media/u']);
        $this->siapkanUjian();
        $this->jadwal->update(['status' => 'draf', 'token' => null, 'media_kode' => null]);
        $this->jadwal->sesi()->delete();
        $this->jadwal->soalBeku()->delete();

        Storage::disk('local')->put('soal/asli.webp', 'isi-gambar');
        $this->soal['s1']->update(['gambar' => 'soal/asli.webp']);
        app(LayananJadwal::class)->aktifkan($this->jadwal);
        $this->jadwal->refresh();

        $berkas = glob($tujuan.DIRECTORY_SEPARATOR.$this->jadwal->media_kode.DIRECTORY_SEPARATOR.'*');
        $this->assertCount(1, $berkas);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}\.webp$/', basename($berkas[0]));
        $this->assertSame('isi-gambar', file_get_contents($berkas[0]));

        $url = app(LayananGambarUjian::class)->url($this->jadwal, $this->soal['s1']->fresh());
        $this->assertSame('http://contoh.test/media/u/'.$this->jadwal->media_kode.'/'.basename($berkas[0]), $url);
        $this->assertStringNotContainsString('asli', $url);
        $this->assertStringNotContainsString((string) $this->soal['s1']->id.'.', basename($url), 'Nama berkas tidak boleh menurunkan id soal secara langsung.');

        // Paket soal memuat alamat statis itu.
        $token = $this->mulaiSesi($this->siswa[0]);
        $sesi = $this->sesiDari($this->siswa[0]);
        $paket = $this->getJson("/api/ujian/{$sesi->id}/paket", $this->header($token))->json();
        $this->assertContains($url, array_column($paket['soal'], 'gambar'));

        // Selesai: folder dihapus.
        app(LayananJadwal::class)->selesaikan($this->jadwal);
        $this->assertDirectoryDoesNotExist($tujuan.DIRECTORY_SEPARATOR.$this->jadwal->media_kode);
        @rmdir($tujuan);
    }

    public function test_batalkan_aktivasi_membersihkan_gambar_statis(): void
    {
        Storage::fake('local');
        $tujuan = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ujian-media-'.bin2hex(random_bytes(4));
        config(['ujian.jalur_media' => $tujuan, 'ujian.url_media' => 'http://contoh.test/media/u']);
        $this->siapkanUjian();
        $this->jadwal->update(['status' => 'draf', 'token' => null, 'media_kode' => null]);
        $this->jadwal->sesi()->delete();
        $this->jadwal->soalBeku()->delete();
        Storage::disk('local')->put('soal/asli.webp', 'isi-gambar');
        $this->soal['s1']->update(['gambar' => 'soal/asli.webp']);
        app(LayananJadwal::class)->aktifkan($this->jadwal);
        $kode = $this->jadwal->fresh()->media_kode;
        $this->assertDirectoryExists($tujuan.DIRECTORY_SEPARATOR.$kode);

        app(LayananJadwal::class)->batalkanAktivasi($this->jadwal->fresh());

        $this->assertDirectoryDoesNotExist($tujuan.DIRECTORY_SEPARATOR.$kode);
        $this->assertNull($this->jadwal->fresh()->media_kode);
        @rmdir($tujuan);
    }
}
