<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\JadwalSoal;
use App\Models\JadwalUjian;
use App\Models\Kelas;
use App\Models\Pengampu;
use App\Models\Siswa;
use App\Models\User;
use App\Services\KesalahanJadwal;
use App\Services\LayananBankPerKelas;
use App\Services\LayananJadwal;
use App\Services\LayananMonitor;
use App\Services\LayananSesi;
use App\Services\LayananSoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\MenyiapkanUjian;
use Tests\TestCase;

/** Dua guru mengampu satu mapel pada satu angkatan: tiap guru memakai soalnya sendiri untuk kelas yang diajarnya. */
class BankPerKelasTest extends TestCase
{
    use MenyiapkanUjian, RefreshDatabase;

    private Kelas $kelasB;

    private User $guruA;

    private User $guruB;

    private BankSoal $bankA;

    private BankSoal $bankB;

    private Siswa $siswaA;

    private Siswa $siswaB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanUjian(jumlahSiswa: 1);
        $this->kelasB = Kelas::create(['tahun_ajaran_id' => $this->kelas->tahun_ajaran_id, 'tingkat' => 'X', 'nama' => 'X B']);
        $this->guruA = User::factory()->create();
        $this->guruB = User::factory()->create();
        Pengampu::create(['guru_id' => $this->guruA->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->kelas->id]);
        Pengampu::create(['guru_id' => $this->guruB->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $this->kelasB->id]);
        $this->bankA = $this->bank($this->guruA, 'A');
        $this->bankB = $this->bank($this->guruB, 'B');
        $this->siswaA = $this->siswa[0];
        $this->siswaB = Siswa::factory()->create();
        $this->siswaB->kelas()->attach($this->kelasB->id, ['status' => 'aktif']);
    }

    private function bank(User $pemilik, string $kode): BankSoal
    {
        $bank = BankSoal::create(['mapel_id' => $this->mtk->id, 'tingkat' => 'X', 'nama' => "Bank {$kode}", 'jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $pemilik->id]);
        foreach ([1, 2] as $i) {
            $s = $bank->soal()->create(['tipe' => 'pg', 'pertanyaan' => "Soal {$kode}{$i}", 'bobot' => 1, 'status' => 'siap', 'sumber' => 'manual', 'urutan' => $i]);
            app(LayananSoal::class)->setOpsi($s, ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'], 'A');
        }

        return $bank;
    }

    private function jadwalBaru(array $bank, ?array $kelas = null): JadwalUjian
    {
        $kelas ??= [$this->kelas->id, $this->kelasB->id];
        $j = JadwalUjian::create([
            'periode_id' => $this->jadwal->periode_id, 'mapel_id' => $this->mtk->id, 'nama' => 'PAI Gabungan', 'durasi_menit' => 60,
            'mulai_pada' => now()->subHour(), 'tutup_pada' => now()->addHours(3), 'batas_pelanggaran' => 3, 'bobot_pg' => 100, 'tampilkan_hasil' => 'langsung',
            'dibuat_oleh' => $this->admin->id, 'status' => 'draf',
        ]);
        $j->kelas()->sync($kelas);
        $peta = app(LayananBankPerKelas::class)->petakan($this->mtk->id, $kelas, array_map(fn ($b) => $b->id, $bank));
        foreach ($bank as $b) {
            $j->bank()->create(['bank_soal_id' => $b->id, 'kelas_ids' => $peta[$b->id]]);
        }

        return $j;
    }

    // ── Pemetaan ────────────────────────────────────────────────────────────

    public function test_bank_dua_guru_pengampu_dipetakan_ke_kelas_masing_masing(): void
    {
        $peta = app(LayananBankPerKelas::class)->petakan($this->mtk->id, [$this->kelas->id, $this->kelasB->id], [$this->bankA->id, $this->bankB->id]);
        $this->assertSame([$this->kelas->id], $peta[$this->bankA->id]);
        $this->assertSame([$this->kelasB->id], $peta[$this->bankB->id]);
    }

    public function test_satu_bank_atau_bank_admin_tetap_untuk_semua_kelas_dan_bank_bersama_ikut_semua(): void
    {
        $layanan = app(LayananBankPerKelas::class);
        $kelas = [$this->kelas->id, $this->kelasB->id];

        // Hanya bank satu guru: perilaku lama (semua kelas).
        $this->assertSame([$this->bankA->id => null], $layanan->petakan($this->mtk->id, $kelas, [$this->bankA->id]));

        // Bank admin (bukan pengampu) + dua bank guru: bank admin untuk semua kelas, bank guru khusus.
        $umum = BankSoal::create(['mapel_id' => $this->mtk->id, 'tingkat' => 'X', 'nama' => 'Bank Umum', 'jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $this->admin->id]);
        $peta = $layanan->petakan($this->mtk->id, $kelas, [$umum->id, $this->bankA->id, $this->bankB->id]);
        $this->assertNull($peta[$umum->id]);
        $this->assertSame([$this->kelas->id], $peta[$this->bankA->id]);
    }

    public function test_kelas_tanpa_bank_dari_gurunya_ditolak_dengan_pesan_jelas(): void
    {
        $kelasC = Kelas::create(['tahun_ajaran_id' => $this->kelas->tahun_ajaran_id, 'tingkat' => 'X', 'nama' => 'X C']);
        $guruC = User::factory()->create(['nama' => 'Bu Cahya']);
        Pengampu::create(['guru_id' => $guruC->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $kelasC->id]);

        try {
            app(LayananBankPerKelas::class)->petakan($this->mtk->id, [$this->kelas->id, $this->kelasB->id, $kelasC->id], [$this->bankA->id, $this->bankB->id]);
            $this->fail('Seharusnya ditolak.');
        } catch (KesalahanJadwal $e) {
            $this->assertStringContainsString('Kelas X C belum kebagian bank soal', $e->getMessage());
            $this->assertStringContainsString('Bu Cahya', $e->getMessage());
        }
    }

    // ── Pelaksanaan ─────────────────────────────────────────────────────────

    public function test_setiap_siswa_hanya_mendapat_soal_guru_kelasnya_dan_dinilai_dari_soal_itu(): void
    {
        $j = $this->jadwalBaru([$this->bankA, $this->bankB]);
        app(LayananJadwal::class)->aktifkan($j);

        // Soal dibekukan dengan penanda kelas pemakai.
        $beku = JadwalSoal::where('jadwal_id', $j->id)->with('soal.bank')->get();
        $this->assertCount(4, $beku);
        $this->assertSame([$this->kelas->id], $beku->firstWhere('soal.bank.nama', 'Bank A')->untuk_kelas);

        $layananSesi = app(LayananSesi::class);
        $ua = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Uji']);
        $tokenA = $layananSesi->mulai($j->sesi()->where('siswa_id', $this->siswaA->id)->first(), null, $ua);
        $tokenB = $layananSesi->mulai($j->sesi()->where('siswa_id', $this->siswaB->id)->first(), null, $ua);
        $sesiA = $j->sesi()->where('siswa_id', $this->siswaA->id)->first();
        $sesiB = $j->sesi()->where('siswa_id', $this->siswaB->id)->first();

        $namaSoal = fn ($sesi) => \App\Models\Soal::whereIn('id', $sesi->susunan['soal'])->orderBy('pertanyaan')->pluck('pertanyaan')->all();
        $this->assertSame(['Soal A1', 'Soal A2'], $namaSoal($sesiA));
        $this->assertSame(['Soal B1', 'Soal B2'], $namaSoal($sesiB));

        // API paket memuat soal kelasnya saja.
        $r = $this->getJson("/api/ujian/{$sesiB->id}/paket", $this->header($tokenB))->assertOk();
        $this->assertSame(['Soal B1', 'Soal B2'], collect($r->json('soal'))->pluck('pertanyaan')->sort()->values()->all());

        // Siswa A menjawab benar semua soalnya (kunci A): nilai 100, bukan 50 karena soal kelas lain tidak dihitung.
        $jawaban = collect($sesiA->susunan['soal'])->mapWithKeys(fn ($id) => [$id => ['o' => 'A', 'w' => now()->getTimestampMs()]])->all();
        $this->postJson("/api/ujian/{$sesiA->id}/kirim", ['jawaban' => $jawaban], $this->header($tokenA))->assertOk()->assertJsonPath('hasil.skor_pg', 100)->assertJsonPath('hasil.benar', 2)->assertJsonPath('hasil.kosong', 0);

        // Siswa B tidak menjawab apa pun: 0 benar, 2 kosong (bukan 4).
        $this->postJson("/api/ujian/{$sesiB->id}/kirim", ['jawaban' => []], $this->header($tokenB))->assertOk()->assertJsonPath('hasil.benar', 0)->assertJsonPath('hasil.kosong', 2);
    }

    public function test_monitor_menampilkan_total_soal_per_peserta_bila_soal_dibedakan_per_kelas(): void
    {
        $guruC = User::factory()->create();
        $kelasC = Kelas::create(['tahun_ajaran_id' => $this->kelas->tahun_ajaran_id, 'tingkat' => 'X', 'nama' => 'X C']);
        $umum = BankSoal::create(['mapel_id' => $this->mtk->id, 'tingkat' => 'X', 'nama' => 'Bank Umum', 'jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $this->admin->id]);
        $s = $umum->soal()->create(['tipe' => 'pg', 'pertanyaan' => 'Soal umum', 'bobot' => 1, 'status' => 'siap', 'sumber' => 'manual', 'urutan' => 1]);
        app(LayananSoal::class)->setOpsi($s, ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'], 'A');

        $j = $this->jadwalBaru([$umum, $this->bankA, $this->bankB]);
        app(LayananJadwal::class)->aktifkan($j);
        $layananSesi = app(LayananSesi::class);
        $layananSesi->mulai($j->sesi()->where('siswa_id', $this->siswaA->id)->first(), null, Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Uji']));

        $data = app(LayananMonitor::class)->data($j);
        $baris = collect($data['peserta'])->keyBy('nisn');
        $this->assertSame(3, $baris[$this->siswaA->nisn]['total_soal']); // 2 soal Bank A + 1 soal umum
        $this->assertSame(5, $data['total_soal']);                      // total jadwal: 5 soal (A, B, umum)
    }

    public function test_form_jadwal_menyimpan_pemetaan_dan_menolak_kelas_tanpa_bank(): void
    {
        $this->actingAs($this->admin, 'web');
        $kegiatan = \App\Models\KegiatanUjian::create(['periode_id' => $this->jadwal->periode_id, 'nama' => 'PTS', 'aktif' => true]);
        $data = [
            'kegiatan_id' => $kegiatan->id, 'mapel_id' => $this->mtk->id, 'durasi_menit' => 60,
            'mulai_pada' => now()->addDay()->format('Y-m-d\TH:i'), 'tutup_pada' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'kelas_ids' => [$this->kelas->id, $this->kelasB->id], 'bank_ids' => [$this->bankA->id, $this->bankB->id],
            'batas_pelanggaran' => 3, 'bobot_pg' => 100, 'tampilkan_hasil' => 'langsung',
        ];
        $this->post('/staf/jadwal-ujian', $data)->assertSessionHasNoErrors();

        $j = JadwalUjian::where('nama', 'PTS – Matematika')->first();
        $peta = $j->bank()->get()->mapWithKeys(fn ($jb) => [$jb->bank_soal_id => $jb->kelas_ids]);
        $this->assertSame([$this->kelas->id], $peta[$this->bankA->id]);
        $this->assertSame([$this->kelasB->id], $peta[$this->bankB->id]);
        $this->get("/staf/jadwal-ujian/{$j->id}")->assertOk()->assertSee('hanya X A')->assertSee('hanya X B');

        // Kelas ketiga yang gurunya belum punya bank dipilih: ditolak.
        $kelasC = Kelas::create(['tahun_ajaran_id' => $this->kelas->tahun_ajaran_id, 'tingkat' => 'X', 'nama' => 'X C']);
        Pengampu::create(['guru_id' => User::factory()->create()->id, 'mapel_id' => $this->mtk->id, 'kelas_id' => $kelasC->id]);
        $this->post('/staf/jadwal-ujian', ['kelas_ids' => [$this->kelas->id, $this->kelasB->id, $kelasC->id]] + $data)->assertSessionHasErrors('bank_ids');
    }

    public function test_koreksi_esai_hanya_menampilkan_soal_esai_kelas_yang_dipilih(): void
    {
        foreach ([[$this->bankA, 'A'], [$this->bankB, 'B']] as [$bank, $kode]) {
            $bank->soal()->create(['tipe' => 'esai', 'pertanyaan' => "Esai {$kode}", 'bobot' => 1, 'status' => 'siap', 'sumber' => 'manual', 'urutan' => 9]);
        }
        $j = $this->jadwalBaru([$this->bankA, $this->bankB]);
        $j->update(['bobot_pg' => 70]);
        app(LayananJadwal::class)->aktifkan($j);

        $koreksi = app(\App\Services\LayananKoreksi::class);
        $this->assertSame(['Esai A', 'Esai B'], collect($koreksi->soalEsai($j))->map(fn ($x) => $x['soal']->pertanyaan)->sort()->values()->all());
        $this->assertSame(['Esai A'], collect($koreksi->soalEsai($j, [$this->kelas->id]))->map(fn ($x) => $x['soal']->pertanyaan)->all());
        $this->assertSame(['Esai B'], collect($koreksi->soalEsai($j, [$this->kelasB->id]))->map(fn ($x) => $x['soal']->pertanyaan)->all());
    }
}
