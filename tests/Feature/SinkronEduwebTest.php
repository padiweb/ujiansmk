<?php

namespace Tests\Feature;

use App\Eduweb\KesalahanEduweb;
use App\Eduweb\PenyinkronEduweb;
use App\Models\Jurusan;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\PengaturanSekolah;
use App\Models\Periode;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SinkronEduwebTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://eduweb.contoh.sch.id/api/exam/v1';

    /** @var array<string,mixed> data EduWeb palsu per endpoint; bisa ditimpa per tes */
    private array $edu = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['eduweb.url' => self::URL, 'eduweb.kunci' => 'exu_kunci-uji-ABCDEF', 'eduweb.timeout' => 5]);

        $this->edu = [
            'academic-years' => [
                ['id' => 1, 'name' => '2025/2026', 'semester' => 1, 'is_active' => true],
                ['id' => 5, 'name' => '2025/2026', 'semester' => 2, 'is_active' => false],
            ],
            'majors' => [['id' => 1, 'name' => 'Teknik Komputer dan Jaringan', 'code' => 'TKJ'], ['id' => 2, 'name' => 'Rekayasa Perangkat Lunak', 'code' => 'RPL']],
            'classrooms' => [
                ['id' => 1, 'name' => 'X TKJ 1', 'grade' => 10, 'major_id' => 1, 'academic_year_id' => 1, 'homeroom_teacher_id' => 2],
                ['id' => 2, 'name' => 'XII TKJ', 'grade' => 12, 'major_id' => 1, 'academic_year_id' => 1, 'homeroom_teacher_id' => 4],
                ['id' => 9, 'name' => 'X TKJ 1', 'grade' => 10, 'major_id' => 1, 'academic_year_id' => 5, 'homeroom_teacher_id' => 2],
            ],
            'subjects' => [
                ['id' => 1, 'name' => 'Matematika', 'code' => 'MTK', 'major_id' => null, 'group' => 'Kelompok A (Umum)'],
                ['id' => 2, 'name' => 'Administrasi Sistem Jaringan', 'code' => 'ASJ', 'major_id' => 1, 'group' => 'Kelompok C3 (Kejuruan)'],
                ['id' => 3, 'name' => 'Bahasa Daerah', 'code' => null, 'major_id' => null, 'group' => 'Muatan Lokal'],
            ],
            'teachers' => [
                ['id' => 2, 'name' => 'Siti Aminah', 'nip' => '198001', 'niy' => null, 'role' => 'wali_kelas', 'is_active' => true, 'deleted' => false],
                ['id' => 4, 'name' => 'Budi Santoso', 'nip' => null, 'niy' => 'NIY-77', 'role' => 'guru', 'is_active' => true, 'deleted' => false],
                ['id' => 6, 'name' => 'Mantan Guru', 'nip' => '555', 'niy' => null, 'role' => 'guru', 'is_active' => false, 'deleted' => true],
            ],
            'students' => [
                ['id' => 6, 'name' => 'Ani Lestari', 'nis' => '111', 'nisn' => '0011', 'gender' => 'P', 'birth_place' => 'Tanon', 'birth_date' => '2009-05-01', 'status' => 'aktif', 'is_active' => true, 'deleted' => false, 'classroom_ids' => [1]],
                ['id' => 7, 'name' => 'Bayu Prakoso', 'nis' => '222', 'nisn' => null, 'gender' => null, 'status' => 'aktif', 'is_active' => true, 'deleted' => false, 'classroom_ids' => [1]],
                ['id' => 8, 'name' => 'Cici Tanpa Nomor', 'nis' => null, 'nisn' => null, 'gender' => null, 'status' => 'aktif', 'is_active' => true, 'deleted' => false, 'classroom_ids' => [1]],
                ['id' => 9, 'name' => 'Dodi Alumni', 'nis' => '333', 'nisn' => '0013', 'gender' => 'L', 'status' => 'alumni', 'is_active' => true, 'deleted' => false, 'classroom_ids' => [2]],
                ['id' => 10, 'name' => 'Eko Pindah', 'nis' => '444', 'nisn' => '0014', 'gender' => 'L', 'status' => 'pindah', 'is_active' => false, 'deleted' => false, 'classroom_ids' => []],
            ],
            'teaching-assignments' => [
                ['teacher_id' => 2, 'subject_id' => 1, 'classroom_id' => 1],
                ['teacher_id' => 4, 'subject_id' => 2, 'classroom_id' => 2],
                ['teacher_id' => 99, 'subject_id' => 1, 'classroom_id' => 1],
            ],
            'events' => [],
        ];
        $this->pasangEdu();
    }

    /** Pasang ulang Http::fake dari $this->edu (stub yang sudah ada tidak bisa ditimpa, jadi pakai klien baru). */
    private function pasangEdu(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(function (Request $r) {
            $bagian = parse_url($r->url());
            $jalur = basename($bagian['path']);
            parse_str($bagian['query'] ?? '', $q);

            if (isset($this->edu['_galat'][$jalur])) {
                return Http::response('galat', $this->edu['_galat'][$jalur]);
            }
            if ($jalur === 'events') {
                $sesudah = (int) ($q['after'] ?? 0);
                $baris = array_values(array_filter($this->edu['events'], fn ($e) => $e['id'] > $sesudah));
                $batas = (int) ($q['limit'] ?? 200);
                $potong = array_slice($baris, 0, $batas);

                return Http::response(['data' => $potong, 'last_id' => $potong ? end($potong)['id'] : $sesudah, 'has_more' => count($baris) > $batas]);
            }
            if ($jalur === 'credentials') {
                $baris = array_values(array_filter($this->edu['credentials'] ?? [], fn ($c) => (! isset($q['role']) || $c['role'] === $q['role'])
                    && (! isset($q['ids']) || in_array($c['id'], array_map('intval', explode(',', $q['ids'])), true))));

                return Http::response(['data' => $baris, 'current_page' => 1, 'last_page' => 1, 'total' => count($baris)]);
            }
            if (in_array($jalur, ['teachers', 'students'], true)) {
                $semua = $this->edu[$jalur];
                $per = (int) ($q['per_page'] ?? 200);
                $hal = (int) ($q['page'] ?? 1);

                return Http::response(['data' => array_slice($semua, ($hal - 1) * $per, $per), 'current_page' => $hal, 'last_page' => max(1, (int) ceil(count($semua) / $per)), 'total' => count($semua)]);
            }

            return Http::response(['data' => $this->edu[$jalur] ?? []]);
        });
    }

    private function sinkron(): \App\Eduweb\HasilSinkron
    {
        return app(PenyinkronEduweb::class)->penuh();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    // ── Sinkron penuh ───────────────────────────────────────────────────────

    public function test_sinkron_penuh_membuat_semua_data(): void
    {
        $h = $this->sinkron();

        $this->assertSame(1, TahunAjaran::count());
        $this->assertSame(1, (int) TahunAjaran::first()->eduweb_id);
        $this->assertTrue((bool) TahunAjaran::first()->aktif);
        $this->assertEqualsCanonicalizing(['ganjil', 'genap'], Periode::pluck('semester')->all());
        $this->assertTrue((bool) Periode::where('semester', 'ganjil')->value('aktif'));
        $this->assertFalse((bool) Periode::where('semester', 'genap')->value('aktif'));
        $this->assertSame(2, Jurusan::count());
        $this->assertSame(2, Kelas::count()); // kelas sama di semester 2 digabung lewat alias
        $this->assertSame(1, DB::table('eduweb_alias')->where('entitas', 'kelas')->where('eduweb_id', 9)->count());
        $this->assertSame(1, DB::table('eduweb_alias')->where('entitas', 'tahun_ajaran')->where('eduweb_id', 5)->count());
        $this->assertSame(['X', 'XII'], Kelas::orderBy('id')->pluck('tingkat')->all());

        $this->assertSame(['A', 'C3', 'ML'], Mapel::orderBy('id')->pluck('kelompok')->all());
        $this->assertSame('M3', Mapel::where('eduweb_id', 3)->value('kode'));
        $this->assertSame(75, (int) Mapel::first()->kkm);

        $this->assertSame(2, User::where('role', 'guru')->count()); // guru yang dihapus di EduWeb tidak dibuatkan akun
        $this->assertSame('198001', User::where('eduweb_id', 2)->value('username'));
        $this->assertSame('NIY-77', User::where('eduweb_id', 4)->value('username'));
        $this->assertSame(Kelas::where('eduweb_id', 1)->value('wali_kelas_id'), User::where('eduweb_id', 2)->value('id'));

        $this->assertSame(4, Siswa::count()); // Cici tanpa NISN/NIS dilewati
        $this->assertSame('222', Siswa::where('eduweb_id', 7)->value('nisn')); // NIS dipakai bila NISN kosong
        $this->assertSame('lulus', Siswa::where('eduweb_id', 9)->value('status'));
        $this->assertSame('keluar', Siswa::where('eduweb_id', 10)->value('status'));
        $this->assertSame('P', Siswa::where('eduweb_id', 6)->value('jenis_kelamin'));
        $this->assertSame(3, DB::table('keanggotaan_kelas')->count());
        $this->assertSame('lulus', DB::table('keanggotaan_kelas')->where('siswa_id', Siswa::where('eduweb_id', 9)->value('id'))->value('status'));

        $this->assertSame(2, DB::table('pengampu')->count());
        $this->assertSame(1, $h->lain['pengampu_dilewati']);
        $this->assertSame(1, $h->lain['siswa_login_pakai_nis']);
        $this->assertSame(['198001', 'NIY-77'], collect($h->akunBaru)->pluck('username')->sort()->values()->all());

        $p = PengaturanSekolah::ambil();
        $this->assertTrue($p->mode_sinkron_eduweb);
        $this->assertNotNull($p->eduweb_sinkron_penuh_pada);
    }

    public function test_akun_guru_baru_memakai_sandi_acak_yang_di_hash_dan_tidak_masuk_log(): void
    {
        $h = $this->sinkron();

        foreach ($h->akunBaru as $a) {
            $this->assertSame(12, strlen($a['sandi']));
            $u = User::where('username', $a['username'])->first();
            $this->assertNotSame($a['sandi'], $u->password);
            $this->assertTrue(Hash::check($a['sandi'], $u->password));
            $this->assertStringNotContainsString($a['sandi'], (string) DB::table('sinkron_log')->value('ringkasan'));
        }
        $this->assertSame('berhasil', DB::table('sinkron_log')->value('status'));
    }

    public function test_siswa_baru_tidak_bisa_login_sebelum_diberi_sandi(): void
    {
        $this->sinkron();

        $s = Siswa::where('eduweb_id', 6)->first();
        $this->assertNotEmpty($s->password);
        foreach (['', 'password', '0011', '111', 'kata-sandi-uji-123'] as $coba) {
            $this->assertFalse(Hash::check($coba, $s->password));
        }
        $this->post('/masuk', ['nisn' => '0011', 'password' => '0011'])->assertSessionHasErrors();
        $this->assertGuest('siswa');
    }

    public function test_sinkron_ulang_idempoten(): void
    {
        $this->sinkron();
        $hitung = fn () => [Kelas::count(), Siswa::count(), Mapel::count(), User::count(), DB::table('keanggotaan_kelas')->count(), DB::table('pengampu')->count(), TahunAjaran::count(), Periode::count()];
        $sebelum = $hitung();

        $h = $this->sinkron();

        $this->assertSame($sebelum, $hitung());
        $this->assertSame([], $h->akunBaru);
        foreach ($h->entitas as $e) {
            $this->assertSame(0, $e['baru']);
        }
    }

    public function test_perubahan_di_eduweb_ikut_diperbarui(): void
    {
        $this->sinkron();
        $this->edu['students'][0]['name'] = 'Ani Lestari Putri';
        $this->edu['students'][0]['classroom_ids'] = [2];
        $this->edu['subjects'][0]['name'] = 'Matematika Umum';
        $this->edu['teaching-assignments'] = [['teacher_id' => 4, 'subject_id' => 2, 'classroom_id' => 2]]; // satu penugasan dicabut
        $this->pasangEdu();

        $h = $this->sinkron();

        $this->assertSame('Ani Lestari Putri', Siswa::where('eduweb_id', 6)->value('nama'));
        $this->assertSame('Matematika Umum', Mapel::where('eduweb_id', 1)->value('nama'));
        $this->assertSame(1, DB::table('pengampu')->count());
        $this->assertSame(1, $h->lain['pengampu_dihapus']);
        $this->assertSame(2, DB::table('keanggotaan_kelas')->where('siswa_id', Siswa::where('eduweb_id', 6)->value('id'))->count()); // kelas lama tidak dihapus
    }

    // ── Pencocokan otomatis ─────────────────────────────────────────────────

    public function test_mencocokkan_data_lokal_yang_sudah_ada_tanpa_menggandakan(): void
    {
        $ta = TahunAjaran::create(['nama' => '2025/2026', 'aktif' => false]);
        $jur = Jurusan::create(['kode' => 'tkj', 'nama' => 'TKJ lama']);
        $kelas = Kelas::create(['tahun_ajaran_id' => $ta->id, 'tingkat' => 'X', 'nama' => 'X TKJ 1', 'jurusan_id' => null]);
        $mapel = Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika', 'kelompok' => 'A', 'kkm' => 80]);
        $siswa = new Siswa(['nisn' => '0011', 'nama' => 'Ani Lama', 'status' => 'aktif']);
        $siswa->password = 'rahasia-lama-123';
        $siswa->save();
        $guru = User::create(['nama' => 'Siti Aminah, S.Pd', 'username' => '198001', 'password' => 'sandi-lama-123456', 'role' => 'guru', 'aktif' => true]);
        $budi = User::create(['nama' => 'Budi Santoso', 'username' => 'budi.s', 'password' => 'sandi-lama-123456', 'role' => 'pengawas', 'aktif' => true]);
        $admin = User::create(['nama' => 'Admin Sekolah', 'username' => '555', 'password' => 'sandi-admin-12345', 'role' => 'admin', 'aktif' => true]);

        $h = $this->sinkron();

        $this->assertSame(1, TahunAjaran::count());
        $this->assertSame($ta->id, TahunAjaran::first()->id);
        $this->assertSame(2, Jurusan::count());
        $this->assertSame(1, (int) $jur->fresh()->eduweb_id);
        $this->assertSame($kelas->id, Kelas::where('eduweb_id', 1)->value('id'));
        $this->assertSame($jur->id, $kelas->fresh()->jurusan_id);
        $this->assertSame(1, (int) $mapel->fresh()->eduweb_id);
        $this->assertSame(80, (int) $mapel->fresh()->kkm); // KKM lokal tidak ditimpa
        $this->assertSame($siswa->id, Siswa::where('eduweb_id', 6)->value('id'));
        $this->assertTrue(Hash::check('rahasia-lama-123', $siswa->fresh()->password)); // sandi siswa lokal tidak diubah
        $this->assertSame(2, (int) $guru->fresh()->eduweb_id);
        $this->assertSame($budi->id, User::where('eduweb_id', 4)->value('id')); // cocok lewat nama tunggal
        $this->assertSame('pengawas', $budi->fresh()->role); // peran lokal dipertahankan
        $this->assertSame([], $h->akunBaru);
        $this->assertSame(3, User::count()); // tidak ada akun ganda

        // Akun admin tidak pernah disentuh walau username-nya sama dengan NIP guru EduWeb.
        $this->assertNull($admin->fresh()->eduweb_id);
        $this->assertSame('Admin Sekolah', $admin->fresh()->nama);
        $this->assertTrue(Hash::check('sandi-admin-12345', $admin->fresh()->password));
        $this->assertTrue((bool) $admin->fresh()->aktif);
    }

    public function test_bentrok_nama_pengguna_siswa_dilewati_dan_dilaporkan(): void
    {
        $lain = new Siswa(['nisn' => '0013', 'nama' => 'Siswa Lain', 'status' => 'aktif', 'eduweb_id' => 999]);
        $lain->password = 'rahasia-lama-123';
        $lain->save();

        $h = $this->sinkron();

        $this->assertSame('Siswa Lain', $lain->fresh()->nama);
        $this->assertSame(999, (int) $lain->fresh()->eduweb_id);
        $this->assertFalse(Siswa::where('eduweb_id', 9)->exists());
        $this->assertSame(2, $h->entitas['siswa']['dilewati']); // Dodi (bentrok) dan Cici (tanpa nomor)
        $this->assertNotEmpty(array_filter($h->peringatan, fn ($p) => str_contains($p, '0013')));
    }

    public function test_nama_ganda_tidak_dicocokkan_otomatis(): void
    {
        User::create(['nama' => 'Budi Santoso', 'username' => 'budi1', 'password' => 'sandi-lama-123456', 'role' => 'guru', 'aktif' => true]);
        User::create(['nama' => 'budi santoso', 'username' => 'budi2', 'password' => 'sandi-lama-123456', 'role' => 'guru', 'aktif' => true]);

        $h = $this->sinkron();

        $this->assertNull(User::where('username', 'budi1')->value('eduweb_id'));
        $this->assertNotNull(User::where('eduweb_id', 4)->value('id')); // dibuat baru
        $this->assertCount(2, $h->akunBaru); // Siti dan Budi dibuatkan akun baru
        $this->assertNotEmpty(array_filter($h->peringatan, fn ($p) => str_contains($p, 'bernama sama')));
    }

    public function test_data_lokal_yang_tidak_ada_di_eduweb_dilaporkan_bukan_dihapus(): void
    {
        $s = new Siswa(['nisn' => '9999', 'nama' => 'Siswa Hanya Lokal', 'status' => 'aktif']);
        $s->password = 'rahasia-lama-123';
        $s->save();

        $h = $this->sinkron();

        $this->assertTrue(Siswa::whereKey($s->id)->exists());
        $this->assertSame(1, $h->lain['siswa_belum_terhubung']);
    }

    // ── Kegagalan ───────────────────────────────────────────────────────────

    public function test_gagal_jaringan_tidak_mengubah_apa_pun(): void
    {
        $this->edu['_galat']['students'] = 500;
        $this->pasangEdu();

        try {
            $this->sinkron();
            $this->fail('seharusnya gagal');
        } catch (KesalahanEduweb $e) {
            $this->assertSame('server', $e->kode);
        }

        $this->assertSame(0, Kelas::count() + Siswa::count() + Mapel::count() + TahunAjaran::count());
        $this->assertFalse(PengaturanSekolah::ambil()->mode_sinkron_eduweb);
        $this->assertNull(PengaturanSekolah::ambil()->eduweb_sinkron_penuh_pada);
        $this->assertSame('gagal', DB::table('sinkron_log')->value('status'));
    }

    public function test_galat_penyimpanan_membatalkan_seluruh_sinkron(): void
    {
        // Galat sungguhan di tengah proses (tabel pengampu hilang): seluruh penulisan sebelumnya harus dibatalkan.
        Schema::dropIfExists('pengampu');

        try {
            $this->sinkron();
            $this->fail('seharusnya gagal');
        } catch (KesalahanEduweb $e) {
            $this->assertSame('tak_terduga', $e->kode);
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
        }

        $this->assertSame(0, Kelas::count() + Siswa::count() + Mapel::count() + TahunAjaran::count() + User::count());
    }

    public function test_sinkron_bersamaan_ditolak(): void
    {
        $kunci = Cache::lock('eduweb:sinkron', 60);
        $this->assertTrue($kunci->get());

        try {
            $this->sinkron();
            $this->fail('seharusnya ditolak');
        } catch (KesalahanEduweb $e) {
            $this->assertSame('sibuk', $e->kode);
        } finally {
            $kunci->release();
        }
        $this->assertSame(0, Siswa::count());
    }

    public function test_halaman_siswa_banyak_diambil_semua(): void
    {
        $this->edu['students'] = [];
        for ($i = 1; $i <= 1100; $i++) {
            $this->edu['students'][] = ['id' => 1000 + $i, 'name' => "Siswa {$i}", 'nis' => "N{$i}", 'nisn' => sprintf('%010d', $i), 'gender' => null, 'status' => 'aktif', 'is_active' => true, 'deleted' => false, 'classroom_ids' => [1]];
        }
        $this->pasangEdu();

        $this->sinkron();

        $this->assertSame(1100, Siswa::count());
        $this->assertSame(1100, DB::table('keanggotaan_kelas')->count());
    }

    // ── Kejadian ────────────────────────────────────────────────────────────

    private function kejadian(int $id, int $siswaEdu, string $aksi, array $sebelum, array $setelah, string $tipe = 'student.changed'): array
    {
        return ['id' => $id, 'type' => $tipe, 'student_id' => $siswaEdu, 'batch' => null, 'created_at' => now()->toIso8601String(),
            'payload' => ['action' => $aksi, 'academic_year_id' => 1, 'before' => $sebelum, 'after' => $setelah]];
    }

    public function test_kursor_kejadian_diambil_saat_sinkron_pertama_dan_tidak_digeser_sesudahnya(): void
    {
        $this->edu['events'] = [$this->kejadian(41, 6, 'naik', ['status' => 'aktif', 'classroom_ids' => [1]], ['status' => 'aktif', 'classroom_ids' => [2]]), $this->kejadian(42, 6, 'naik', [], [])];
        $this->pasangEdu();

        $this->sinkron();
        $this->assertSame(42, (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir); // kejadian lama sudah tercermin di data

        $this->edu['events'][] = $this->kejadian(43, 6, 'pindah', [], ['status' => 'pindah', 'classroom_ids' => []]);
        $this->pasangEdu();
        $this->sinkron();
        $this->assertSame(42, (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir);
    }

    public function test_kejadian_menerapkan_kenaikan_kelulusan_dan_idempoten(): void
    {
        $this->sinkron();
        $ani = Siswa::where('eduweb_id', 6)->value('id');
        $dodi = Siswa::where('eduweb_id', 9)->value('id');
        $kelasX = Kelas::where('eduweb_id', 1)->value('id');
        $kelasXII = Kelas::where('eduweb_id', 2)->value('id');
        $this->edu['events'] = [
            $this->kejadian(1, 6, 'naik', ['status' => 'aktif', 'classroom_ids' => [1]], ['status' => 'aktif', 'classroom_ids' => [2]]),
            $this->kejadian(2, 7, 'tidak_naik', ['status' => 'aktif', 'classroom_ids' => [1]], ['status' => 'aktif', 'classroom_ids' => [1]]),
            $this->kejadian(3, 9, 'lulus', ['status' => 'aktif', 'classroom_ids' => [2]], ['status' => 'alumni', 'classroom_ids' => [2]]),
            $this->kejadian(4, 99, 'naik', [], ['status' => 'aktif', 'classroom_ids' => [2]]),
            $this->kejadian(5, 6, 'lainnya', [], [], 'student.diubah_jenisnya'),
        ];
        $this->pasangEdu();

        $h = app(PenyinkronEduweb::class)->kejadian();

        $status = fn ($siswa, $kelas) => DB::table('keanggotaan_kelas')->where('siswa_id', $siswa)->where('kelas_id', $kelas)->value('status');
        $this->assertSame('naik', $status($ani, $kelasX));
        $this->assertSame('aktif', $status($ani, $kelasXII));
        $this->assertSame('lulus', $status($dodi, $kelasXII));
        $this->assertSame('lulus', Siswa::find($dodi)->status);
        $this->assertSame(3, $h->lain['kejadian_diterapkan']);
        $this->assertSame(1, $h->lain['kejadian_dilewati']); // siswa 99 belum ada
        $this->assertSame(1, $h->lain['kejadian_diabaikan']);
        $this->assertSame(5, (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir);

        // Dijalankan lagi: tidak ada kejadian baru, tidak ada perubahan.
        $h2 = app(PenyinkronEduweb::class)->kejadian();
        $this->assertArrayNotHasKey('kejadian_diterapkan', $h2->lain);

        // Diputar ulang dari awal (kursor dikembalikan): hasil tetap sama.
        PengaturanSekolah::ambil()->update(['eduweb_kejadian_terakhir' => 0]);
        app(PenyinkronEduweb::class)->kejadian();
        $this->assertSame('naik', $status($ani, $kelasX));
        $this->assertSame('aktif', $status($ani, $kelasXII));
        $this->assertSame(2, DB::table('keanggotaan_kelas')->where('siswa_id', $ani)->count());
    }

    public function test_kejadian_banyak_diambil_berhalaman(): void
    {
        $this->sinkron();
        $this->edu['events'] = [];
        for ($i = 1; $i <= 450; $i++) {
            $this->edu['events'][] = $this->kejadian($i, 6, 'ubah_status', [], ['status' => 'aktif', 'classroom_ids' => [1]]);
        }
        $this->pasangEdu();

        $h = app(PenyinkronEduweb::class)->kejadian();

        $this->assertSame(450, $h->lain['kejadian_diterapkan']);
        $this->assertSame(450, (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir);
    }

    public function test_kejadian_butuh_sinkron_penuh_dulu(): void
    {
        try {
            app(PenyinkronEduweb::class)->kejadian();
            $this->fail('seharusnya ditolak');
        } catch (KesalahanEduweb $e) {
            $this->assertSame('belum_penuh', $e->kode);
        }
    }

    // ── Halaman admin dan perintah ──────────────────────────────────────────

    public function test_halaman_hanya_untuk_admin(): void
    {
        $guru = User::factory()->create();

        $this->actingAs($this->admin(), 'web')->get(route('staf.sinkron.index'))->assertOk()->assertSee('Sinkron penuh');
        $this->actingAs($guru, 'web')->get(route('staf.sinkron.index'))->assertForbidden();
        $this->actingAs($guru, 'web')->post(route('staf.sinkron.penuh'))->assertForbidden();
        $this->assertSame(0, Siswa::count());
    }

    public function test_sinkron_lewat_halaman_dan_unduh_sandi_hanya_sekali(): void
    {
        $this->actingAs($this->admin(), 'web');

        $r = $this->post(route('staf.sinkron.penuh'))->assertRedirect()->assertSessionHas('berhasil')->assertSessionHas('sandi_token');
        $token = session('sandi_token');
        $this->assertSame(4, Siswa::count());

        $unduh = $this->get(route('staf.sinkron.sandi', $token))->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'ak').'.xlsx';
        file_put_contents($tmp, $unduh->streamedContent());
        $lembar = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);
        $this->assertSame('Kata sandi sementara', $lembar->getCell('C1')->getValue());
        $this->assertSame(12, strlen((string) $lembar->getCell('C2')->getValue()));

        $this->get(route('staf.sinkron.sandi', $token))->assertNotFound(); // hanya sekali
        $this->get(route('staf.sinkron.sandi', 'x'))->assertNotFound();

        $this->get(route('staf.sinkron.index'))->assertOk()->assertSee('Berhasil');
    }

    public function test_sandi_hanya_bisa_diunduh_admin_yang_menjalankan(): void
    {
        $this->actingAs($this->admin(), 'web')->post(route('staf.sinkron.penuh'))->assertSessionHas('sandi_token');
        $token = session('sandi_token');

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($this->admin(), 'web')->get(route('staf.sinkron.sandi', $token))->assertNotFound();
    }

    public function test_uji_koneksi_dan_galat_ditampilkan(): void
    {
        $this->actingAs($this->admin(), 'web');
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['ok' => true, 'school' => ['name' => 'SMK Uji'], 'api_version' => 1])]);
        $this->post(route('staf.sinkron.uji'))->assertSessionHas('berhasil');

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response(['message' => 'Unauthorized.'], 401)]);
        $this->post(route('staf.sinkron.uji'))->assertSessionHas('gagal');
        $this->post(route('staf.sinkron.penuh'))->assertSessionHas('gagal');
        $this->assertSame(0, Siswa::count());
    }

    // ── Kredensial: login sama dengan EduWeb ────────────────────────────────

    private function hashEdu(string $sandi): string
    {
        return Hash::make($sandi, ['rounds' => 4]);
    }

    private function pasangKredensial(): void
    {
        $this->edu['teachers'][0]['username'] = 'siti.aminah';
        $this->edu['students'][0]['username'] = 'ani.l';
        $this->edu['credentials'] = [
            ['id' => 6, 'role' => 'student', 'username' => 'ani.l', 'password_hash' => $this->hashEdu('SandiEduAni1')],
            ['id' => 7, 'role' => 'student', 'username' => null, 'password_hash' => $this->hashEdu('SandiEduBayu1')],
            ['id' => 2, 'role' => 'teacher', 'username' => 'siti.aminah', 'password_hash' => $this->hashEdu('SandiEduSiti1')],
        ];
        $this->pasangEdu();
    }

    public function test_login_siswa_dan_guru_memakai_username_dan_sandi_eduweb(): void
    {
        $this->pasangKredensial();
        $h = $this->sinkron();

        $this->assertSame('siti.aminah', User::where('eduweb_id', 2)->value('username'));
        $this->assertSame('ani.l', Siswa::where('eduweb_id', 6)->value('username'));
        $this->assertSame('aktif', PengaturanSekolah::ambil()->eduweb_kredensial);
        $this->assertNotContains('siti.aminah', array_column($h->akunBaru, 'username')); // punya hash EduWeb: tidak perlu daftar sandi
        $this->assertSame(['NIY-77'], array_column($h->akunBaru, 'username')); // guru tanpa hash tetap sandi acak

        $this->post('/masuk', ['username' => 'ani.l', 'password' => 'SandiEduAni1'])->assertRedirect(route('siswa.beranda'));
        $this->assertAuthenticatedAs(Siswa::where('eduweb_id', 6)->first(), 'siswa');
        auth('siswa')->logout();

        // NISN/NIS juga diterima (seperti EduWeb), dengan sandi EduWeb yang sama.
        $this->post('/masuk', ['username' => '222', 'password' => 'SandiEduBayu1'])->assertRedirect(route('siswa.beranda'));
        auth('siswa')->logout();

        $this->post('/staf/masuk', ['username' => 'siti.aminah', 'password' => 'SandiEduSiti1'])->assertRedirect(route('staf.beranda'));
        $this->assertAuthenticatedAs(User::where('eduweb_id', 2)->first(), 'web');
    }

    public function test_hash_tidak_masuk_log_ringkasan_maupun_tampilan(): void
    {
        $this->pasangKredensial();
        $this->actingAs($this->admin(), 'web');
        $respons = $this->post(route('staf.sinkron.penuh'))->assertSessionHas('berhasil');

        $log = (string) DB::table('sinkron_log')->value('ringkasan').(string) DB::table('audit_log')->pluck('detail')->join(' ');
        foreach ($this->edu['credentials'] as $c) {
            $this->assertStringNotContainsString($c['password_hash'], $log);
        }
        $this->get(route('staf.sinkron.index'))->assertDontSee('$2y$', false);
        $this->assertStringNotContainsString('$2y$', json_encode(Siswa::first()->toArray()));
    }

    public function test_hash_bukan_bcrypt_ditolak_dan_akun_tetap_aman(): void
    {
        $this->edu['credentials'] = [
            ['id' => 6, 'role' => 'student', 'username' => null, 'password_hash' => 'sandi-polos'],
            ['id' => 7, 'role' => 'student', 'username' => null, 'password_hash' => '$2y$31$'.str_repeat('a', 53)], // biaya berlebihan
        ];
        $this->pasangEdu();
        $h = $this->sinkron();

        $this->assertSame(0, $h->lain['sandi_diperbarui'] ?? 0);
        $this->post('/masuk', ['username' => '0011', 'password' => 'sandi-polos'])->assertSessionHasErrors();
        $this->assertGuest('siswa');
        $this->assertNull(Siswa::where('eduweb_id', 6)->value('eduweb_hash_sandi'));
    }

    public function test_sandi_sementara_tidak_tertimpa_kecuali_sandi_eduweb_berubah(): void
    {
        $this->pasangKredensial();
        $this->sinkron();

        $ani = Siswa::where('eduweb_id', 6)->first();
        $ani->forceFill(['password' => Hash::make('Sementara-Kartu-9', ['rounds' => 4])])->saveQuietly(); // dari Kartu Ujian
        $this->sinkron();
        $this->assertTrue(Hash::check('Sementara-Kartu-9', $ani->fresh()->password), 'sinkron ulang tanpa perubahan di EduWeb tidak boleh menimpa sandi lokal');

        $this->edu['credentials'][0]['password_hash'] = $this->hashEdu('SandiBaruAni2');
        $this->pasangEdu();
        $h = $this->sinkron();
        $this->assertSame(1, $h->lain['sandi_diperbarui']);
        $this->assertTrue(Hash::check('SandiBaruAni2', $ani->fresh()->password));
        $this->assertFalse(Hash::check('SandiEduAni1', $ani->fresh()->password));
    }

    public function test_akun_admin_tidak_pernah_menerima_kredensial_eduweb(): void
    {
        $this->pasangKredensial();
        $admin = $this->admin();
        $admin->forceFill(['eduweb_id' => 2, 'username' => 'siti.aminah'])->saveQuietly();
        $sebelum = $admin->fresh()->password;

        $this->sinkron();

        $this->assertSame($sebelum, $admin->fresh()->password);
        $this->assertNull($admin->fresh()->eduweb_hash_sandi);
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_kredensial_belum_dinyalakan_di_eduweb_hanya_peringatan(): void
    {
        $this->edu['_galat']['credentials'] = 403;
        $this->pasangEdu();
        $h = $this->sinkron();

        $this->assertSame(4, Siswa::count());
        $this->assertSame('belum_aktif', PengaturanSekolah::ambil()->eduweb_kredensial);
        $this->assertStringContainsString('akses kredensial belum dinyalakan', implode(' ', $h->peringatan));
        $this->assertNotEmpty($h->akunBaru, 'tanpa hash EduWeb, guru baru tetap mendapat sandi acak sekali-unduh');
    }

    public function test_kredensial_galat_server_menggagalkan_sinkron_tanpa_mengubah_data(): void
    {
        $this->edu['_galat']['credentials'] = 500;
        $this->pasangEdu();

        $this->expectException(KesalahanEduweb::class);
        try {
            $this->sinkron();
        } finally {
            $this->assertSame(0, Siswa::count());
        }
    }

    public function test_nama_pengguna_eduweb_yang_bentrok_tidak_dipaksakan(): void
    {
        $lain = Siswa::factory()->create(['nisn' => '7777', 'username' => 'ani.l']);
        $this->pasangKredensial();
        $h = $this->sinkron();

        $this->assertSame('ani.l', $lain->fresh()->username);
        $this->assertNull(Siswa::where('eduweb_id', 6)->value('username'));
        $this->assertStringContainsString('sudah dipakai siswa lain', implode(' ', $h->peringatan));
        $this->post('/masuk', ['username' => '0011', 'password' => 'SandiEduAni1'])->assertRedirect(route('siswa.beranda'));
    }

    public function test_username_guru_eduweb_menggantikan_nip_pada_akun_terhubung(): void
    {
        $this->sinkron();
        $this->assertSame('198001', User::where('eduweb_id', 2)->value('username'));

        $this->edu['teachers'][0]['username'] = 'siti.aminah';
        $this->pasangEdu();
        $this->sinkron();
        $this->assertSame('siti.aminah', User::where('eduweb_id', 2)->value('username'));
    }

    // ── Kejadian perubahan data master dan sandi ────────────────────────────

    private function kejadianBaru(int $id, string $tipe, array $payload): array
    {
        return ['id' => $id, 'type' => $tipe, 'student_id' => $payload['id'] ?? null, 'batch' => null, 'created_at' => now()->toIso8601String(), 'payload' => $payload];
    }

    public function test_kejadian_data_master_menyegarkan_data_sekali_dan_kursor_maju(): void
    {
        $this->pasangKredensial();
        $this->sinkron();
        $kursor = (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir;

        $this->edu['students'][] = ['id' => 11, 'name' => 'Fajar Baru', 'nis' => '555', 'nisn' => '0015', 'username' => 'fajar', 'gender' => 'L', 'status' => 'aktif', 'is_active' => true, 'deleted' => false, 'classroom_ids' => [1]];
        $this->edu['students'][0]['name'] = 'Ani Lestari Putri';
        $this->edu['classrooms'][0]['name'] = 'X TKJ 1A'; $this->edu['classrooms'][2]['name'] = 'X TKJ 1A'; // baris semester 2 dari kelas yang sama
        $this->edu['events'] = [
            $this->kejadianBaru($kursor + 1, 'master.changed', ['entity' => 'student', 'id' => 11, 'action' => 'created']),
            $this->kejadianBaru($kursor + 2, 'master.changed', ['entity' => 'student', 'id' => 6, 'action' => 'updated']),
            $this->kejadianBaru($kursor + 3, 'master.changed', ['entity' => 'classroom', 'id' => 1, 'action' => 'updated']),
        ];
        $this->pasangEdu();

        $h = app(PenyinkronEduweb::class)->kejadian();

        $this->assertSame(3, $h->lain['kejadian_data_master']);
        $this->assertSame(1, $h->lain['penyegaran_data_master']);
        $this->assertSame('Fajar Baru', Siswa::where('eduweb_id', 11)->value('nama'));
        $this->assertSame('Ani Lestari Putri', Siswa::where('eduweb_id', 6)->value('nama'));
        $this->assertSame('X TKJ 1A', Kelas::where('eduweb_id', 1)->value('nama'));
        $this->assertSame($kursor + 3, (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir);
    }

    public function test_penyegaran_gagal_tidak_memajukan_kursor_dan_diulang_berikutnya(): void
    {
        $this->sinkron();
        $kursor = (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir;
        $this->edu['students'][0]['name'] = 'Nama Baru Ani';
        $this->edu['events'] = [$this->kejadianBaru($kursor + 1, 'master.changed', ['entity' => 'student', 'id' => 6, 'action' => 'updated'])];
        $this->edu['_galat']['subjects'] = 500;
        $this->pasangEdu();

        try {
            app(PenyinkronEduweb::class)->kejadian();
            $this->fail('seharusnya gagal');
        } catch (KesalahanEduweb) {
        }
        $this->assertSame($kursor, (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir);
        $this->assertNotSame('Nama Baru Ani', Siswa::where('eduweb_id', 6)->value('nama'));

        unset($this->edu['_galat']);
        $this->pasangEdu();
        app(PenyinkronEduweb::class)->kejadian();
        $this->assertSame($kursor + 1, (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir);
        $this->assertSame('Nama Baru Ani', Siswa::where('eduweb_id', 6)->value('nama'));
    }

    public function test_kejadian_sandi_hanya_mengambil_kredensial_pengguna_terkait(): void
    {
        $this->pasangKredensial();
        $this->sinkron();
        $kursor = (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir;

        $this->edu['credentials'][0]['password_hash'] = $this->hashEdu('SandiBaruAni3');
        $this->edu['events'] = [$this->kejadianBaru($kursor + 1, 'credential.changed', ['entity' => 'student', 'id' => 6])];
        $this->pasangEdu();

        $h = app(PenyinkronEduweb::class)->kejadian();

        $this->assertSame(1, $h->lain['sandi_diperbarui']);
        $this->assertArrayNotHasKey('penyegaran_data_master', $h->lain);
        $this->post('/masuk', ['username' => 'ani.l', 'password' => 'SandiBaruAni3'])->assertRedirect(route('siswa.beranda'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'credentials') && str_contains(urldecode($r->url()), 'ids=6'));
        $this->assertSame($kursor + 1, (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir);
    }

    public function test_kejadian_tak_dikenal_atau_rusak_diabaikan(): void
    {
        $this->sinkron();
        $kursor = (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir;
        $this->edu['events'] = [
            $this->kejadianBaru($kursor + 1, 'credential.changed', ['entity' => 'admin', 'id' => 1]),
            $this->kejadianBaru($kursor + 2, 'jenis.baru.entah', ['id' => 1]),
        ];
        $this->pasangEdu();

        $h = app(PenyinkronEduweb::class)->kejadian();

        $this->assertSame(2, $h->lain['kejadian_diabaikan']);
        $this->assertSame($kursor + 2, (int) PengaturanSekolah::ambil()->eduweb_kejadian_terakhir);
    }

    public function test_perintah_sinkron(): void
    {
        $this->artisan('eduweb:sinkron')->expectsOutputToContain('Sinkron selesai')->expectsOutputToContain('198001')->assertSuccessful();
        $this->assertSame(4, Siswa::count());

        $this->artisan('eduweb:sinkron --kejadian')->expectsOutputToContain('Sinkron selesai')->assertSuccessful();

        config(['eduweb.kunci' => null]);
        $this->artisan('eduweb:sinkron')->assertFailed();
    }
}
