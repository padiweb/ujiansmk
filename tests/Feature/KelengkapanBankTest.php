<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\Kelas;
use App\Models\KegiatanUjian;
use App\Models\Mapel;
use App\Models\Pengampu;
use App\Models\Periode;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KelengkapanBankTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Kelas $kelasX;

    private KegiatanUjian $kegiatan;

    private KegiatanUjian $kegiatanLama;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $tahun = TahunAjaran::create(['nama' => '2026/2027', 'aktif' => true]);
        $this->kelasX = Kelas::create(['tahun_ajaran_id' => $tahun->id, 'tingkat' => 'X', 'nama' => 'X TKJ 1']);
        $lama = Periode::create(['tahun_ajaran_id' => $tahun->id, 'semester' => 'genap', 'tipe' => 'reguler', 'aktif' => false]);
        $baru = Periode::create(['tahun_ajaran_id' => $tahun->id, 'semester' => 'ganjil', 'tipe' => 'reguler', 'aktif' => true]);
        $this->kegiatanLama = KegiatanUjian::create(['periode_id' => $lama->id, 'nama' => 'Asesmen Semester Genap', 'aktif' => false]);
        $this->kegiatan = KegiatanUjian::create(['periode_id' => $baru->id, 'nama' => 'Asesmen Tengah Semester', 'aktif' => true]);
    }

    private function guruMengampu(string $nama, string $mapelNama, string $kode): array
    {
        $guru = User::factory()->create(['nama' => $nama]);
        $mapel = Mapel::create(['kode' => $kode, 'nama' => $mapelNama, 'kelompok' => 'A', 'kkm' => 75]);
        Pengampu::create(['guru_id' => $guru->id, 'mapel_id' => $mapel->id, 'kelas_id' => $this->kelasX->id]);

        return [$guru, $mapel];
    }

    private function bank(User $pembuat, Mapel $mapel, int $jumlahSoal, ?KegiatanUjian $kegiatan): BankSoal
    {
        $b = BankSoal::create(['mapel_id' => $mapel->id, 'tingkat' => 'X', 'kegiatan_id' => $kegiatan?->id, 'nama' => 'Bank '.$mapel->nama, 'jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $pembuat->id]);
        for ($i = 1; $i <= $jumlahSoal; $i++) {
            $b->soal()->create(['tipe' => 'esai', 'pertanyaan' => "Soal {$i}", 'bobot' => 1, 'status' => 'siap', 'sumber' => 'manual', 'urutan' => $i]);
        }

        return $b;
    }

    public function test_hanya_admin_dan_status_tiap_guru_ditampilkan_untuk_kegiatan_terpilih(): void
    {
        [$gSudah, $mSudah] = $this->guruMengampu('Guru Sudah', 'Matematika', 'MTK');
        [$gKosong, $mKosong] = $this->guruMengampu('Guru Kosong', 'Bahasa Inggris', 'BIN');
        $this->guruMengampu('Guru Belum', 'Sejarah', 'SJH');
        [, $mLain] = $this->guruMengampu('Guru Lain', 'Seni Rupa', 'SR');
        $this->bank($gSudah, $mSudah, 3, $this->kegiatan);
        $this->bank($gKosong, $mKosong, 0, $this->kegiatan);
        $this->bank($this->admin, $mLain, 5, $this->kegiatan); // dibuat admin untuk kegiatan ini

        $this->actingAs($gSudah, 'web')->get('/staf/kelengkapan-bank-soal')->assertForbidden();

        // Tanpa pilihan: kegiatan aktif terbaru.
        $this->actingAs($this->admin, 'web')->get('/staf/kelengkapan-bank-soal')->assertOk()
            ->assertSee('Asesmen Tengah Semester')
            ->assertSeeInOrder(['Guru Belum', 'Guru Kosong', 'Guru Lain', 'Guru Sudah'])
            ->assertSee('Belum ada bank')->assertSee('Bank masih kosong')->assertSee('Ada bank dari pengguna lain')->assertSee('Sudah ada soal');

        $this->get('/staf/kelengkapan-bank-soal?status=belum')->assertOk()
            ->assertSee('Guru Belum')->assertDontSee('Guru Sudah')->assertDontSee('Guru Kosong');
        $this->get('/staf/kelengkapan-bank-soal?q=sejarah')->assertOk()->assertSee('Guru Belum')->assertDontSee('Guru Sudah');
        $this->get('/staf/kelengkapan-bank-soal?tingkat=XII')->assertOk()->assertSee('Tidak ada data yang sesuai');
    }

    public function test_bank_umum_dan_bank_kegiatan_lain_tidak_dihitung(): void
    {
        [$g1, $m1] = $this->guruMengampu('Guru Soal Lama', 'Matematika', 'MTK');
        [$g2, $m2] = $this->guruMengampu('Guru Bank Umum', 'Sejarah', 'SJH');
        $this->bank($g1, $m1, 4, $this->kegiatanLama); // semester lampau
        $this->bank($g2, $m2, 4, null);                // bank umum/lama (hasil pemindahan)

        $this->actingAs($this->admin, 'web')->get('/staf/kelengkapan-bank-soal')->assertOk()
            ->assertSee('Guru Soal Lama')->assertSee('Guru Bank Umum')->assertDontSee('Sudah ada soal</span>', false);

        // Dilihat untuk kegiatan lama, guru pertama sudah ada soal.
        $this->get('/staf/kelengkapan-bank-soal?kegiatan='.$this->kegiatanLama->id.'&status=sendiri')->assertOk()
            ->assertSee('Guru Soal Lama')->assertDontSee('Guru Bank Umum');
    }

    public function test_tanpa_tahun_ajaran_aktif_atau_kegiatan_tidak_galat(): void
    {
        TahunAjaran::query()->update(['aktif' => false]);
        $this->actingAs($this->admin, 'web')->get('/staf/kelengkapan-bank-soal')->assertOk()->assertSee('Belum ada tahun ajaran aktif');

        TahunAjaran::query()->update(['aktif' => true]);
        KegiatanUjian::query()->delete();
        $this->get('/staf/kelengkapan-bank-soal')->assertOk()->assertSee('Belum ada kegiatan ujian');
    }

    public function test_tautan_hanya_tampil_untuk_admin_di_halaman_bank_soal(): void
    {
        $guru = User::factory()->create();

        $this->actingAs($this->admin, 'web')->get('/staf/bank-soal')->assertOk()->assertSee('Kelengkapan bank soal');
        $this->actingAs($guru, 'web')->get('/staf/bank-soal')->assertOk()->assertDontSee('Kelengkapan bank soal');
    }

    public function test_bank_baru_menyimpan_kegiatan_dan_formulir_memilih_kegiatan_aktif_secara_bawaan(): void
    {
        [$guru, $mapel] = $this->guruMengampu('Guru Baru', 'Matematika', 'MTK');

        $this->actingAs($guru, 'web')->get('/staf/bank-soal/tambah')->assertOk()
            ->assertSee('Untuk kegiatan ujian')
            ->assertSee('value="'.$this->kegiatan->id.'" selected', false);

        $this->post('/staf/bank-soal', ['nama' => 'Bank Uji', 'mapel_id' => $mapel->id, 'tingkat' => 'X', 'kegiatan_id' => $this->kegiatan->id, 'jumlah_opsi_bawaan' => 4])
            ->assertRedirect();
        $this->assertSame($this->kegiatan->id, BankSoal::where('nama', 'Bank Uji')->value('kegiatan_id'));

        $this->post('/staf/bank-soal', ['nama' => 'Bank Umum Uji', 'mapel_id' => $mapel->id, 'tingkat' => 'X', 'kegiatan_id' => '', 'jumlah_opsi_bawaan' => 4])
            ->assertRedirect();
        $this->assertNull(BankSoal::where('nama', 'Bank Umum Uji')->value('kegiatan_id'));
    }

    public function test_menyalin_bank_menautkan_salinan_ke_kegiatan_aktif(): void
    {
        [$guru, $mapel] = $this->guruMengampu('Guru Salin', 'Matematika', 'MTK');
        $asal = $this->bank($guru, $mapel, 2, null);

        $this->actingAs($guru, 'web')->post("/staf/bank-soal/{$asal->id}/salin")->assertRedirect();

        $salinan = BankSoal::where('id', '!=', $asal->id)->sole();
        $this->assertSame($this->kegiatan->id, $salinan->kegiatan_id);
        $this->assertNull($asal->fresh()->kegiatan_id);
    }
}
