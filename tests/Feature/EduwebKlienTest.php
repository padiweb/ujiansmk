<?php

namespace Tests\Feature;

use App\Eduweb\KesalahanEduweb;
use App\Eduweb\KlienEduweb;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EduwebKlienTest extends TestCase
{
    private const KUNCI = 'exu_kunci-rahasia-ABCD1234';

    private const URL = 'https://eduweb.contoh.sch.id/api/exam/v1';

    private function klien(?string $url = self::URL, ?string $kunci = self::KUNCI): KlienEduweb
    {
        return new KlienEduweb($url, $kunci, 5);
    }

    private function galat(callable $aksi): KesalahanEduweb
    {
        try {
            $aksi();
        } catch (KesalahanEduweb $e) {
            return $e;
        }
        $this->fail('Seharusnya melempar KesalahanEduweb.');
    }

    private function ping(array $timpa = []): array
    {
        return array_replace_recursive([
            'ok' => true, 'school' => ['id' => 1, 'name' => 'SMK Uji', 'npsn' => '12345678'], 'client' => 'Ujian Baru',
            'server_time' => now()->toIso8601String(), 'api_version' => 1,
        ], $timpa);
    }

    public function test_mengirim_kunci_lewat_header_bukan_alamat(): void
    {
        Http::fake(['*' => Http::response($this->ping())]);

        $hasil = $this->klien()->get('ping', ['per_page' => 1]);

        $this->assertTrue($hasil['ok']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::KUNCI)
            && $r->url() === self::URL.'/ping?per_page=1' && ! str_contains($r->url(), 'exu_'));
    }

    public function test_belum_diatur_dan_format_kunci_salah(): void
    {
        Http::fake();

        $this->assertSame('belum_diatur', $this->galat(fn () => $this->klien(null, null)->get('ping'))->kode);
        $this->assertSame('belum_diatur', $this->galat(fn () => $this->klien(self::URL, '')->get('ping'))->kode);
        $this->assertSame('kunci_salah_format', $this->galat(fn () => $this->klien(self::URL, 'rahasia-tanpa-awalan')->get('ping'))->kode);
        Http::assertNothingSent();
    }

    public function test_http_polos_ditolak_kecuali_host_lokal(): void
    {
        Http::fake(['*' => Http::response($this->ping())]);

        $this->assertSame('url_tidak_aman', $this->galat(fn () => $this->klien('http://eduweb.contoh.sch.id/api/exam/v1')->get('ping'))->kode);
        $this->assertSame('url_salah', $this->galat(fn () => $this->klien('bukan-url')->get('ping'))->kode);
        $this->assertSame('url_salah', $this->galat(fn () => $this->klien('ftp://eduweb.contoh.sch.id')->get('ping'))->kode);

        foreach (['http://localhost/eduweb/api/exam/v1', 'http://127.0.0.1:8000/api/exam/v1', 'http://eduweb.test/api/exam/v1'] as $lokal) {
            $this->assertTrue($this->klien($lokal)->get('ping')['ok']);
        }
    }

    public function test_pesan_galat_per_status_tidak_membocorkan_kunci_atau_isi_respons(): void
    {
        $kasus = [
            [401, 'ditolak'], [429, 'dibatasi'], [404, 'tidak_ditemukan'], [503, 'server'], [422, 'ditolak_lain'], [302, 'dialihkan'],
        ];
        foreach ($kasus as [$status, $kode]) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(['*' => Http::response('isi rahasia '.self::KUNCI, $status, $status === 302 ? ['Location' => 'https://lain.example/'] : [])]);

            $e = $this->galat(fn () => $this->klien()->get('ping'));

            $this->assertSame($kode, $e->kode, "status {$status}");
            $this->assertStringNotContainsString('exu_', $e->getMessage());
            $this->assertStringNotContainsString('isi rahasia', $e->getMessage());
        }
    }

    public function test_jawaban_bukan_json_dan_koneksi_putus(): void
    {
        Http::fake(['*' => Http::response('<html>halaman login</html>', 200)]);
        $this->assertSame('bukan_json', $this->galat(fn () => $this->klien()->get('ping'))->kode);

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => throw new ConnectionException('cURL error 6: could not resolve host eduweb.contoh.sch.id'));
        $e = $this->galat(fn () => $this->klien()->get('ping'));
        $this->assertSame('koneksi', $e->kode);
        $this->assertStringNotContainsString('cURL', $e->getMessage());
    }

    // ── Perintah eduweb:ping ────────────────────────────────────────────────

    private function atur(): void
    {
        config(['eduweb.url' => self::URL, 'eduweb.kunci' => self::KUNCI, 'eduweb.timeout' => 5]);
    }

    public function test_perintah_ping_tanpa_konfigurasi_gagal_dengan_petunjuk(): void
    {
        config(['eduweb.url' => null, 'eduweb.kunci' => null]);

        $this->artisan('eduweb:ping')->expectsOutputToContain('EDUWEB_URL dan EDUWEB_KUNCI')->assertFailed();
    }

    public function test_perintah_ping_berhasil_menampilkan_identitas_sekolah(): void
    {
        $this->atur();
        Http::fake(['*' => Http::response($this->ping())]);

        $this->artisan('eduweb:ping')->expectsOutputToContain('Terhubung ke EduWeb')->expectsOutputToContain('SMK Uji')->assertSuccessful();
    }

    public function test_perintah_ping_melaporkan_selisih_jam_dan_kunci_ditolak(): void
    {
        $this->atur();
        Http::fake(['*' => Http::response($this->ping(['server_time' => now()->subMinutes(10)->toIso8601String()]))]);
        $this->artisan('eduweb:ping')->expectsOutputToContain('periksa zona waktu')->assertSuccessful();

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['message' => 'Unauthorized.'], 401)]);
        $this->artisan('eduweb:ping')->expectsOutputToContain('EduWeb menolak kunci')->assertFailed();
    }

    public function test_perintah_ping_penuh_memeriksa_semua_endpoint(): void
    {
        $this->atur();
        Http::fake([
            '*/ping*' => Http::response($this->ping()),
            '*/students*' => Http::response(['data' => [], 'total' => 321]),
            '*/teachers*' => Http::response(['data' => [], 'total' => 28]),
            '*/events*' => Http::response(['data' => [], 'last_id' => 77, 'has_more' => false]),
            '*' => Http::response(['data' => [['id' => 1], ['id' => 2]]]),
        ]);

        $this->artisan('eduweb:ping --penuh')->expectsOutputToContain('321')->expectsOutputToContain('kejadian terbaru: 77')->assertSuccessful();
        Http::assertSentCount(9);
    }

    public function test_perintah_ping_penuh_gagal_bila_satu_endpoint_gagal(): void
    {
        $this->atur();
        Http::fake([
            '*/ping*' => Http::response($this->ping()),
            '*/students*' => Http::response('galat', 500),
            '*' => Http::response(['data' => []]),
        ]);

        $this->artisan('eduweb:ping --penuh')->expectsOutputToContain('GAGAL')->assertFailed();
    }
}
