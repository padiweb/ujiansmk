<?php

namespace Tests\Feature;

use Tests\TestCase;

class AlamatLamaTest extends TestCase
{
    public function test_alamat_aplikasi_lama_diarahkan_ke_halaman_baru(): void
    {
        $this->get('/mulai.php')->assertRedirect('/');
        $this->get('/login.php')->assertRedirect('/');
        $this->get('/admin')->assertRedirect('/staf/masuk');
        $this->get('/admin/login.php')->assertRedirect('/staf/masuk');
    }

    public function test_service_worker_lama_diganti_pembersih(): void
    {
        $isi = file_get_contents(public_path('pwa/sw.js'));
        $this->assertStringContainsString('unregister', $isi);
        $this->assertStringContainsString('caches.delete', $isi);
    }
}
