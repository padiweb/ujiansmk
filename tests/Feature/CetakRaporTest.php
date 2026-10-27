<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NilaiRapor;
use App\Models\Pengampu;
use App\Models\PengaturanSekolah;
use App\Models\Periode;
use App\Models\RaporSiswa;
use App\Models\SkalaPredikat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

class CetakRaporTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private User $wali;

    private Periode $periode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 2);
        foreach ([[90, 'A'], [80, 'B'], [70, 'C'], [0, 'D']] as [$min, $p]) {
            SkalaPredikat::create(['nilai_min' => $min, 'predikat' => $p]);
        }
        $this->wali = User::factory()->create(['nama' => 'Bu Wali Kelas']);
        $guru = User::factory()->create();
        $this->kelas->update(['wali_kelas_id' => $this->wali->id]);
        Pengampu::create(['guru_id' => $guru->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->kelas->id]);
        $this->periode = $this->jadwal->periode;

        $p = PengaturanSekolah::ambil();
        $p->update(['nama' => 'SMK Uji', 'alamat' => 'Jl. Contoh 1', 'kota' => 'Tanon', 'kepala_sekolah' => 'Pak Kepala', 'nip_kepala_sekolah' => '1980']);
    }

    private function url(string $akhir = ''): string
    {
        return "/staf/wali-kelas/{$this->kelas->id}{$akhir}";
    }

    private function finalkan(): void
    {
        foreach ($this->siswa as $s) {
            NilaiRapor::create([
                'siswa_id' => $s->id, 'mapel_id' => $this->mtk->id, 'periode_id' => $this->periode->id, 'kelas_id' => $this->kelas->id,
                'ujian' => 80, 'harian' => 80, 'tugas_akhir' => 80, 'praktik' => 80, 'adab' => 80, 'np' => 80, 'nk' => 78, 'na' => 79, 'predikat' => 'B', 'status' => 'draf',
            ]);
        }
        $this->actingAs($this->wali, 'web')->post($this->url(), ['rapor' => [
            $this->siswa[0]->id => ['sakit' => '2', 'izin' => '1', 'alpa' => '0', 'catatan' => 'Anak yang rajin', 'keputusan' => 'naik'],
            $this->siswa[1]->id => ['keputusan' => 'tinggal'],
        ]]);
        RaporSiswa::where('siswa_id', $this->siswa[0]->id)->update(['ekskul' => [['nama' => 'Pramuka', 'nilai' => 88, 'predikat' => 'B']]]);
        $this->post($this->url('/finalkan'))->assertSessionHas('berhasil');
    }

    private function hubungkanEduweb(): void
    {
        config(['eduweb.url' => 'http://127.0.0.1:8000/api/exam/v1', 'eduweb.kunci' => 'exu_'.str_repeat('a', 40)]);
        $this->periode->update(['mulai' => '2025-07-14', 'selesai' => '2025-12-20']);
        $this->kelas->update(['eduweb_id' => 77]);
        foreach ($this->siswa as $i => $s) {
            $s->update(['eduweb_id' => 9000 + $i]);
        }
    }

    // ── Ekskul dari EduWeb ──────────────────────────────────────────────────

    public function test_ekskul_diambil_dari_eduweb_bersama_absensi(): void
    {
        $this->hubungkanEduweb();
        Http::fake(['*' => Http::sequence()
            ->push(['data' => [['student_id' => 9000, 'sakit' => 1, 'izin' => 0, 'alpa' => 0]]])
            ->push(['data' => [
                ['student_id' => 9000, 'extracurricular' => 'Pramuka', 'score' => 88, 'predicate' => 'B'],
                ['student_id' => 9000, 'extracurricular' => 'PMR', 'score' => 95, 'predicate' => 'A'],
                ['student_id' => 5555, 'extracurricular' => 'Silat', 'score' => 70, 'predicate' => 'C'], // bukan siswa kelas
            ]])]);

        $this->actingAs($this->wali, 'web')->post($this->url('/absensi'))->assertSessionHas('berhasil', fn ($m) => str_contains($m, 'Ekskul 1 siswa'));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'extracurricular-grades') && $r['classroom_id'] == 77 && $r['year_name'] === '2025/2026' && $r['semester'] == 1);
        $r = RaporSiswa::where('siswa_id', $this->siswa[0]->id)->first();
        $this->assertSame([['nama' => 'Pramuka', 'nilai' => 88, 'predikat' => 'B'], ['nama' => 'PMR', 'nilai' => 95, 'predikat' => 'A']], $r->ekskul);
        $this->assertSame([], RaporSiswa::where('siswa_id', $this->siswa[1]->id)->first()->ekskul); // sudah dicek, tidak punya ekskul
    }

    public function test_kegagalan_ekskul_tidak_membatalkan_absensi(): void
    {
        $this->hubungkanEduweb();
        Http::fake(['*' => Http::sequence()
            ->push(['data' => [['student_id' => 9000, 'sakit' => 4, 'izin' => 0, 'alpa' => 0]]])
            ->push(['message' => 'Kunci ini tidak memiliki lingkup nilai.'], 403)]);

        $this->actingAs($this->wali, 'web')->post($this->url('/absensi'))->assertSessionHas('catatan', fn ($m) => str_contains($m, 'Absensi 1 siswa') && str_contains($m, 'Ekskul belum bisa diambil'));

        $this->assertSame(4, RaporSiswa::where('siswa_id', $this->siswa[0]->id)->value('sakit'));
    }

    // ── Cetak ───────────────────────────────────────────────────────────────

    public function test_cetak_menampilkan_isi_rapor_final_dengan_tanggal_pilihan(): void
    {
        $this->finalkan();
        $this->periode->update(['semester' => 'genap']);

        $html = $this->get($this->url('/cetak?periode='.$this->periode->id.'&tgl=2026-06-20'))->assertOk()->getContent();

        $this->assertStringContainsString('LAPORAN HASIL BELAJAR', $html);
        $this->assertStringContainsString($this->siswa[0]->nama, $html);
        $this->assertStringContainsString($this->siswa[1]->nama, $html);
        $this->assertStringContainsString('Matematika', $html);
        $this->assertStringContainsString('Kelompok Mata Pelajaran Umum', $html);
        $this->assertStringContainsString('Pramuka', $html);
        $this->assertStringContainsString('Baik', $html);              // keterangan ekskul predikat B
        $this->assertStringContainsString('Anak yang rajin', $html);
        $this->assertStringContainsString('Tanon, 20 Juni 2026', $html); // tanggal pilihan, bukan hari ini
        $this->assertStringContainsString('Bu Wali Kelas', $html);       // wali kelas kelas ini
        $this->assertStringContainsString('Pak Kepala', $html);
        $this->assertStringContainsString('NIP. 1980', $html);
        $this->assertStringContainsString('E. KENAIKAN KELAS', $html);
        $this->assertStringContainsString('<s>Tidak Naik</s> Kelas XI A', $html);
        $this->assertStringContainsString('<s>Naik</s> / <strong>Tidak Naik</strong> Kelas X A', $html);
        $this->assertSame(1, AuditLog::where('aksi', 'rapor.cetak')->count());
    }

    public function test_bagian_kenaikan_hanya_di_semester_genap(): void
    {
        $this->finalkan(); // semester ganjil
        $html = $this->get($this->url('/cetak?periode='.$this->periode->id.'&tgl=2026-01-05'))->assertOk()->getContent();

        $this->assertStringNotContainsString('KENAIKAN KELAS', $html);
        $this->assertStringContainsString('Gasal', $html);
    }

    public function test_cetak_satu_siswa_dan_unduh_word(): void
    {
        $this->finalkan();
        $url = $this->url('/cetak?periode='.$this->periode->id.'&tgl=2026-01-05&siswa='.$this->siswa[0]->id);

        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString($this->siswa[0]->nama, $html);
        $this->assertStringNotContainsString($this->siswa[1]->nama, $html);

        $word = $this->get($url.'&format=word')->assertOk();
        $this->assertStringContainsString('application/msword', $word->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="Rapor_x_a_', $word->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('window.print', $word->getContent());
    }

    public function test_cetak_memakai_data_beku_walau_pengaturan_berubah_sesudahnya(): void
    {
        $this->finalkan();
        $this->mtk->update(['nama' => 'Matematika Baru']);

        $html = $this->get($this->url('/cetak?periode='.$this->periode->id.'&tgl=2026-01-05'))->getContent();

        $this->assertStringContainsString('Matematika', $html);
        $this->assertStringNotContainsString('Matematika Baru', $html);
    }

    public function test_rapor_belum_final_tidak_bisa_dicetak_dan_hanya_wali_atau_admin(): void
    {
        $this->actingAs($this->wali, 'web')->get($this->url('/cetak?periode='.$this->periode->id))->assertSessionHas('gagal', fn ($m) => str_contains($m, 'Belum ada rapor final'));

        $this->finalkan();
        $lain = User::factory()->create();
        $this->actingAs($lain, 'web')->get($this->url('/cetak?periode='.$this->periode->id))->assertNotFound();
        $this->actingAs($this->admin, 'web')->get($this->url('/cetak?periode='.$this->periode->id))->assertOk();
        $this->actingAs($this->wali, 'web')->get($this->url('/cetak?periode='.$this->periode->id.'&tgl=bukan-tanggal'))->assertSessionHasErrors('tgl');
        $this->get($this->url('/cetak'))->assertSessionHasErrors('periode');
    }

    public function test_pratinjau_sebelum_final_menampilkan_semua_siswa_dan_nilai_kosong_menjadi_nol(): void
    {
        $kimia = \App\Models\Mapel::create(['kode' => 'KIM', 'nama' => 'Kimia', 'kelompok' => 'C1', 'kkm' => 75]);
        Pengampu::create(['guru_id' => User::factory()->create()->id, 'mapel_id' => $kimia->id, 'kelas_id' => $this->kelas->id]);
        NilaiRapor::create([ // Matematika sudah diisi untuk siswa pertama saja
            'siswa_id' => $this->siswa[0]->id, 'mapel_id' => $this->mtk->id, 'periode_id' => $this->periode->id, 'kelas_id' => $this->kelas->id,
            'ujian' => 80, 'harian' => 80, 'tugas_akhir' => 80, 'praktik' => 70, 'adab' => 90, 'np' => 80, 'nk' => 76, 'na' => 78, 'predikat' => 'B', 'status' => 'draf',
        ]);

        $html = $this->actingAs($this->wali, 'web')->get($this->url('/cetak?format=pratinjau&periode='.$this->periode->id))->assertOk()->getContent();

        $this->assertStringContainsString('PRATINJAU', $html);
        $this->assertStringContainsString($this->siswa[0]->nama, $html);
        $this->assertStringContainsString($this->siswa[1]->nama, $html); // belum punya rapor sama sekali: tetap tampil
        $this->assertStringContainsString('Matematika', $html);
        $this->assertStringContainsString('Kimia', $html);               // mapel diampu tanpa nilai tetap tampil
        $this->assertStringContainsString('<td class="cb" align="center" style="font-weight:bold">0</td>', $html); // nilai akhir kosong = 0
        $this->assertStringContainsString('<td class="cb" align="center" style="font-weight:bold">78</td>', $html);
        $this->assertSame(0, RaporSiswa::count());                       // pratinjau tidak menyimpan apa pun
        $this->assertSame(1, AuditLog::where('aksi', 'rapor.pratinjau')->count());

        // Cetak biasa tetap ditolak selama belum final.
        $this->get($this->url('/cetak?periode='.$this->periode->id))->assertSessionHas('gagal');
    }

    public function test_pratinjau_hanya_wali_atau_admin_dan_kartu_selalu_ada(): void
    {
        $this->actingAs($this->wali, 'web')->get($this->url())->assertOk()->assertSee('Pratinjau')->assertDontSee('Unduh Word');

        $this->actingAs(User::factory()->create(), 'web')->get($this->url('/cetak?format=pratinjau&periode='.$this->periode->id))->assertNotFound();
        $this->actingAs($this->admin, 'web')->get($this->url('/cetak?format=pratinjau&periode='.$this->periode->id.'&siswa='.$this->siswa[1]->id))->assertOk();
    }

    public function test_kartu_cetak_muncul_di_halaman_wali_hanya_setelah_ada_yang_final(): void
    {
        $this->actingAs($this->wali, 'web')->get($this->url())->assertDontSee('Unduh Word');

        $this->finalkan();
        $this->get($this->url())->assertSee('Unduh Word')->assertSee('Tanggal tanda tangan');
    }
}
