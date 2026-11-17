<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigasiMobileTest extends TestCase
{
    use RefreshDatabase;

    private function tab(User $u): string
    {
        $html = $this->actingAs($u, 'web')->get(route('staf.beranda'))->assertOk()->getContent();
        preg_match('/<nav class="bawah".*?<\/nav>/s', $html, $m);
        $this->assertNotEmpty($m, 'Bilah tab bawah tidak ditemukan.');

        return $m[0];
    }

    public function test_admin_mendapat_tab_monitor_jadwal_dan_bank_soal(): void
    {
        $tab = $this->tab(User::factory()->admin()->create());

        foreach (['Beranda', 'Monitor', 'Jadwal', 'Bank Soal', 'Menu'] as $label) {
            $this->assertStringContainsString($label, $tab);
        }
        $this->assertStringContainsString('aria-current="page"', $tab); // tab Beranda aktif
    }

    public function test_guru_mendapat_tab_koreksi_dan_nilai_tanpa_monitor(): void
    {
        $tab = $this->tab(User::factory()->create());

        foreach (['Beranda', 'Bank Soal', 'Koreksi', 'Nilai', 'Menu'] as $label) {
            $this->assertStringContainsString($label, $tab);
        }
        $this->assertStringNotContainsString('Monitor', $tab);
        $this->assertStringNotContainsString('Jadwal', $tab);
    }

    public function test_guru_pengawas_mendapat_tab_monitor_sebagai_ganti_nilai(): void
    {
        $tab = $this->tab(User::factory()->guruPengawas()->create());

        $this->assertStringContainsString('Monitor', $tab);
        $this->assertStringContainsString('Koreksi', $tab);
        $this->assertStringNotContainsString('>Nilai<', $tab);
    }

    public function test_pengawas_hanya_mendapat_beranda_dan_monitor(): void
    {
        $tab = $this->tab(User::factory()->pengawas()->create());

        $this->assertStringContainsString('Monitor', $tab);
        $this->assertStringContainsString('Menu', $tab);
        foreach (['Bank Soal', 'Koreksi', 'Jadwal'] as $label) {
            $this->assertStringNotContainsString($label, $tab);
        }
    }

    public function test_tabel_monitor_dan_daftar_jadwal_memakai_tampilan_kartu(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->get(route('staf.jadwal.index'))->assertOk()->assertSee('tabel-kartu', false);
        $this->get(route('staf.monitor.index'))->assertOk();
        $this->assertFileExists(public_path('js/tabel-kartu.js'));
    }
}
