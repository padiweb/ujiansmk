<?php

namespace Tests\Feature;

use App\Models\Jurusan;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Pengampu;
use App\Models\PengaturanSekolah;
use App\Models\Ruang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImporExcelTest extends TestCase
{
    use RefreshDatabase;

    private array $berkasSementara = [];

    protected function tearDown(): void
    {
        foreach ($this->berkasSementara as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'web');

        return $admin;
    }

    /** Buat berkas .xlsx; semua sel ditulis sebagai teks seperti templat asli. */
    private function xlsx(array $header, array $baris, string $nama = 'data.xlsx'): UploadedFile
    {
        $buku = new Spreadsheet;
        $lembar = $buku->getActiveSheet();
        foreach (array_merge([$header], $baris) as $r => $isi) {
            foreach (array_values($isi) as $c => $nilai) {
                if ($nilai !== null) {
                    $lembar->setCellValueExplicit([$c + 1, $r + 1], (string) $nilai, DataType::TYPE_STRING);
                }
            }
        }
        $jalur = tempnam(sys_get_temp_dir(), 'uji').'.xlsx';
        (new Xlsx($buku))->save($jalur);
        $this->berkasSementara[] = $jalur;

        return new UploadedFile($jalur, $nama, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private const HEADER_SISWA = ['NISN *', 'Nama *', 'NIS', 'L/P', 'Tempat Lahir', 'Tanggal Lahir', 'Nama Wali', 'No Peserta US', 'Kelas', 'Kata Sandi'];

    private function siapkanKelas(): Kelas
    {
        $tahun = TahunAjaran::create(['nama' => '2025/2026', 'aktif' => true]);

        return Kelas::create(['tahun_ajaran_id' => $tahun->id, 'tingkat' => 'X', 'nama' => 'X TKJ 1']);
    }

    private function pratinjauLalu(string $entitas, UploadedFile $berkas, bool $simpan = true): \Illuminate\Testing\TestResponse
    {
        $r = $this->post("/staf/master/{$entitas}/impor", ['berkas' => $berkas])->assertOk();
        if (! $simpan) {
            return $r;
        }
        $token = $r->viewData('token');
        $this->assertNotNull($token, 'Pratinjau seharusnya lolos.');

        return $this->post("/staf/master/{$entitas}/impor/{$token}")->assertOk();
    }

    public function test_templat_dapat_diunduh_untuk_semua_entitas(): void
    {
        $this->admin();

        foreach (['siswa', 'staf', 'jurusan', 'kelas', 'mapel', 'pengampu', 'ruang'] as $slug) {
            $this->get("/staf/master/{$slug}/impor")->assertOk();
            $this->get("/staf/master/{$slug}/impor/templat")->assertOk()
                ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
    }

    public function test_entitas_tanpa_impor_404(): void
    {
        $this->admin();

        $this->get('/staf/master/periode/impor')->assertNotFound();
    }

    public function test_pratinjau_tidak_menyimpan_apa_pun(): void
    {
        $this->admin();
        $this->siapkanKelas();
        $berkas = $this->xlsx(self::HEADER_SISWA, [['0012345678', 'Ahmad Fauzi', null, 'L', null, null, null, null, 'X TKJ 1', null]]);

        $r = $this->pratinjauLalu('siswa', $berkas, simpan: false);

        $this->assertTrue($r->viewData('hasil')->berhasil());
        $this->assertSame(1, $r->viewData('hasil')->baru);
        $this->assertSame(0, Siswa::count());
    }

    public function test_impor_siswa_menyimpan_dan_memasangkan_kelas(): void
    {
        $this->admin();
        $kelas = $this->siapkanKelas();
        $berkas = $this->xlsx(self::HEADER_SISWA, [
            ['0012345678', 'Ahmad Fauzi', '2425001', 'l', 'Sragen', '31/12/2008', 'Budi', null, 'x tkj 1', 'rahasia123'],
            ['0087654321', 'Siti Aminah', null, 'P', null, '2008-05-01', null, null, 'X TKJ 1', null],
        ]);

        $r = $this->pratinjauLalu('siswa', $berkas);
        $hasil = $r->viewData('hasil');

        $this->assertSame(2, $hasil->baru);
        $a = Siswa::firstWhere('nisn', '0012345678');
        $this->assertSame('Ahmad Fauzi', $a->nama);
        $this->assertSame('L', $a->jenis_kelamin);
        $this->assertSame('2008-12-31', $a->tgl_lahir->format('Y-m-d'));
        $this->assertSame([$kelas->id], $a->kelas->pluck('id')->all());
        $this->assertTrue(\Hash::check('rahasia123', $a->password));
        $this->assertStringStartsWith('$2y$', $a->getRawOriginal('password'));
    }

    public function test_nisn_dengan_nol_di_depan_tidak_rusak(): void
    {
        $this->admin();
        $berkas = $this->xlsx(self::HEADER_SISWA, [['0000123456', 'Nol Depan', null, null, null, null, null, null, null, null]]);

        $this->pratinjauLalu('siswa', $berkas);

        $this->assertNotNull(Siswa::firstWhere('nisn', '0000123456'));
    }

    public function test_sandi_acak_hanya_untuk_akun_baru_dan_bisa_diunduh_sekali(): void
    {
        $this->admin();
        $berkas = $this->xlsx(self::HEADER_SISWA, [['0012345678', 'Ahmad Fauzi', null, null, null, null, null, null, null, null]]);

        $r = $this->pratinjauLalu('siswa', $berkas);
        $hasil = $r->viewData('hasil');
        $tokenSandi = $r->viewData('tokenSandi');

        $this->assertCount(1, $hasil->sandi);
        [$nisn, , $sandi] = $hasil->sandi[0];
        $this->assertSame('0012345678', $nisn);
        $this->assertTrue(\Hash::check($sandi, Siswa::firstWhere('nisn', $nisn)->password));

        $this->get("/staf/impor/sandi/{$tokenSandi}")->assertOk()->assertDownload('kata-sandi-sementara.xlsx');
        $this->get("/staf/impor/sandi/{$tokenSandi}")->assertNotFound();
    }

    public function test_impor_ulang_memperbarui_dan_tidak_mengganti_sandi(): void
    {
        $this->admin();
        $berkas = fn (string $nama) => $this->xlsx(self::HEADER_SISWA, [['0012345678', $nama, null, null, null, null, null, null, null, null]]);

        $this->pratinjauLalu('siswa', $berkas('Nama Lama'));
        $sandiAsli = Siswa::firstWhere('nisn', '0012345678')->getRawOriginal('password');

        $r = $this->pratinjauLalu('siswa', $berkas('Nama Baru'));

        $this->assertSame(0, $r->viewData('hasil')->baru);
        $this->assertSame(1, $r->viewData('hasil')->diperbarui);
        $this->assertSame([], $r->viewData('hasil')->sandi);
        $this->assertSame(1, Siswa::count());
        $siswa = Siswa::firstWhere('nisn', '0012345678');
        $this->assertSame('Nama Baru', $siswa->nama);
        $this->assertSame($sandiAsli, $siswa->getRawOriginal('password'));
    }

    public function test_satu_baris_salah_membatalkan_seluruh_impor(): void
    {
        $this->admin();
        $this->siapkanKelas();
        $berkas = $this->xlsx(self::HEADER_SISWA, [
            ['0012345678', 'Ahmad Fauzi', null, null, null, null, null, null, 'X TKJ 1', null],
            ['0087654321', 'Siti Aminah', null, null, null, null, null, null, 'Kelas Hantu', null],
        ]);

        $r = $this->post('/staf/master/siswa/impor', ['berkas' => $berkas])->assertOk();
        $hasil = $r->viewData('hasil');

        $this->assertFalse($hasil->berhasil());
        $this->assertSame(3, $hasil->galat[0]['baris']);
        $this->assertStringContainsString('Kelas Hantu', $hasil->galat[0]['pesan']);
        $this->assertNull($r->viewData('token'));
        $this->assertSame(0, Siswa::count());
    }

    public function test_nisn_ganda_dalam_satu_berkas_ditolak(): void
    {
        $this->admin();
        $berkas = $this->xlsx(self::HEADER_SISWA, [
            ['0012345678', 'A', null, null, null, null, null, null, null, null],
            ['0012345678', 'B', null, null, null, null, null, null, null, null],
        ]);

        $hasil = $this->post('/staf/master/siswa/impor', ['berkas' => $berkas])->viewData('hasil');

        $this->assertStringContainsString('Duplikat dengan baris 2', $hasil->galat[0]['pesan']);
        $this->assertSame(0, Siswa::count());
    }

    public function test_validasi_isi_baris_dan_tanggal(): void
    {
        $this->admin();
        $berkas = $this->xlsx(self::HEADER_SISWA, [
            ['abc', 'Nisn Salah', null, null, null, null, null, null, null, null],
            ['0012345678', 'Tanggal Salah', null, null, null, 'kemarin', null, null, null, null],
            ['0012345679', 'Sandi Pendek', null, null, null, null, null, null, null, '123'],
        ]);

        $hasil = $this->post('/staf/master/siswa/impor', ['berkas' => $berkas])->viewData('hasil');

        $this->assertCount(3, $hasil->galat);
        $this->assertStringContainsString('NISN', $hasil->galat[0]['pesan']);
        $this->assertStringContainsString('Tanggal "kemarin"', $hasil->galat[1]['pesan']);
        $this->assertStringContainsString('Kata Sandi', $hasil->galat[2]['pesan']);
    }

    public function test_kolom_wajib_hilang_dilaporkan(): void
    {
        $this->admin();
        $berkas = $this->xlsx(['Nama'], [['Tanpa NISN']]);

        $hasil = $this->post('/staf/master/siswa/impor', ['berkas' => $berkas])->viewData('hasil');

        $this->assertStringContainsString('Kolom wajib tidak ditemukan', $hasil->galat[0]['pesan']);
        $this->assertStringContainsString('NISN', $hasil->galat[0]['pesan']);
    }

    public function test_urutan_kolom_bebas_dan_kolom_asing_diabaikan(): void
    {
        $this->admin();
        $berkas = $this->xlsx(['Nama', 'Kolom Asing', 'NISN'], [['Urutan Bebas', 'x', '0012345678']]);

        $this->pratinjauLalu('siswa', $berkas);

        $this->assertSame('Urutan Bebas', Siswa::firstWhere('nisn', '0012345678')->nama);
    }

    public function test_berkas_bukan_xlsx_ditolak(): void
    {
        $this->admin();
        $teks = UploadedFile::fake()->createWithContent('data.xlsx', 'bukan excel');

        $r = $this->post('/staf/master/siswa/impor', ['berkas' => $teks])->assertOk();

        $this->assertStringContainsString('tidak dapat dibaca', $r->viewData('hasil')->galat[0]['pesan']);
        $this->assertNull($r->viewData('token'));
        $this->assertSame(0, Siswa::count());
    }

    public function test_tanpa_berkas_ditolak(): void
    {
        $this->admin();

        $this->from('/staf/master/siswa/impor')->post('/staf/master/siswa/impor', [])->assertSessionHasErrors('berkas');
    }

    public function test_token_pratinjau_milik_pengguna_lain_tidak_bisa_dipakai(): void
    {
        $this->admin();
        $berkas = $this->xlsx(self::HEADER_SISWA, [['0012345678', 'A', null, null, null, null, null, null, null, null]]);
        $token = $this->post('/staf/master/siswa/impor', ['berkas' => $berkas])->viewData('token');

        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->post("/staf/master/siswa/impor/{$token}")->assertNotFound();
        $this->post('/staf/master/siswa/impor/'.str_repeat('a', 40))->assertNotFound();
        $this->post('/staf/master/siswa/impor/../../etc')->assertNotFound();
        $this->assertSame(0, Siswa::count());
    }

    public function test_token_tidak_bisa_dipakai_untuk_entitas_lain(): void
    {
        $this->admin();
        $berkas = $this->xlsx(self::HEADER_SISWA, [['0012345678', 'A', null, null, null, null, null, null, null, null]]);
        $token = $this->post('/staf/master/siswa/impor', ['berkas' => $berkas])->viewData('token');

        $this->post("/staf/master/ruang/impor/{$token}")->assertNotFound();
    }

    public function test_guru_tidak_bisa_mengimpor(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $berkas = $this->xlsx(self::HEADER_SISWA, [['0012345678', 'A', null, null, null, null, null, null, null, null]]);

        $this->post('/staf/master/siswa/impor', ['berkas' => $berkas])->assertForbidden();
        $this->get('/staf/master/siswa/impor')->assertForbidden();
    }

    public function test_mode_sinkron_menolak_impor(): void
    {
        $this->admin();
        PengaturanSekolah::ambil()->update(['mode_sinkron_eduweb' => true]);
        $berkas = $this->xlsx(self::HEADER_SISWA, [['0012345678', 'A', null, null, null, null, null, null, null, null]]);

        $this->post('/staf/master/siswa/impor', ['berkas' => $berkas])->assertForbidden();
        $this->assertSame(0, Siswa::count());
    }

    public function test_impor_guru_tidak_bisa_menyentuh_akun_admin(): void
    {
        $this->admin();
        User::factory()->admin()->create(['username' => 'bu.kepala']);
        $berkas = $this->xlsx(['Nama', 'Username', 'Peran'], [['Penyamar', 'bu.kepala', 'guru']]);

        $hasil = $this->post('/staf/master/staf/impor', ['berkas' => $berkas])->viewData('hasil');

        $this->assertStringContainsString('akun admin', $hasil->galat[0]['pesan']);
        $this->assertSame('admin', User::firstWhere('username', 'bu.kepala')->role);
    }

    public function test_peran_admin_tidak_bisa_dibuat_lewat_impor(): void
    {
        $this->admin();
        $berkas = $this->xlsx(['Nama', 'Username', 'Peran'], [['Calon Admin', 'calon', 'admin']]);

        $hasil = $this->post('/staf/master/staf/impor', ['berkas' => $berkas])->viewData('hasil');

        $this->assertFalse($hasil->berhasil());
        $this->assertNull(User::firstWhere('username', 'calon'));
    }

    public function test_alur_impor_lengkap_dari_jurusan_sampai_pengampu(): void
    {
        $this->admin();
        TahunAjaran::create(['nama' => '2025/2026', 'aktif' => true]);

        $this->pratinjauLalu('jurusan', $this->xlsx(['Kode', 'Nama'], [['TKJ', 'Teknik Komputer dan Jaringan']]));
        $this->pratinjauLalu('staf', $this->xlsx(['Nama', 'Username', 'Kata Sandi'], [['Siti Aminah', 'siti.aminah', 'sandi-panjang-1']]));
        $this->pratinjauLalu('kelas', $this->xlsx(['Nama Kelas', 'Tingkat', 'Kode Jurusan', 'Username Wali Kelas'], [['X TKJ 1', 'X', 'tkj', 'SITI.AMINAH']]));
        $this->pratinjauLalu('mapel', $this->xlsx(['Kode', 'Nama', 'Kelompok', 'KKM'], [['MTK', 'Matematika', 'A', '70']]));
        $this->pratinjauLalu('ruang', $this->xlsx(['Nama Ruang'], [['Lab 1'], ['Lab 2']]));
        $this->pratinjauLalu('pengampu', $this->xlsx(['Username Guru', 'Kode Mapel', 'Nama Kelas'], [['siti.aminah', 'MTK', 'X TKJ 1']]));

        $this->assertSame(1, Jurusan::count());
        $kelas = Kelas::with('waliKelas', 'jurusan')->first();
        $this->assertSame('siti.aminah', $kelas->waliKelas->username);
        $this->assertSame('TKJ', $kelas->jurusan->kode);
        $this->assertSame(70, Mapel::first()->kkm);
        $this->assertSame(2, Ruang::count());
        $this->assertSame(1, Pengampu::count());
        $this->assertTrue(\Hash::check('sandi-panjang-1', User::firstWhere('username', 'siti.aminah')->password));
    }

    public function test_impor_kelas_tanpa_tahun_ajaran_aktif_gagal_jelas(): void
    {
        $this->admin();

        $hasil = $this->post('/staf/master/kelas/impor', ['berkas' => $this->xlsx(['Nama Kelas', 'Tingkat'], [['X A', 'X']])])->viewData('hasil');

        $this->assertStringContainsString('tahun ajaran aktif', $hasil->galat[0]['pesan']);
    }

    public function test_impor_dicatat_di_audit_log(): void
    {
        $this->admin();

        $this->pratinjauLalu('ruang', $this->xlsx(['Nama Ruang'], [['Lab 1']]));

        $this->assertDatabaseHas('audit_log', ['aksi' => 'impor.ruang', 'detail' => '1 baru, 0 diperbarui']);
    }
}
