<?php

namespace Tests\Feature;

use App\Services\LayananSesi;
use App\Support\InfoPerangkat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class InfoPerangkatTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private const ANDROID_CHROME = 'Mozilla/5.0 (Linux; Android 13; SM-A135F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

    private const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1';

    public function test_user_agent_dibaca_menjadi_sistem_operasi_dan_peramban(): void
    {
        $kasus = [
            [self::ANDROID_CHROME, 'Android 13', 'Chrome'],
            [str_replace('Chrome/120', 'SamsungBrowser/23.0 Chrome/115', self::ANDROID_CHROME), 'Android 13', 'Samsung Internet'],
            ['Mozilla/5.0 (Android 12; Mobile; rv:120.0) Gecko/120.0 Firefox/120.0', 'Android 12', 'Firefox'],
            [self::IPHONE_SAFARI, 'iOS 17', 'Safari'],
            [str_replace('Version/17.2', 'CriOS/120.0.0.0', self::IPHONE_SAFARI), 'iOS 17', 'Chrome'],
            [str_replace('Chrome/120.0.0.0', 'Chrome/120.0.0.0 EdgA/120.0', self::ANDROID_CHROME), 'Android 13', 'Edge'],
            ['Mozilla/5.0 (Linux; Android 13; Redmi) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120 Mobile Safari/537.36 [FBAN/FB4A;FBAV/450.0]', 'Android 13', 'Facebook (dalam aplikasi)'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36', 'Windows', 'Chrome'],
            ['', 'Tidak dikenal', 'Tidak dikenal'],
        ];
        foreach ($kasus as [$ua, $os, $peramban]) {
            $this->assertSame($os, InfoPerangkat::sistemOperasi($ua), $ua);
            $this->assertSame($peramban, InfoPerangkat::peramban($ua), $ua);
        }
    }

    public function test_ipad_dengan_situs_desktop_dikenali_dari_layar_sentuh(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15';

        $this->assertSame('macOS', InfoPerangkat::sistemOperasi($ua, false));
        $this->assertSame('iPadOS', InfoPerangkat::sistemOperasi($ua, true));
    }

    public function test_mode_tampil_dari_klien_dan_aplikasi_sekolah(): void
    {
        $this->assertSame('pwa', InfoPerangkat::dari(self::IPHONE_SAFARI, 'pwa')['mode_tampil']);
        $this->assertSame('peramban', InfoPerangkat::dari(self::IPHONE_SAFARI, null)['mode_tampil']);
        $this->assertSame('peramban', InfoPerangkat::dari(self::IPHONE_SAFARI, 'sembarang')['mode_tampil']);
        $this->assertSame('aplikasi', InfoPerangkat::dari(self::ANDROID_CHROME.' UjianSMKAltan/1.0', 'pwa')['mode_tampil']);
    }

    public function test_sesi_menyimpan_info_perangkat_dan_monitor_menampilkannya(): void
    {
        $this->siapkanUjian();
        $pengawas = \App\Models\User::factory()->pengawas()->create();
        $sesi = $this->sesiDari($this->siswa[0]);

        $permintaan = Request::create('/', 'POST', ['mode_tampil' => 'pwa', 'sentuh' => '1'], [], [], ['HTTP_USER_AGENT' => self::IPHONE_SAFARI]);
        app(LayananSesi::class)->mulai($sesi, null, $permintaan);

        $sesi->refresh();
        $this->assertSame(['iOS 17', 'Safari', 'pwa'], [$sesi->sistem_operasi, $sesi->nama_peramban, $sesi->mode_tampil]);

        $d = $this->actingAs($this->admin, 'web')->getJson("/staf/monitor/{$this->jadwal->id}/data")->assertOk()->json();
        $baris = collect($d['peserta'])->firstWhere('id', $sesi->id);
        $this->assertSame(['iOS 17', 'Safari', 'pwa'], [$baris['sistem_operasi'], $baris['nama_peramban'], $baris['mode_tampil']]);
    }

    public function test_formulir_mulai_membawa_penanda_mode_tampil(): void
    {
        $this->siapkanUjian();

        $this->actingAs($this->siswa[0], 'siswa')
            ->post(route('siswa.ujian.token.kirim', $this->jadwal), ['token' => $this->jadwal->token])
            ->assertRedirect(route('siswa.ujian.siap', $this->jadwal));

        $this->get(route('siswa.ujian.siap', $this->jadwal))->assertOk()
            ->assertSee('name="mode_tampil"', false)->assertSee('display-mode: standalone', false);
    }
}
