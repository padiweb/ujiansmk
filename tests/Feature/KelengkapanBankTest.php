<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Pengampu;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KelengkapanBankTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $tahun = TahunAjaran::create(['nama' => '2026/2027', 'aktif' => true]);
        $this->kelasX = Kelas::create(['tahun_ajaran_id' => $tahun->id, 'tingkat' => 'X', 'nama' => 'X TKJ 1']);
    }

    private Kelas $kelasX;

    private function guruMengampu(string $nama, string $mapelNama, string $kode): array
    {
        $guru = User::factory()->create(['nama' => $nama]);
        $mapel = Mapel::create(['kode' => $kode, 'nama' => $mapelNama, 'kelompok' => 'A', 'kkm' => 75]);
        Pengampu::create(['guru_id' => $guru->id, 'mapel_id' => $mapel->id, 'kelas_id' => $this->kelasX->id]);

        return [$guru, $mapel];
    }

    private function bank(User $pembuat, Mapel $mapel, int $jumlahSoal): void
    {
        $b = BankSoal::create(['mapel_id' => $mapel->id, 'tingkat' => 'X', 'nama' => 'Bank '.$mapel->nama, 'jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $pembuat->id]);
        for ($i = 1; $i <= $jumlahSoal; $i++) {
            $b->soal()->create(['tipe' => 'esai', 'pertanyaan' => "Soal {$i}", 'bobot' => 1, 'status' => 'siap', 'sumber' => 'manual', 'urutan' => $i]);
        }
    }

    public function test_hanya_admin_dan_status_tiap_guru_ditampilkan(): void
    {
        [$gSudah, $mSudah] = $this->guruMengampu('Guru Sudah', 'Matematika', 'MTK');
        [$gKosong, $mKosong] = $this->guruMengampu('Guru Kosong', 'Bahasa Inggris', 'BIN');
        $this->guruMengampu('Guru Belum', 'Sejarah', 'SJH');
        [, $mLain] = $this->guruMengampu('Guru Lain', 'Seni Rupa', 'SR');
        $this->bank($gSudah, $mSudah, 3);
        $this->bank($gKosong, $mKosong, 0);
        $this->bank($this->admin, $mLain, 5); // dibuat admin (misalnya hasil pemindahan), bukan oleh guru pengampu

        $this->actingAs($gSudah, 'web')->get('/staf/kelengkapan-bank-soal')->assertForbidden();

        $this->actingAs($this->admin, 'web')->get('/staf/kelengkapan-bank-soal')->assertOk()
            ->assertSeeInOrder(['Guru Belum', 'Guru Kosong', 'Guru Lain', 'Guru Sudah'])
            ->assertSee('Belum ada bank')->assertSee('Bank masih kosong')->assertSee('Ada bank dari pengguna lain')->assertSee('Sudah ada soal');

        $this->get('/staf/kelengkapan-bank-soal?status=belum')->assertOk()
            ->assertSee('Guru Belum')->assertDontSee('Guru Sudah')->assertDontSee('Guru Kosong');
        $this->get('/staf/kelengkapan-bank-soal?q=sejarah')->assertOk()->assertSee('Guru Belum')->assertDontSee('Guru Sudah');
        $this->get('/staf/kelengkapan-bank-soal?tingkat=XII')->assertOk()->assertSee('Tidak ada data yang sesuai');
    }

    public function test_tanpa_tahun_ajaran_aktif_tidak_galat(): void
    {
        TahunAjaran::query()->update(['aktif' => false]);

        $this->actingAs($this->admin, 'web')->get('/staf/kelengkapan-bank-soal')->assertOk()->assertSee('Belum ada tahun ajaran aktif');
    }

    public function test_tautan_hanya_tampil_untuk_admin_di_halaman_bank_soal(): void
    {
        $guru = User::factory()->create();

        $this->actingAs($this->admin, 'web')->get('/staf/bank-soal')->assertOk()->assertSee('Kelengkapan bank soal');
        $this->actingAs($guru, 'web')->get('/staf/bank-soal')->assertOk()->assertDontSee('Kelengkapan bank soal');
    }
}
