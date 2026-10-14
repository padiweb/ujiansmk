<?php

namespace Tests\Concerns;

use App\Models\BankSoal;
use App\Models\JadwalUjian;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Periode;
use App\Models\SesiUjian;
use App\Models\Siswa;
use App\Models\Soal;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\LayananJadwal;
use App\Services\LayananSesi;
use App\Services\LayananSoal;
use Illuminate\Http\Request;

/** Menyiapkan jadwal AKTIF lengkap dengan soal beku dan peserta untuk tes pelaksanaan ujian. */
trait MenyiapkanUjian
{
    protected User $admin;

    protected Mapel $mtk;

    protected Kelas $kelas;

    protected JadwalUjian $jadwal;

    /** @var array<string, Soal> */
    protected array $soal = [];

    /** @var list<Siswa> */
    protected array $siswa = [];

    /**
     * 3 soal PG (kunci A bobot 1, kunci B bobot 1, kunci C bobot 2) dan, bila diminta, 1 soal esai.
     *
     * @param  array<string,mixed>  $atribut  menimpa atribut jadwal
     */
    protected function siapkanUjian(array $atribut = [], bool $denganEsai = false, int $jumlahSiswa = 2): void
    {
        $this->admin = User::factory()->admin()->create();
        $this->mtk = Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika', 'kelompok' => 'A', 'kkm' => 75]);
        $tahun = TahunAjaran::create(['nama' => '2025/2026', 'aktif' => true]);
        $periode = Periode::create(['tahun_ajaran_id' => $tahun->id, 'semester' => 'ganjil', 'tipe' => 'reguler', 'aktif' => true]);
        $this->kelas = Kelas::create(['tahun_ajaran_id' => $tahun->id, 'tingkat' => 'X', 'nama' => 'X A']);
        $bank = BankSoal::create(['mapel_id' => $this->mtk->id, 'tingkat' => 'X', 'nama' => 'Bank', 'jumlah_opsi_bawaan' => 4, 'dibuat_oleh' => $this->admin->id]);

        $layananSoal = app(LayananSoal::class);
        foreach ([['s1', 'A', 1], ['s2', 'B', 1], ['s3', 'C', 2]] as $i => [$nama, $kunci, $bobot]) {
            $s = $bank->soal()->create(['tipe' => 'pg', 'pertanyaan' => "Soal PG {$nama}", 'bobot' => $bobot, 'status' => 'siap', 'sumber' => 'manual', 'urutan' => $i + 1]);
            $layananSoal->setOpsi($s, ['A' => "{$nama}-a", 'B' => "{$nama}-b", 'C' => "{$nama}-c", 'D' => "{$nama}-d"], $kunci);
            $this->soal[$nama] = $s;
        }
        if ($denganEsai) {
            $this->soal['esai'] = $bank->soal()->create(['tipe' => 'esai', 'pertanyaan' => 'Soal esai', 'bobot' => 1, 'rubrik_esai' => 'RAHASIA-RUBRIK', 'status' => 'siap', 'sumber' => 'manual', 'urutan' => 4]);
        }

        $this->jadwal = JadwalUjian::create(array_replace([
            'periode_id' => $periode->id, 'mapel_id' => $this->mtk->id, 'nama' => 'UTS Matematika', 'durasi_menit' => 60,
            'mulai_pada' => now()->subHour(), 'tutup_pada' => now()->addHours(3), 'acak_soal' => true, 'acak_opsi' => true,
            'batas_pelanggaran' => 3, 'bobot_pg' => $denganEsai ? 70 : 100, 'tampilkan_hasil' => 'langsung', 'dibuat_oleh' => $this->admin->id,
            'status' => 'draf',
        ], $atribut));
        $this->jadwal->kelas()->sync([$this->kelas->id]);
        $this->jadwal->bank()->create(['bank_soal_id' => $bank->id]);

        $this->siswa = Siswa::factory()->count($jumlahSiswa)->create()->each(fn (Siswa $s) => $s->kelas()->attach($this->kelas->id, ['status' => 'aktif']))->all();

        app(LayananJadwal::class)->aktifkan($this->jadwal);
        $this->jadwal->refresh();
    }

    protected function sesiDari(Siswa $siswa): SesiUjian
    {
        return SesiUjian::where('jadwal_id', $this->jadwal->id)->where('siswa_id', $siswa->id)->firstOrFail();
    }

    /** Mulai sesi lewat layanan; mengembalikan token perangkat polos (isi cookie / header). */
    protected function mulaiSesi(Siswa $siswa): string
    {
        return app(LayananSesi::class)->mulai($this->sesiDari($siswa), null, Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'PengujiPeramban/1.0']));
    }

    /** @return array<string,string> header autentikasi API ujian */
    protected function header(string $token): array
    {
        return ['X-Perangkat' => $token, 'Accept' => 'application/json'];
    }

    /** Jawaban benar untuk semua soal PG menurut kunci. */
    protected function jawabanBenar(): array
    {
        return [
            $this->soal['s1']->id => ['o' => 'A', 'w' => now()->getTimestampMs()],
            $this->soal['s2']->id => ['o' => 'B', 'w' => now()->getTimestampMs()],
            $this->soal['s3']->id => ['o' => 'C', 'w' => now()->getTimestampMs()],
        ];
    }
}
