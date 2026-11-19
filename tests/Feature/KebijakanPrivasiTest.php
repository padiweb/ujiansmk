<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KebijakanPrivasiTest extends TestCase
{
    use RefreshDatabase;

    public function test_kebijakan_privasi_terbuka_tanpa_login_dan_ditautkan_dari_halaman_masuk(): void
    {
        $this->get('/kebijakan-privasi')->assertOk()->assertSee('Kebijakan Privasi')->assertSee('FLAG_SECURE');
        $this->get('/')->assertOk()->assertSee(route('privasi'), false);
    }
}
