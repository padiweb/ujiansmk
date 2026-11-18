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

class BankGuruLainTest extends TestCase
{
    use RefreshDatabase;

    private Kelas $kelasX;

    private KegiatanUjian $aktif;

    private KegiatanUjian $lama;

    protected function setUp(): void
    {
        parent::setUp();
        $tahun = TahunAjaran::create(['nama' => '2026/2027', 'aktif' => true]);
        $this->kelasX = Kelas::create(['tahun_ajaran_id' => $tahun->id, 'tingkat' => 'X', 'nama' => 'X TKJ 1']);
        $genap = Periode::create(['tahun_ajaran_id' => $tahun->id, 'semester' => 'genap', 'tipe' => 'reguler', 'aktif' => false]);
        $ganjil = Periode::create(['tahun_ajaran_id' => $tahun->id, 'semester' => 'ganjil', 'tipe' => 'reguler', 'aktif' => true]);
        $this->lama = KegiatanUjian::create(['periode_id' => $genap->id, 'nama' => 'Asesmen Genap', 'aktif' => false]);
        $this->aktif = KegiatanUjian::create(['periode_id' => $ganjil->id, 'nama' => 'Asesmen Tengah Semester', 'aktif' => true]);
    }

    private function mapel(string $nama, string $kode): Mapel
    {
        return Mapel::create(['kode' => $kode, 'nama' => $nama, 'kelompok' => 'A', 'kkm' => 75]);
    }

    private function bank(User $pembuat, Mapel $mapel, int $soal, ?KegiatanUjian $kegiatan): BankSoal
    {
        $b = BankSoal::create(['mapel_id' => $mapel->id, 'tingkat' => 'X', 'kegiatan_id' => $kegiatan?->id, 'nama' => 'Bank '.$mapel->nama, 'jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $pembuat->id]);
        for ($i = 1; $i <= $soal; $i++) {
            $b->soal()->create(['tipe' => 'esai', 'pertanyaan' => "Rahasia {$i} {$mapel->nama}", 'bobot' => 1, 'status' => 'siap', 'sumber' => 'manual', 'urutan' => $i]);
        }

        return $b;
    }

    public function test_guru_melihat_bank_guru_lain_hanya_pada_kegiatan_aktif_tanpa_isi_soalnya(): void
    {
        $saya = User::factory()->create(['nama' => 'Guru Saya']);
        $rina = User::factory()->create(['nama' => 'Bu Rina']);
        $bio = $this->mapel('Biologi', 'BIO');
        $fis = $this->mapel('Fisika', 'FIS');
        $kim = $this->mapel('Kimia', 'KIM');
        $this->bank($rina, $bio, 3, $this->aktif);   // tampil
        $this->bank($rina, $fis, 2, $this->lama);    // kegiatan tidak aktif: tidak tampil
        $this->bank($rina, $kim, 2, null);           // bank umum: tidak tampil
        $this->bank($rina, $this->mapel('Sejarah', 'SEJ'), 0, $this->aktif); // kosong: tidak tampil

        $html = $this->actingAs($saya, 'web')->get(route('staf.bank-soal.index'))->assertOk()
            ->assertSee('Bank soal guru lain')->assertSee('Bu Rina')->assertSee('Biologi')->getContent();

        $this->assertStringNotContainsString('Fisika', $html);
        $this->assertStringNotContainsString('Kimia', $html);
        $this->assertStringNotContainsString('Sejarah', $html);
        $this->assertStringNotContainsString('Rahasia 1', $html); // isi soal tidak dibuka
    }

    public function test_bank_sendiri_tidak_masuk_daftar_guru_lain_dan_pengingat_menandai_yang_belum_dibuat(): void
    {
        $saya = User::factory()->create(['nama' => 'Guru Saya']);
        $rina = User::factory()->create(['nama' => 'Bu Rina']);
        $bio = $this->mapel('Biologi', 'BIO');
        $ipa = $this->mapel('IPA Terapan', 'IPA');
        $mtk = $this->mapel('Matematika', 'MTK');
        foreach ([$bio, $ipa, $mtk] as $m) {
            Pengampu::create(['guru_id' => $saya->id, 'mapel_id' => $m->id, 'kelas_id' => $this->kelasX->id]);
        }
        $this->bank($saya, $mtk, 4, $this->aktif); // sudah dibuat sendiri
        $this->bank($rina, $bio, 3, $this->aktif); // guru lain sudah

        $html = $this->actingAs($saya, 'web')->get(route('staf.bank-soal.index'))->assertOk()
            ->assertSee('Bank soal yang belum Anda buat')->getContent();

        $awal = strpos($html, 'bank-info-daftar');
        $pengingat = substr($html, $awal, strpos($html, 'padding-bottom:80px') - $awal);
        $this->assertStringContainsString('Biologi', $pengingat);
        $this->assertStringContainsString('sudah dibuat oleh Bu Rina', $pengingat);
        $this->assertStringContainsString('IPA Terapan', $pengingat);
        $this->assertStringContainsString('belum ada yang membuat', $pengingat);
        $this->assertStringNotContainsString('Matematika', $pengingat); // sudah dibuat sendiri
        // Bank milik sendiri tidak muncul di tabel "guru lain".
        $this->assertStringNotContainsString('Guru Saya</td>', $html);
    }

    public function test_tanpa_kegiatan_aktif_bagian_ini_tidak_tampil_dan_admin_tidak_mendapatkannya(): void
    {
        $saya = User::factory()->create();
        $rina = User::factory()->create();
        $bio = $this->mapel('Biologi', 'BIO');
        Pengampu::create(['guru_id' => $saya->id, 'mapel_id' => $bio->id, 'kelas_id' => $this->kelasX->id]);
        $this->bank($rina, $bio, 3, $this->aktif);

        $this->actingAs(User::factory()->admin()->create(), 'web')->get(route('staf.bank-soal.index'))->assertOk()
            ->assertDontSee('Bank soal guru lain')->assertDontSee('Bank soal yang belum Anda buat');

        $this->aktif->update(['aktif' => false]);
        $this->actingAs($saya, 'web')->get(route('staf.bank-soal.index'))->assertOk()
            ->assertDontSee('Bank soal guru lain')->assertDontSee('Bank soal yang belum Anda buat');
    }

    public function test_guru_tetap_tidak_bisa_membuka_bank_guru_lain(): void
    {
        $saya = User::factory()->create();
        $rina = User::factory()->create();
        $b = $this->bank($rina, $this->mapel('Biologi', 'BIO'), 3, $this->aktif);

        $this->actingAs($saya, 'web')->get(route('staf.bank-soal.show', $b))->assertForbidden();
    }
}
