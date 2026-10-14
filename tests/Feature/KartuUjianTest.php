<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Denah;
use App\Models\DenahRuang;
use App\Models\Kelas;
use App\Models\PengaturanSekolah;
use App\Models\Ruang;
use App\Models\Siswa;
use App\Models\User;
use App\Services\LayananDenah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class KartuUjianTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private Ruang $ruang;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 4);
        $this->ruang = Ruang::create(['nama' => 'Lab Komputer']);
        $denah = Denah::create(['nama' => 'UTS', 'periode_id' => $this->jadwal->periode_id]);
        DenahRuang::create(['denah_id' => $denah->id, 'ruang_id' => $this->ruang->id, 'jumlah_meja' => 5, 'siswa_per_meja' => 1]);
        app(LayananDenah::class)->bagi($denah, [$this->kelas->id], null, 3);
        $this->jadwal->update(['denah_id' => $denah->id]);
        app(LayananDenah::class)->terapkan($this->jadwal->refresh());
    }

    private function sandiLama(Siswa $s): bool
    {
        return Hash::check('kata-sandi-uji-123', $s->fresh()->password);
    }

    // ── Hak akses ───────────────────────────────────────────────────────────

    public function test_kartu_hanya_untuk_admin(): void
    {
        $guru = User::factory()->create();
        $pengawas = User::factory()->pengawas()->create();
        $this->jadwal->pengawas()->sync([$pengawas->id]);

        $this->actingAs($this->admin, 'web')->get(route('staf.kartu.index', $this->jadwal))->assertOk();
        $this->actingAs($guru, 'web')->get(route('staf.kartu.index', $this->jadwal))->assertForbidden();
        $this->actingAs($pengawas, 'web')->get(route('staf.kartu.index', $this->jadwal))->assertForbidden();
        $this->actingAs($pengawas, 'web')->post(route('staf.kartu.sandi', $this->jadwal), ['kelas' => [$this->kelas->id], 'format' => 'cetak'])->assertForbidden();
        $this->assertTrue($this->sandiLama($this->siswa[0]));
    }

    public function test_daftar_hadir_untuk_admin_dan_pengawas_tertugas(): void
    {
        $tertugas = User::factory()->pengawas()->create();
        $lain = User::factory()->pengawas()->create();
        $guru = User::factory()->create();
        $this->jadwal->pengawas()->sync([$tertugas->id]);

        $this->actingAs($this->admin, 'web')->get(route('staf.hadir', $this->jadwal))->assertOk();
        $this->actingAs($tertugas, 'web')->get(route('staf.hadir', $this->jadwal))->assertOk();
        $this->actingAs($lain, 'web')->get(route('staf.hadir', $this->jadwal))->assertForbidden();
        $this->actingAs($guru, 'web')->get(route('staf.hadir', $this->jadwal))->assertForbidden();
    }

    public function test_pengawas_ruang_hanya_mencetak_ruangnya(): void
    {
        $ruangLain = Ruang::create(['nama' => 'Ruang Teori']);
        $denah = $this->jadwal->denah;
        DenahRuang::create(['denah_id' => $denah->id, 'ruang_id' => $ruangLain->id, 'jumlah_meja' => 2, 'siswa_per_meja' => 1]);
        app(LayananDenah::class)->tempatkan($denah, $this->siswa[0], $ruangLain->id, 1, 1);
        $pengawas = User::factory()->pengawas()->create();
        $this->jadwal->pengawas()->sync([$pengawas->id => ['ruang_id' => $this->ruang->id]]);

        $r = $this->actingAs($pengawas, 'web')->get(route('staf.hadir', $this->jadwal))->assertOk();

        $r->assertSee('Lab Komputer')->assertDontSee('Ruang Teori')->assertDontSee($this->siswa[0]->nama);
        $r->assertSee($this->siswa[1]->nama);
    }

    // ── Kartu ───────────────────────────────────────────────────────────────

    public function test_kartu_memuat_data_peserta_tanpa_kata_sandi(): void
    {
        $r = $this->actingAs($this->admin, 'web')->post(route('staf.kartu.cetak', $this->jadwal), ['kelas' => [$this->kelas->id]])->assertOk();

        foreach ($this->siswa as $s) {
            $r->assertSee($s->nama)->assertSee($s->nisn);
        }
        $r->assertSee('Lab Komputer')->assertSee('UTS Matematika')->assertSee('gunakan kata sandi Anda')->assertDontSee('<code>', false);
        $this->assertTrue($this->sandiLama($this->siswa[0]));
    }

    public function test_kartu_dibatasi_kelas_terpilih(): void
    {
        $lain = Kelas::create(['tahun_ajaran_id' => $this->kelas->tahun_ajaran_id, 'tingkat' => 'X', 'nama' => 'X Z']);
        $this->siswa[0]->kelas()->attach($lain->id, ['status' => 'aktif']);

        $r = $this->actingAs($this->admin, 'web')->post(route('staf.kartu.cetak', $this->jadwal), ['kelas' => [$lain->id]])->assertOk();
        $r->assertSee('Tidak ada peserta pada pilihan ini', false)->assertDontSee($this->siswa[1]->nama);
    }

    public function test_jadwal_draf_memakai_calon_peserta_dan_denah(): void
    {
        $this->actingAs($this->admin, 'web');
        app(\App\Services\LayananJadwal::class)->batalkanAktivasi($this->jadwal);

        $this->post(route('staf.kartu.cetak', $this->jadwal->refresh()), ['kelas' => [$this->kelas->id]])->assertOk()
            ->assertSee($this->siswa[0]->nama)->assertSee('Lab Komputer');
    }

    // ── Sandi massal ────────────────────────────────────────────────────────

    public function test_sandi_massal_mengganti_sandi_dan_tampil_sekali(): void
    {
        $this->actingAs($this->admin, 'web');

        $r = $this->post(route('staf.kartu.sandi', $this->jadwal), ['kelas' => [$this->kelas->id], 'format' => 'cetak'])->assertOk();

        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $isi = $r->getContent();
        preg_match_all('#<code>([^<]+)</code>#', $isi, $cocok);
        $this->assertCount(4, $cocok[1]);
        $this->assertCount(4, array_unique($cocok[1]));
        $dikenal = 0;
        foreach ($this->siswa as $s) {
            $this->assertFalse($this->sandiLama($s), 'sandi lama harus tidak berlaku');
            foreach ($cocok[1] as $kata) {
                $dikenal += Hash::check($kata, $s->fresh()->password) ? 1 : 0;
            }
        }
        $this->assertSame(4, $dikenal); // tiap sandi di kartu cocok dengan tepat satu siswa
        $this->assertSame(1, AuditLog::where('aksi', 'kartu.sandi_massal')->count());
        $this->assertStringNotContainsString($cocok[1][0], AuditLog::where('aksi', 'kartu.sandi_massal')->value('detail'));

        // Kartu berikutnya tidak lagi memuat sandi.
        $this->post(route('staf.kartu.cetak', $this->jadwal), ['kelas' => [$this->kelas->id]])->assertDontSee('<code>', false);
    }

    public function test_sandi_massal_hanya_kelas_terpilih(): void
    {
        $lain = Kelas::create(['tahun_ajaran_id' => $this->kelas->tahun_ajaran_id, 'tingkat' => 'X', 'nama' => 'X Z']);
        $this->jadwal->kelas()->attach($lain->id);
        $siswaLain = Siswa::factory()->create();
        $siswaLain->kelas()->attach($lain->id, ['status' => 'aktif']);
        $sesi = \App\Models\SesiUjian::create(['jadwal_id' => $this->jadwal->id, 'siswa_id' => $siswaLain->id, 'status' => 'belum', 'jumlah_pelanggaran' => 0, 'tambahan_menit' => 0]);

        $this->actingAs($this->admin, 'web')->post(route('staf.kartu.sandi', $this->jadwal), ['kelas' => [$this->kelas->id], 'format' => 'cetak'])->assertOk();

        $this->assertTrue($this->sandiLama($siswaLain));
        $this->assertFalse($this->sandiLama($this->siswa[0]));
        $this->assertNotNull($sesi);
    }

    public function test_sandi_massal_butuh_kelas_dan_format_valid(): void
    {
        $this->actingAs($this->admin, 'web');

        $this->post(route('staf.kartu.sandi', $this->jadwal), ['kelas' => [], 'format' => 'cetak'])->assertSessionHasErrors('kelas');
        $this->post(route('staf.kartu.sandi', $this->jadwal), ['kelas' => [$this->kelas->id], 'format' => 'pdf'])->assertSessionHasErrors('format');
        $this->assertTrue($this->sandiLama($this->siswa[0]));
    }

    public function test_sandi_massal_dibatasi_jumlahnya(): void
    {
        $this->actingAs($this->admin, 'web');
        $kelas = Kelas::create(['tahun_ajaran_id' => $this->kelas->tahun_ajaran_id, 'tingkat' => 'X', 'nama' => 'X Besar']);
        $this->jadwal->kelas()->attach($kelas->id);
        $banyak = Siswa::factory()->count(151)->create();
        foreach ($banyak as $s) {
            $s->kelas()->attach($kelas->id, ['status' => 'aktif']);
        }
        $baris = $banyak->map(fn ($s) => ['jadwal_id' => $this->jadwal->id, 'siswa_id' => $s->id, 'status' => 'belum', 'jumlah_pelanggaran' => 0, 'tambahan_menit' => 0, 'created_at' => now(), 'updated_at' => now()])->all();
        \App\Models\SesiUjian::insert($baris);

        $this->post(route('staf.kartu.sandi', $this->jadwal), ['kelas' => [$kelas->id], 'format' => 'cetak'])->assertSessionHas('gagal');
        $this->assertTrue($this->sandiLama($banyak[0]));
    }

    public function test_sandi_massal_ditolak_saat_mode_sinkron_eduweb_aktif(): void
    {
        PengaturanSekolah::ambil()->update(['mode_sinkron_eduweb' => true]);

        $this->actingAs($this->admin, 'web')->post(route('staf.kartu.sandi', $this->jadwal), ['kelas' => [$this->kelas->id], 'format' => 'cetak'])
            ->assertSessionHas('gagal');
        $this->assertTrue($this->sandiLama($this->siswa[0]));
    }

    public function test_sandi_massal_excel_aman_dari_rumus(): void
    {
        $this->siswa[0]->update(['nama' => '=CMD|calc']);

        $r = $this->actingAs($this->admin, 'web')->post(route('staf.kartu.sandi', $this->jadwal), ['kelas' => [$this->kelas->id], 'format' => 'excel'])->assertOk();
        $this->assertStringContainsString('spreadsheetml', $r->headers->get('Content-Type'));

        $tmp = tempnam(sys_get_temp_dir(), 'kt').'.xlsx';
        file_put_contents($tmp, $r->streamedContent());
        $lembar = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);

        $this->assertSame('Kata sandi sementara', $lembar->getCell('D1')->getValue());
        $namaSel = collect(range(2, 5))->map(fn ($n) => $lembar->getCell("A{$n}"))->first(fn ($c) => str_contains((string) $c->getValue(), 'CMD'));
        $this->assertSame('s', $namaSel->getDataType());
        $sandi = collect(range(2, 5))->map(fn ($n) => (string) $lembar->getCell("D{$n}")->getValue());
        $this->assertCount(4, $sandi->filter(fn ($k) => strlen($k) === 8));
        $this->assertFalse($this->sandiLama($this->siswa[1]));
    }
}
