<?php

namespace Tests\Feature;

use App\Models\HasilUjian;
use App\Models\JadwalUjian;
use App\Models\KegiatanUjian;
use App\Models\SesiUjian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class HapusKegiatanUjiTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private KegiatanUjian $uji;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian();
        $this->uji = KegiatanUjian::create(['periode_id' => $this->jadwal->periode_id, 'nama' => 'UJI COBA', 'aktif' => true, 'untuk_rapor' => false]);
        $this->jadwal->update(['kegiatan_id' => $this->uji->id]);
        $this->mulaiSesi($this->siswa[0]);
    }

    public function test_bawaan_hanya_menampilkan_dan_tidak_menghapus(): void
    {
        $this->artisan('ujian:hapus-kegiatan', ['kegiatan' => $this->uji->id])
            ->expectsOutputToContain('Hanya tampilan')->assertSuccessful();

        $this->assertSame(1, JadwalUjian::count());
        $this->assertSame(2, SesiUjian::count());
    }

    public function test_menghapus_jadwal_sesi_dan_isinya_bila_nama_cocok_dan_bank_soal_tetap_ada(): void
    {
        $bankId = $this->jadwal->bank()->first()->bank_soal_id;

        $this->artisan('ujian:hapus-kegiatan', ['kegiatan' => $this->uji->id, '--yakin' => 'UJI COBA'])->assertSuccessful();

        $this->assertSame(0, JadwalUjian::count());
        $this->assertSame(0, SesiUjian::count());
        $this->assertSame(0, HasilUjian::count());
        $this->assertTrue(KegiatanUjian::whereKey($this->uji->id)->exists());
        $this->assertSame(1, \App\Models\BankSoal::whereKey($bankId)->count());
        $this->assertSame(3, \App\Models\Soal::count());
        $this->assertDatabaseHas('audit_log', ['aksi' => 'kegiatan.hapus_isi']);
    }

    public function test_dapat_menghapus_kegiatannya_sekalian(): void
    {
        $this->artisan('ujian:hapus-kegiatan', ['kegiatan' => $this->uji->id, '--yakin' => 'UJI COBA', '--dengan-kegiatan' => true])->assertSuccessful();

        $this->assertFalse(KegiatanUjian::whereKey($this->uji->id)->exists());
    }

    public function test_nama_salah_atau_kegiatan_untuk_rapor_ditolak(): void
    {
        $this->artisan('ujian:hapus-kegiatan', ['kegiatan' => $this->uji->id, '--yakin' => 'uji coba'])->assertFailed();
        $this->assertSame(1, JadwalUjian::count());

        $this->uji->update(['untuk_rapor' => true]);
        $this->artisan('ujian:hapus-kegiatan', ['kegiatan' => $this->uji->id, '--yakin' => 'UJI COBA'])
            ->expectsOutputToContain('untuk rapor')->assertFailed();
        $this->assertSame(1, JadwalUjian::count());

        $this->artisan('ujian:hapus-kegiatan', ['kegiatan' => 99999])->assertFailed();
    }

    public function test_kegiatan_lain_tidak_tersentuh(): void
    {
        $lain = KegiatanUjian::create(['periode_id' => $this->jadwal->periode_id, 'nama' => 'ASESMEN', 'aktif' => true, 'untuk_rapor' => true]);
        $jadwalLain = JadwalUjian::create([
            'periode_id' => $this->jadwal->periode_id, 'kegiatan_id' => $lain->id, 'mapel_id' => $this->mtk->id, 'nama' => 'Jadwal Asli', 'durasi_menit' => 60,
            'mulai_pada' => now()->addDay(), 'tutup_pada' => now()->addDays(2), 'dibuat_oleh' => $this->admin->id, 'status' => 'draf',
        ]);

        $this->artisan('ujian:hapus-kegiatan', ['kegiatan' => $this->uji->id, '--yakin' => 'UJI COBA'])->assertSuccessful();

        $this->assertTrue(JadwalUjian::whereKey($jadwalLain->id)->exists());
    }
}
