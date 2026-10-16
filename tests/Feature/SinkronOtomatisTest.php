<?php

namespace Tests\Feature;

use App\Eduweb\SinkronOtomatis;
use App\Models\PengaturanSekolah;
use App\Models\SesiUjian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class SinkronOtomatisTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private const RAHASIA = 'rahasia-webhook-uji-0123456789';

    /** @var list<array<string,mixed>> kejadian EduWeb palsu */
    private array $kejadian = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'eduweb.url' => 'https://eduweb.contoh.sch.id/api/exam/v1', 'eduweb.kunci' => 'exu_kunci-uji-ABCDEF', 'eduweb.timeout' => 5,
            'eduweb.otomatis' => true, 'eduweb.webhook_rahasia' => self::RAHASIA, 'eduweb.cek_menit' => 5,
        ]);
        PengaturanSekolah::ambil()->update(['eduweb_sinkron_penuh_pada' => now(), 'eduweb_kejadian_terakhir' => 10]);
        $this->pasangEdu();
    }

    private function pasangEdu(): void
    {
        Http::swap(new Factory);
        Http::fake(function (Request $r) {
            $jalur = basename(parse_url($r->url(), PHP_URL_PATH));
            parse_str(parse_url($r->url(), PHP_URL_QUERY) ?? '', $q);
            if ($jalur === 'events') {
                $baris = array_values(array_filter($this->kejadian, fn ($e) => $e['id'] > (int) ($q['after'] ?? 0)));

                return Http::response(['data' => $baris, 'last_id' => $baris ? end($baris)['id'] : (int) ($q['after'] ?? 0), 'has_more' => false]);
            }
            if (in_array($jalur, ['teachers', 'students', 'credentials'], true)) {
                return Http::response(['data' => [], 'current_page' => 1, 'last_page' => 1]);
            }

            return Http::response(['data' => []]);
        });
    }

    private function permintaanKe(string $jalur): int
    {
        return Http::recorded(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/'.$jalur))->count();
    }

    /** @return array<string,string> */
    private function header(string $isi, ?string $rahasia = null, ?int $waktu = null): array
    {
        $waktu ??= time();

        return [
            'X-Eduweb-Timestamp' => (string) $waktu,
            'X-Eduweb-Signature' => hash_hmac('sha256', $waktu.'.'.$isi, $rahasia ?? self::RAHASIA),
            'Content-Type' => 'application/json',
        ];
    }

    private function kirim(string $isi = '{"event_id":11}', array $header = null)
    {
        return $this->call('POST', '/api/eduweb/webhook', [], [], [], $this->ke($header ?? $this->header($isi)), $isi);
    }

    private function ke(array $header): array
    {
        $server = [];
        foreach ($header as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $server['CONTENT_TYPE'] = 'application/json';

        return $server;
    }

    // ── Webhook ─────────────────────────────────────────────────────────────

    public function test_webhook_sah_menjalankan_sinkron_kejadian(): void
    {
        $this->kejadian = [['id' => 11, 'type' => 'unknown.type', 'student_id' => null, 'payload' => []]];
        $this->pasangEdu();

        $this->kirim()->assertStatus(202)->assertJson(['diterima' => true]);

        $this->assertGreaterThanOrEqual(1, $this->permintaanKe('events'));
        $this->assertSame(11, (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir);
        $this->assertNotNull(PengaturanSekolah::ambil()->eduweb_webhook_terakhir);
        $this->assertNotNull(PengaturanSekolah::ambil()->eduweb_cek_terakhir);
    }

    public function test_webhook_kejadian_data_master_menyegarkan_data(): void
    {
        $this->kejadian = [['id' => 11, 'type' => 'master.changed', 'student_id' => 3, 'payload' => ['entity' => 'student', 'id' => 3, 'action' => 'created']]];
        $this->pasangEdu();

        $this->kirim()->assertStatus(202);

        $this->assertSame(1, $this->permintaanKe('subjects'), 'satu penyegaran data master');
        $this->assertSame(11, (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir);
    }

    public function test_tanda_tangan_salah_ditolak_dan_tidak_menjalankan_apa_pun(): void
    {
        $isi = '{"event_id":11}';
        $this->kirim($isi, $this->header($isi, 'rahasia-lain'))->assertStatus(401);
        $this->kirim($isi, ['X-Eduweb-Timestamp' => (string) time(), 'X-Eduweb-Signature' => str_repeat('0', 64)])->assertStatus(401);
        $this->kirim($isi, [])->assertStatus(401);
        $this->kirim('{"event_id":99}', $this->header($isi))->assertStatus(401); // isi diubah setelah ditandatangani

        $this->assertSame(0, $this->permintaanKe('events'));
        $this->assertNull(PengaturanSekolah::ambil()->eduweb_webhook_terakhir);
    }

    public function test_stempel_waktu_kedaluwarsa_atau_dari_masa_depan_ditolak(): void
    {
        $isi = '{"event_id":11}';
        $this->kirim($isi, $this->header($isi, null, time() - 400))->assertStatus(401);
        $this->kirim($isi, $this->header($isi, null, time() + 400))->assertStatus(401);
        $this->assertSame(0, $this->permintaanKe('events'));
    }

    public function test_tanda_tangan_yang_sama_tidak_diterima_dua_kali(): void
    {
        $isi = '{"event_id":11}';
        $header = $this->header($isi);

        $this->kirim($isi, $header)->assertStatus(202);
        $this->kirim($isi, $header)->assertStatus(401);
    }

    public function test_isi_terlalu_besar_ditolak(): void
    {
        $isi = json_encode(['x' => str_repeat('a', 5000)]);
        $this->kirim($isi)->assertStatus(413);
    }

    public function test_webhook_mati_bila_rahasia_kosong_atau_otomatis_dimatikan(): void
    {
        config(['eduweb.webhook_rahasia' => '']);
        $this->kirim()->assertStatus(503);

        config(['eduweb.webhook_rahasia' => self::RAHASIA, 'eduweb.otomatis' => false]);
        $this->kirim()->assertStatus(503);
        $this->assertSame(0, $this->permintaanKe('events'));
    }

    public function test_webhook_tidak_menerima_metode_selain_post(): void
    {
        $this->get('/api/eduweb/webhook')->assertStatus(405);
    }

    public function test_webhook_tidak_butuh_csrf_atau_login(): void
    {
        $this->kirim()->assertStatus(202); // POST tanpa token CSRF dan tanpa sesi
    }

    // ── Jeda saat ujian ─────────────────────────────────────────────────────

    public function test_ditahan_saat_sesi_ujian_berlangsung_lalu_diterapkan_setelah_selesai(): void
    {
        $this->siapkanUjian();
        $this->mulaiSesi($this->siswa[0]);
        $this->kejadian = [['id' => 11, 'type' => 'unknown.type', 'student_id' => null, 'payload' => []]];
        $this->pasangEdu();

        $this->kirim()->assertStatus(202);

        $this->assertSame(0, $this->permintaanKe('events'), 'tidak boleh menyentuh data saat ujian berlangsung');
        $this->assertTrue(PengaturanSekolah::ambil()->eduweb_kejadian_tertahan);
        $this->assertSame(10, (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir);

        SesiUjian::query()->update(['status' => SesiUjian::SELESAI]);
        $this->jadwal->update(['status' => 'selesai']);
        app(SinkronOtomatis::class)->cobaBilaPerlu();

        $this->assertGreaterThanOrEqual(1, $this->permintaanKe('events'));
        $this->assertFalse(PengaturanSekolah::ambil()->eduweb_kejadian_tertahan);
        $this->assertSame(11, (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir);
    }

    public function test_jadwal_aktif_dalam_jendela_pendek_menahan_sinkron_tetapi_tugas_berhari_hari_tidak(): void
    {
        $this->siapkanUjian(); // jendela: sejam lalu sampai 3 jam lagi, aktif
        $this->assertTrue(app(SinkronOtomatis::class)->ujianBerlangsung());

        $this->jadwal->update(['mulai_pada' => now()->subDays(1), 'tutup_pada' => now()->addDays(5)]);
        $this->assertFalse(app(SinkronOtomatis::class)->ujianBerlangsung());

        $this->jadwal->update(['mulai_pada' => now()->addMinutes(5), 'tutup_pada' => now()->addHours(3)]);
        $this->assertTrue(app(SinkronOtomatis::class)->ujianBerlangsung(), 'sebentar lagi mulai: peserta sudah mulai masuk');

        $this->jadwal->update(['mulai_pada' => now()->addHours(2), 'tutup_pada' => now()->addHours(4)]);
        $this->assertFalse(app(SinkronOtomatis::class)->ujianBerlangsung());
    }

    // ── Pengecekan cadangan tanpa cron ──────────────────────────────────────

    public function test_pengecekan_cadangan_dipicu_saat_halaman_dibuka_paling_sering_sekali_per_interval(): void
    {
        $this->get('/')->assertOk();
        $this->assertSame(1, $this->permintaanKe('events'));

        $this->get('/')->assertOk();
        $this->get('/')->assertOk();
        $this->assertSame(1, $this->permintaanKe('events'), 'dalam 5 menit hanya sekali');

        Cache::forget('eduweb:cek');
        $this->get('/')->assertOk();
        $this->assertSame(2, $this->permintaanKe('events'));
    }

    public function test_pengecekan_cadangan_tidak_jalan_untuk_post_json_atau_saat_dimatikan(): void
    {
        $this->postJson('/masuk', ['username' => 'x', 'password' => 'y']);
        $this->getJson('/staf/masuk');
        $this->assertSame(0, $this->permintaanKe('events'));

        config(['eduweb.otomatis' => false]);
        $this->get('/')->assertOk();
        $this->assertSame(0, $this->permintaanKe('events'));
    }

    public function test_pengecekan_cadangan_menunggu_sinkron_penuh_pertama(): void
    {
        PengaturanSekolah::ambil()->update(['eduweb_sinkron_penuh_pada' => null]);
        $this->get('/')->assertOk();
        $this->assertSame(0, $this->permintaanKe('events'));
    }

    public function test_pengecekan_tanpa_kejadian_tidak_menumpuk_riwayat(): void
    {
        $this->get('/')->assertOk();
        $this->assertSame(0, DB::table('sinkron_log')->count());

        $this->kejadian = [['id' => 11, 'type' => 'master.changed', 'student_id' => 3, 'payload' => ['entity' => 'student', 'id' => 3, 'action' => 'updated']]];
        $this->pasangEdu();
        Cache::forget('eduweb:cek');
        $this->get('/')->assertOk();
        $this->assertSame(1, DB::table('sinkron_log')->count());
    }

    public function test_galat_eduweb_tidak_mengganggu_halaman_dan_tidak_menghujani(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('galat', 500)]);

        $this->get('/')->assertOk();
        $this->get('/')->assertOk();

        $this->assertSame(1, Http::recorded()->count(), 'galat tidak diulang dalam interval yang sama');
        $this->assertSame('gagal', DB::table('sinkron_log')->value('status'));
    }

    public function test_sinyal_saat_sinkron_berjalan_tidak_hilang(): void
    {
        $kunci = Cache::lock('eduweb:sinkron', 60);
        $this->assertTrue($kunci->get()); // seolah proses lain sedang sinkron

        $this->kirim()->assertStatus(202);
        $this->assertSame(0, $this->permintaanKe('events'));
        $this->assertTrue((bool) Cache::get('eduweb:perlu'), 'penanda dipertahankan untuk dibaca proses yang sedang jalan');

        $kunci->release();
        app(SinkronOtomatis::class)->dariWebhook();
        $this->assertGreaterThanOrEqual(1, $this->permintaanKe('events'));
        $this->assertFalse((bool) Cache::get('eduweb:perlu'));
    }

    public function test_halaman_menampilkan_status_otomatis_tanpa_membuka_rahasia(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'web')->get(route('staf.sinkron.index'))
            ->assertOk()->assertSee('Sinkron otomatis')->assertSee('Siap, belum pernah menerima sinyal')
            ->assertSee(route('eduweb.webhook'))->assertDontSee(self::RAHASIA)->assertDontSee('exu_kunci-uji');

        config(['eduweb.webhook_rahasia' => '']);
        $this->get(route('staf.sinkron.index'))->assertSee('EDUWEB_WEBHOOK_RAHASIA');

        PengaturanSekolah::ambil()->update(['eduweb_kredensial' => 'belum_aktif']);
        $this->get(route('staf.sinkron.index'))->assertSee('Belum aktif');
    }

    public function test_halaman_menampilkan_ditahan_saat_ujian_berlangsung(): void
    {
        $this->siapkanUjian();
        $this->actingAs($this->admin, 'web')->get(route('staf.sinkron.index'))->assertOk()->assertSee('Ditahan');
    }

    public function test_admin_tidak_terpengaruh_oleh_pengecekan_otomatis(): void
    {
        $admin = User::factory()->admin()->create();
        $sandi = $admin->password;

        $this->actingAs($admin, 'web')->get('/staf')->assertOk();

        $this->assertSame($sandi, $admin->fresh()->password);
    }
}
