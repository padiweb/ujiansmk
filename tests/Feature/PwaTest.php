<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_memuat_penanda_pemasangan_dengan_nama_altan_exam(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('rel="manifest"', $html);
        $this->assertStringContainsString('name="apple-mobile-web-app-title" content="Altan Exam"', $html);
        $this->assertStringContainsString('rel="apple-touch-icon"', $html);
        $this->assertStringContainsString('Pasang sebagai aplikasi', $html);
        $this->get('/staf/masuk')->assertOk()->assertSee('apple-mobile-web-app-title', false);
    }

    public function test_manifest_sah_dan_semua_ikon_serta_service_worker_tersedia(): void
    {
        $m = json_decode(file_get_contents(public_path('manifest.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Altan Exam', $m['name']);
        $this->assertSame('Altan Exam', $m['short_name']);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame('/', $m['start_url']);
        $ukuran = [];
        foreach ($m['icons'] as $ikon) {
            $berkas = public_path(ltrim($ikon['src'], '/'));
            $this->assertFileExists($berkas);
            $ukuran[] = getimagesize($berkas)[0].'x'.getimagesize($berkas)[1];
        }
        $this->assertContains('192x192', $ukuran);
        $this->assertContains('512x512', $ukuran);
        $this->assertSame([180, 180], array_slice(getimagesize(public_path('pwa/apple-touch-icon.png')), 0, 2));

        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("addEventListener('fetch'", $sw);
        $this->assertStringNotContainsString('caches.open', $sw); // tidak menyimpan isi halaman
    }
}
