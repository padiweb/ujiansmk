<?php

namespace App\Eduweb;

use App\Models\AuditLog;
use App\Models\Jurusan;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\PengaturanSekolah;
use App\Models\Periode;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Support\KataSandi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Menarik data master dari EduWeb (hanya baca) ke data lokal. Idempoten: boleh dijalankan berulang.
 * Pencocokan otomatis dengan data lokal yang sudah ada (impor Excel); konflik tidak diubah dan dilaporkan.
 */
class PenyinkronEduweb
{
    private const HALAMAN = 500;

    private const MAKS_HALAMAN_KEJADIAN = 50;

    /** @var array<string,int> cache pemetaan eduweb_id → id lokal per entitas */
    private array $peta = [];

    /** @var array{student:array<int,array<string,mixed>>,teacher:array<int,array<string,mixed>>} kredensial EduWeb per eduweb_id (hanya selama satu sinkron) */
    private array $kredensial = ['student' => [], 'teacher' => []];

    public function __construct(private KlienEduweb $klien) {}

    // ── Sinkron penuh ───────────────────────────────────────────────────────

    /** @throws KesalahanEduweb */
    public function penuh(?User $pelaku = null): HasilSinkron
    {
        return $this->dalamKunci('penuh', $pelaku, function (HasilSinkron $h) {
            $pengaturan = PengaturanSekolah::ambil();
            $pertama = $pengaturan->eduweb_sinkron_penuh_pada === null;

            // Posisi kejadian diambil SEBELUM data dibaca: kejadian setelahnya akan diikuti lewat sinkron kejadian.
            $kursor = $pertama ? $this->kejadianTerakhir() : null;

            // Baca semua dulu (gagal jaringan tidak meninggalkan data setengah jadi).
            $data = [
                'tahun' => $this->klien->get('academic-years')['data'] ?? [],
                'jurusan' => $this->klien->get('majors')['data'] ?? [],
                'kelas' => $this->klien->get('classrooms')['data'] ?? [],
                'mapel' => $this->klien->get('subjects')['data'] ?? [],
                'guru' => $this->semuaHalaman('teachers'),
                'siswa' => $this->semuaHalaman('students'),
                'pengampu' => $this->klien->get('teaching-assignments')['data'] ?? [],
            ];
            $this->kredensial = $this->bacaKredensial($pengaturan, $h);

            DB::transaction(function () use ($data, $h) {
                $this->peta = [];
                $this->tahunAjaran($data['tahun'], $h);
                $this->jurusan($data['jurusan'], $h);
                $this->kelas($data['kelas'], $h);
                $this->mapel($data['mapel'], $h);
                $this->guru($data['guru'], $h);
                $this->waliKelas($data['kelas']);
                $this->siswa($data['siswa'], $h);
                $this->terapkanKredensial($this->kredensial, $h);
                $this->pengampu($data['pengampu'], $h);
                $this->laporkanTakTerhubung($h);
            });

            $perubahan = ['eduweb_sinkron_penuh_pada' => now()];
            if ($pertama) {
                $perubahan['eduweb_kejadian_terakhir'] = $kursor;
                $perubahan['mode_sinkron_eduweb'] = true; // data master kini dikelola EduWeb
            }
            $pengaturan->update($perubahan);

            return $h;
        });
    }

    // ── Kejadian (kenaikan kelas, lulus, pindah, ...) ───────────────────────

    /** @throws KesalahanEduweb */
    public function kejadian(?User $pelaku = null): HasilSinkron
    {
        return $this->dalamKunci('kejadian', $pelaku, function (HasilSinkron $h) {
            $pengaturan = PengaturanSekolah::ambil();
            if ($pengaturan->eduweb_sinkron_penuh_pada === null) {
                throw new KesalahanEduweb('Jalankan sinkron penuh dulu sebelum mengikuti kejadian.', 'belum_penuh');
            }

            $this->peta = [];
            $kursor = (int) $pengaturan->eduweb_kejadian_terakhir;

            for ($i = 0; $i < self::MAKS_HALAMAN_KEJADIAN; $i++) {
                $j = $this->klien->get('events', ['after' => $kursor, 'limit' => 200]);

                DB::transaction(function () use ($j, $h) {
                    foreach ($j['data'] ?? [] as $e) {
                        $this->terapkanKejadian($e, $h);
                    }
                });

                $kursor = max($kursor, (int) ($j['last_id'] ?? $kursor));
                $pengaturan->update(['eduweb_kejadian_terakhir' => $kursor]);

                if (! ($j['has_more'] ?? false)) {
                    break;
                }
            }

            return $h;
        });
    }

    /** @param  array<string,mixed>  $e */
    private function terapkanKejadian(array $e, HasilSinkron $h): void
    {
        if (($e['type'] ?? null) !== 'student.changed') {
            $h->tambah('kejadian_diabaikan');

            return;
        }

        $siswa = Siswa::where('eduweb_id', (int) ($e['student_id'] ?? 0))->first();
        if (! $siswa) {
            $h->peringatan("Kejadian #{$e['id']}: siswa EduWeb {$e['student_id']} belum ada di sini. Jalankan sinkron penuh.");
            $h->tambah('kejadian_dilewati');

            return;
        }

        $payload = (array) ($e['payload'] ?? []);
        $aksi = (string) ($payload['action'] ?? '');
        $setelah = (array) ($payload['after'] ?? []);
        $sebelum = (array) ($payload['before'] ?? []);

        $status = $this->statusSiswa($setelah['status'] ?? null, false) ?? $siswa->status;
        $siswa->update(['status' => $status]);

        $statusKeanggotaan = $status === 'lulus' ? 'lulus' : 'aktif';
        $ada = [];
        foreach ((array) ($setelah['classroom_ids'] ?? []) as $idEdu) {
            $kelasId = $this->petaKelas((int) $idEdu);
            if (! $kelasId) {
                $h->peringatan("Kejadian #{$e['id']}: kelas EduWeb {$idEdu} belum ada di sini.");
                continue;
            }
            $ada[] = $kelasId;
            $this->setKeanggotaan($siswa->id, $kelasId, $statusKeanggotaan, ganti: ['aktif', 'lulus', 'naik', 'tinggal']);
        }

        $statusLama = match ($aksi) {
            'tidak_naik' => 'tinggal',
            'lulus' => 'lulus',
            default => 'naik',
        };
        foreach ((array) ($sebelum['classroom_ids'] ?? []) as $idEdu) {
            $kelasId = $this->petaKelas((int) $idEdu);
            if ($kelasId && ! in_array($kelasId, $ada, true)) {
                $this->setKeanggotaan($siswa->id, $kelasId, $statusLama, ganti: ['aktif']);
            }
        }

        $h->tambah('kejadian_diterapkan');
    }

    private function kejadianTerakhir(): int
    {
        $kursor = 0;
        for ($i = 0; $i < self::MAKS_HALAMAN_KEJADIAN; $i++) {
            $j = $this->klien->get('events', ['after' => $kursor, 'limit' => 500]);
            $kursor = max($kursor, (int) ($j['last_id'] ?? $kursor));
            if (! ($j['has_more'] ?? false)) {
                break;
            }
        }

        return $kursor;
    }

    // ── Entitas ─────────────────────────────────────────────────────────────

    private function tahunAjaran(array $baris, HasilSinkron $h): void
    {
        $semuaAktif = [];
        foreach ($baris as $r) {
            $nama = mb_substr(trim((string) ($r['name'] ?? '')), 0, 20);
            if ($nama === '') {
                $h->dilewati('tahun_ajaran', "Tahun ajaran EduWeb {$r['id']} tanpa nama dilewati.");
                continue;
            }

            $ta = TahunAjaran::where('eduweb_id', $r['id'])->first() ?? $this->lewatAlias('tahun_ajaran', (int) $r['id'], TahunAjaran::class)
                ?? TahunAjaran::where('nama', $nama)->first();

            if ($ta) {
                if ($ta->eduweb_id === null) {
                    $ta->update(['eduweb_id' => $r['id']]);
                    $h->diperbarui('tahun_ajaran');
                } elseif ((int) $ta->eduweb_id !== (int) $r['id']) {
                    $this->tambahAlias('tahun_ajaran', (int) $r['id'], $ta->id); // baris semester lain, tahun yang sama
                }
            } else {
                $ta = TahunAjaran::create(['eduweb_id' => $r['id'], 'nama' => $nama, 'aktif' => false]);
                $h->baru('tahun_ajaran');
            }
            $this->peta['tahun_ajaran'][(int) $r['id']] = $ta->id;
            $semuaAktif[$ta->id] = ($semuaAktif[$ta->id] ?? false) || (bool) ($r['is_active'] ?? false);

            $semester = (int) ($r['semester'] ?? 0) === 2 ? 'genap' : 'ganjil';
            $periode = Periode::firstOrCreate(['tahun_ajaran_id' => $ta->id, 'semester' => $semester, 'tipe' => 'reguler'], ['aktif' => false]);
            if ($periode->aktif !== (bool) ($r['is_active'] ?? false)) {
                $periode->update(['aktif' => (bool) ($r['is_active'] ?? false)]);
            }
            if ($periode->wasRecentlyCreated) {
                $h->baru('periode');
            }
        }

        foreach ($semuaAktif as $id => $aktif) {
            TahunAjaran::whereKey($id)->update(['aktif' => $aktif]);
        }
    }

    private function jurusan(array $baris, HasilSinkron $h): void
    {
        foreach ($baris as $r) {
            $kode = mb_substr(trim((string) ($r['code'] ?? '')), 0, 20) ?: 'J'.$r['id'];
            $nama = mb_substr(trim((string) ($r['name'] ?? $kode)), 0, 100);

            $j = Jurusan::where('eduweb_id', $r['id'])->first() ?? Jurusan::whereRaw('lower(kode) = ?', [mb_strtolower($kode)])->first();
            if ($j && $j->eduweb_id !== null && (int) $j->eduweb_id !== (int) $r['id']) {
                $h->dilewati('jurusan', "Jurusan \"{$nama}\": kode {$kode} sudah terhubung ke jurusan EduWeb lain.");
                continue;
            }

            if ($j) {
                $this->perbarui($j, ['eduweb_id' => $r['id'], 'nama' => $nama], 'jurusan', $h);
            } else {
                $j = Jurusan::create(['eduweb_id' => $r['id'], 'kode' => $kode, 'nama' => $nama]);
                $h->baru('jurusan');
            }
            $this->peta['jurusan'][(int) $r['id']] = $j->id;
        }
    }

    private function kelas(array $baris, HasilSinkron $h): void
    {
        foreach ($baris as $r) {
            $nama = mb_substr(trim((string) ($r['name'] ?? '')), 0, 30);
            $tingkat = [10 => 'X', 11 => 'XI', 12 => 'XII'][(int) ($r['grade'] ?? 0)] ?? null;
            $taId = $this->peta['tahun_ajaran'][(int) ($r['academic_year_id'] ?? 0)] ?? null;

            if ($nama === '' || ! $tingkat || ! $taId) {
                $h->dilewati('kelas', "Kelas EduWeb {$r['id']} (\"{$nama}\") dilewati: nama, tingkat (10-12), atau tahun ajaran tidak valid.");
                continue;
            }

            $nilai = ['tahun_ajaran_id' => $taId, 'tingkat' => $tingkat, 'nama' => $nama, 'jurusan_id' => $this->peta['jurusan'][(int) ($r['major_id'] ?? 0)] ?? null];

            $k = Kelas::where('eduweb_id', $r['id'])->first() ?? $this->lewatAlias('kelas', (int) $r['id'], Kelas::class);
            if ($k) {
                $this->perbarui($k, $nilai, 'kelas', $h);
            } else {
                $k = Kelas::where('tahun_ajaran_id', $taId)->where('nama', $nama)->first();
                if ($k && $k->eduweb_id !== null && (int) $k->eduweb_id !== (int) $r['id']) {
                    $this->tambahAlias('kelas', (int) $r['id'], $k->id); // kelas sama di semester lain
                    $k->update($nilai);
                } elseif ($k) {
                    $this->perbarui($k, $nilai + ['eduweb_id' => $r['id']], 'kelas', $h);
                } else {
                    $k = Kelas::create($nilai + ['eduweb_id' => $r['id']]);
                    $h->baru('kelas');
                }
            }
            $this->peta['kelas'][(int) $r['id']] = $k->id;
        }
    }

    private function mapel(array $baris, HasilSinkron $h): void
    {
        foreach ($baris as $r) {
            $kode = mb_substr(trim((string) ($r['code'] ?? '')), 0, 20) ?: 'M'.$r['id'];
            $nama = mb_substr(trim((string) ($r['name'] ?? $kode)), 0, 100);
            $kelompok = $this->kelompokMapel((string) ($r['group'] ?? ''));

            $m = Mapel::where('eduweb_id', $r['id'])->first()
                ?? Mapel::whereRaw('lower(kode) = ?', [mb_strtolower($kode)])->whereNull('eduweb_id')->first()
                ?? Mapel::whereRaw('lower(nama) = ?', [mb_strtolower($nama)])->whereNull('eduweb_id')->first();

            if ($m) {
                $this->perbarui($m, ['eduweb_id' => $r['id'], 'nama' => $nama, 'kelompok' => $kelompok], 'mapel', $h);
            } else {
                if (Mapel::where('kode', $kode)->exists()) {
                    $kode = mb_substr($kode, 0, 15).'-'.$r['id'];
                }
                $m = Mapel::create(['eduweb_id' => $r['id'], 'kode' => $kode, 'nama' => $nama, 'kelompok' => $kelompok, 'kkm' => 75]);
                $h->baru('mapel');
            }
            $this->peta['mapel'][(int) $r['id']] = $m->id;
        }
    }

    private function guru(array $baris, HasilSinkron $h): void
    {
        foreach ($baris as $r) {
            $nama = mb_substr(trim((string) ($r['name'] ?? '')), 0, 255);
            $aktif = (bool) ($r['is_active'] ?? false) && ! ($r['deleted'] ?? false);
            // Nama pengguna EduWeb dipakai bila ada; bila belum, NIP lalu NIY (perilaku lama).
            $ident = $this->bersihkanUsername((string) ($r['username'] ?? '')) ?: $this->bersihkanUsername((string) ($r['nip'] ?? '')) ?: $this->bersihkanUsername((string) ($r['niy'] ?? ''));

            $u = User::where('eduweb_id', $r['id'])->first();
            if (! $u && $ident !== '') {
                $u = User::whereRaw('lower(username) = ?', [mb_strtolower($ident)])->whereNull('eduweb_id')->first();
            }
            if (! $u) {
                $kandidat = User::whereRaw('lower(nama) = ?', [mb_strtolower($nama)])->whereNull('eduweb_id')->whereIn('role', [User::GURU, User::PENGAWAS])->get();
                $u = $kandidat->count() === 1 ? $kandidat->first() : null;
                if ($kandidat->count() > 1) {
                    $h->peringatan("Guru \"{$nama}\": ada {$kandidat->count()} akun lokal bernama sama, tidak dicocokkan otomatis.");
                }
            }

            if ($u && $u->role === User::ADMIN) {
                $h->dilewati('guru', "Guru \"{$nama}\" cocok dengan akun admin lokal; akun admin tidak pernah diubah oleh sinkron.");
                continue;
            }

            if ($u) {
                $nilai = ['eduweb_id' => $r['id'], 'nama' => $nama, 'aktif' => $aktif];
                if ($ident !== '' && mb_strtolower($ident) !== mb_strtolower($u->username)) {
                    if ($this->usernameDipakai(User::class, $ident, $u->id)) {
                        $h->peringatan("Guru \"{$nama}\": nama pengguna EduWeb \"{$ident}\" sudah dipakai akun lain di sini, nama pengguna lokal tidak diubah.");
                    } else {
                        $nilai['username'] = $ident;
                    }
                }
                $this->perbarui($u, $nilai, 'guru', $h);
            } elseif ($aktif) {
                $username = $this->usernameBebas($ident !== '' ? $ident : (Str::slug($nama, '.') ?: 'guru'.$r['id']));
                $hashEdu = $this->hashKredensial('teacher', (int) $r['id']);
                $u = new User(['eduweb_id' => $r['id'], 'nama' => $nama, 'username' => $username, 'role' => User::GURU, 'aktif' => true]);
                if ($hashEdu) {
                    $u->forceFill(['password' => $hashEdu, 'eduweb_hash_sandi' => $hashEdu])->saveQuietly(); // hash EduWeb: login sama dengan EduWeb
                } else {
                    $sandi = KataSandi::acak(12);
                    $u->password = $sandi; // dihash oleh cast
                    $u->save();
                    $h->akunBaru[] = ['username' => $username, 'nama' => $nama, 'sandi' => $sandi];
                }
                $h->baru('guru');
            } else {
                continue; // guru nonaktif yang tidak dikenal: tidak perlu dibuatkan akun
            }
            $this->peta['guru'][(int) $r['id']] = $u->id;
        }
    }

    private function waliKelas(array $kelasEdu): void
    {
        foreach ($kelasEdu as $r) {
            $kelasId = $this->peta['kelas'][(int) $r['id']] ?? null;
            $guruId = $this->peta['guru'][(int) ($r['homeroom_teacher_id'] ?? 0)] ?? null;
            if ($kelasId && $guruId) {
                Kelas::whereKey($kelasId)->update(['wali_kelas_id' => $guruId]);
            }
        }
    }

    private function siswa(array $baris, HasilSinkron $h): void
    {
        $sandiAcak = Hash::make(Str::random(40)); // satu hash untuk semua siswa baru; tidak ada yang tahu aslinya
        $adaDiLokal = Siswa::pluck('id', 'eduweb_id')->all();
        $keanggotaan = DB::table('keanggotaan_kelas')->get(['siswa_id', 'kelas_id', 'status'])->keyBy(fn ($k) => $k->siswa_id.'-'.$k->kelas_id);
        $baruMasuk = [];
        $tanpaNisn = 0;

        foreach ($baris as $r) {
            $nisn = mb_substr(trim((string) ($r['nisn'] ?? '')), 0, 20);
            $nis = mb_substr(trim((string) ($r['nis'] ?? '')), 0, 30);
            $ident = $nisn !== '' ? $nisn : mb_substr($nis, 0, 20);
            $nama = mb_substr(trim((string) ($r['name'] ?? '')), 0, 100);
            if ($ident === '' || $nama === '') {
                $h->dilewati('siswa', "Siswa EduWeb {$r['id']} (\"{$nama}\") dilewati: tidak punya NISN maupun NIS.");
                continue;
            }
            if ($nisn === '') {
                $tanpaNisn++;
            }

            $status = $this->statusSiswa($r['status'] ?? null, (bool) ($r['is_active'] ?? true) && ! ($r['deleted'] ?? false)) ?? 'aktif';
            $username = $this->bersihkanUsername((string) ($r['username'] ?? ''));
            $isi = [
                'nis' => $nis ?: null, 'nama' => $nama, 'jenis_kelamin' => in_array($r['gender'] ?? null, ['L', 'P'], true) ? $r['gender'] : null,
                'tempat_lahir' => isset($r['birth_place']) ? mb_substr((string) $r['birth_place'], 0, 60) : null,
                'tgl_lahir' => $r['birth_date'] ?? null, 'status' => $status,
            ];

            $s = isset($adaDiLokal[$r['id']]) ? Siswa::find($adaDiLokal[$r['id']]) : null;
            $s ??= Siswa::where('nisn', $ident)->whereNull('eduweb_id')->first();

            $bentrok = Siswa::where('nisn', $ident)->when($s, fn ($q) => $q->whereKeyNot($s->id))->exists();
            if ($bentrok) {
                $h->dilewati('siswa', "Siswa \"{$nama}\" dilewati: nama pengguna {$ident} sudah dipakai siswa lain (terhubung ke data EduWeb berbeda).");
                continue;
            }

            if ($username !== '' && $this->usernameDipakai(Siswa::class, $username, $s?->id)) {
                $h->peringatan("Siswa \"{$nama}\": nama pengguna EduWeb \"{$username}\" sudah dipakai siswa lain di sini, masuk memakai {$ident}.");
                $username = '';
            }
            $isi['username'] = $username !== '' ? $username : null;

            if ($s) {
                $this->perbarui($s, $isi + ['eduweb_id' => $r['id'], 'nisn' => $ident], 'siswa', $h);
            } else {
                $hashEdu = $this->hashKredensial('student', (int) $r['id']);
                $s = new Siswa;
                $s->forceFill($isi + ['eduweb_id' => $r['id'], 'nisn' => $ident, 'password' => $hashEdu ?: $sandiAcak, 'eduweb_hash_sandi' => $hashEdu]);
                $s->saveQuietly(); // password sudah berupa hash; hindari hash ulang oleh cast
                $h->baru('siswa');
            }

            $statusAnggota = $status === 'lulus' ? 'lulus' : 'aktif';
            foreach ((array) ($r['classroom_ids'] ?? []) as $idEdu) {
                $kelasId = $this->peta['kelas'][(int) $idEdu] ?? $this->petaKelas((int) $idEdu);
                if (! $kelasId) {
                    $h->peringatan("Siswa \"{$nama}\": kelas EduWeb {$idEdu} tidak dikenal, keanggotaan dilewati.");
                    continue;
                }
                $kunci = $s->id.'-'.$kelasId;
                $lama = $keanggotaan[$kunci] ?? null;
                if (! $lama) {
                    $baruMasuk[] = ['siswa_id' => $s->id, 'kelas_id' => $kelasId, 'status' => $statusAnggota, 'created_at' => now(), 'updated_at' => now()];
                } elseif (in_array($lama->status, ['aktif', 'lulus'], true) && $lama->status !== $statusAnggota) {
                    DB::table('keanggotaan_kelas')->where('siswa_id', $s->id)->where('kelas_id', $kelasId)->update(['status' => $statusAnggota, 'updated_at' => now()]);
                }
            }
        }

        foreach (array_chunk($baruMasuk, 500) as $potong) {
            DB::table('keanggotaan_kelas')->insert($potong);
        }
        $h->tambah('keanggotaan_baru', count($baruMasuk));
        if ($tanpaNisn > 0) {
            $h->tambah('siswa_login_pakai_nis', $tanpaNisn);
            $h->peringatan("{$tanpaNisn} siswa tidak punya NISN di EduWeb; NIS dipakai sebagai nama pengguna login.");
        }
    }

    private function pengampu(array $baris, HasilSinkron $h): void
    {
        $dari = [];
        foreach ($baris as $r) {
            $g = $this->peta['guru'][(int) ($r['teacher_id'] ?? 0)] ?? null;
            $m = $this->peta['mapel'][(int) ($r['subject_id'] ?? 0)] ?? null;
            $k = $this->peta['kelas'][(int) ($r['classroom_id'] ?? 0)] ?? null;
            if ($g && $m && $k) {
                $dari["{$g}-{$m}-{$k}"] = ['guru_id' => $g, 'mapel_id' => $m, 'kelas_id' => $k];
            } else {
                $h->tambah('pengampu_dilewati');
            }
        }

        $ada = DB::table('pengampu')->get(['id', 'guru_id', 'mapel_id', 'kelas_id'])->keyBy(fn ($p) => "{$p->guru_id}-{$p->mapel_id}-{$p->kelas_id}");
        $baru = array_values(array_map(fn ($p) => $p + ['created_at' => now(), 'updated_at' => now()], array_diff_key($dari, $ada->all())));
        foreach (array_chunk($baru, 500) as $potong) {
            DB::table('pengampu')->insert($potong);
        }

        // Yang sudah tidak ada di EduWeb dihapus, tetapi hanya bila guru, mapel, dan kelasnya semua berasal dari EduWeb.
        $terhubung = DB::table('pengampu as p')
            ->join('users as u', 'u.id', '=', 'p.guru_id')->join('mapel as m', 'm.id', '=', 'p.mapel_id')->join('kelas as k', 'k.id', '=', 'p.kelas_id')
            ->whereNotNull('u.eduweb_id')->whereNotNull('m.eduweb_id')->whereNotNull('k.eduweb_id')
            ->get(['p.id', 'p.guru_id', 'p.mapel_id', 'p.kelas_id']);
        $usang = $terhubung->filter(fn ($p) => ! isset($dari["{$p->guru_id}-{$p->mapel_id}-{$p->kelas_id}"]))->pluck('id')->all();
        foreach (array_chunk($usang, 500) as $potong) {
            DB::table('pengampu')->whereIn('id', $potong)->delete();
        }

        $h->tambah('pengampu_baru', count($baru));
        $h->tambah('pengampu_dihapus', count($usang));
    }

    private function laporkanTakTerhubung(HasilSinkron $h): void
    {
        $h->tambah('siswa_belum_terhubung', Siswa::whereNull('eduweb_id')->count());
        $h->tambah('kelas_belum_terhubung', Kelas::whereNull('eduweb_id')->count());
        $h->tambah('mapel_belum_terhubung', Mapel::whereNull('eduweb_id')->count());
        $h->tambah('guru_belum_terhubung', User::whereNull('eduweb_id')->whereIn('role', [User::GURU, User::PENGAWAS])->count());
    }

    // ── Kredensial (username + hash kata sandi) ─────────────────────────────

    /**
     * Membaca kredensial dari EduWeb. Bila lingkup "credentials" belum dinyalakan di EduWeb (403/404),
     * sinkron data tetap berjalan dan login memakai sandi lokal (Kartu Ujian).
     *
     * @return array{student:array<int,array<string,mixed>>,teacher:array<int,array<string,mixed>>}
     */
    private function bacaKredensial(PengaturanSekolah $pengaturan, HasilSinkron $h): array
    {
        try {
            $hasil = [];
            foreach (['student', 'teacher'] as $peran) {
                $hasil[$peran] = [];
                foreach ($this->semuaHalaman('credentials', ['role' => $peran]) as $r) {
                    $hasil[$peran][(int) ($r['id'] ?? 0)] = $r;
                }
            }
        } catch (KesalahanEduweb $e) {
            if (! in_array($e->status, [403, 404], true)) {
                throw $e;
            }
            $pengaturan->update(['eduweb_kredensial' => 'belum_aktif']);
            $h->peringatan('Kata sandi belum bisa disinkronkan: akses kredensial belum dinyalakan untuk kunci ini di EduWeb (menu Integrasi Ujian). Sementara, siswa masuk memakai sandi dari Kartu Ujian.');

            return ['student' => [], 'teacher' => []];
        }

        $pengaturan->update(['eduweb_kredensial' => 'aktif']);

        return $hasil;
    }

    /** Hash bcrypt EduWeb yang sah untuk satu pengguna, atau null. */
    private function hashKredensial(string $peran, int $idEdu): ?string
    {
        $hash = $this->kredensial[$peran][$idEdu]['password_hash'] ?? null;

        return is_string($hash) && $this->hashSah($hash) ? $hash : null;
    }

    private function hashSah(string $hash): bool
    {
        // Hanya bcrypt dengan biaya wajar; bentuk lain ditolak (juga mencegah biaya ekstrem membebani server).
        return preg_match('/^\$2[aby]\$(0[4-9]|1[0-4])\$[.\/A-Za-z0-9]{53}$/', $hash) === 1;
    }

    /**
     * Menerapkan username dan hash sandi EduWeb pada akun yang sudah terhubung. Sandi lokal hanya diganti bila
     * hash EduWeb berubah dibanding yang terakhir terlihat (sandi sementara dari Kartu Ujian tidak tertimpa).
     * Akun admin tidak pernah disentuh.
     *
     * @param  array{student:array<int,array<string,mixed>>,teacher:array<int,array<string,mixed>>}  $kredensial
     */
    private function terapkanKredensial(array $kredensial, HasilSinkron $h): void
    {
        foreach (['student' => Siswa::class, 'teacher' => User::class] as $peran => $model) {
            foreach (array_chunk($kredensial[$peran] ?? [], 500, true) as $potong) {
                $akun = $model::whereIn('eduweb_id', array_keys($potong))
                    ->when($model === User::class, fn ($q) => $q->where('role', '!=', User::ADMIN))
                    ->get()->keyBy('eduweb_id');

                foreach ($potong as $idEdu => $r) {
                    $a = $akun[$idEdu] ?? null;
                    if (! $a) {
                        continue;
                    }

                    $ubah = [];
                    $username = $this->bersihkanUsername((string) ($r['username'] ?? ''));
                    if ($username !== '' && mb_strtolower($username) !== mb_strtolower((string) $a->username)) {
                        if ($this->usernameDipakai($model, $username, $a->id)) {
                            $h->peringatan("Nama pengguna EduWeb \"{$username}\" sudah dipakai akun lain di sini; tidak diubah.");
                        } else {
                            $ubah['username'] = $username;
                        }
                    }

                    $hash = $r['password_hash'] ?? null;
                    if (is_string($hash) && $hash !== '') {
                        if (! $this->hashSah($hash)) {
                            $h->tambah('sandi_bentuk_tak_dikenal');
                        } elseif ($a->eduweb_hash_sandi !== $hash) {
                            $ubah += ['password' => $hash, 'eduweb_hash_sandi' => $hash];
                            $h->tambah('sandi_diperbarui');
                        }
                    }

                    if ($ubah) {
                        DB::table($a->getTable())->where('id', $a->id)->update($ubah + ['updated_at' => now()]);
                    }
                }
            }
        }
    }

    /** @param  class-string<Model>  $model */
    private function usernameDipakai(string $model, string $username, ?int $kecualiId): bool
    {
        return $model::whereRaw('lower(username) = ?', [mb_strtolower($username)])
            ->when($kecualiId, fn ($q) => $q->whereKeyNot($kecualiId))->exists();
    }

    // ── Pembantu ────────────────────────────────────────────────────────────

    /** Isi model dan simpan hanya bila ada yang berubah; hanya yang benar-benar berubah dihitung "diperbarui". */
    private function perbarui(Model $m, array $nilai, string $entitas, HasilSinkron $h): void
    {
        $m->forceFill($nilai);
        if ($m->isDirty()) {
            $m->save();
            $h->diperbarui($entitas);
        }
    }

    private function dalamKunci(string $jenis, ?User $pelaku, callable $kerja): HasilSinkron
    {
        $kunci = Cache::lock('eduweb:sinkron', 900);
        if (! $kunci->get()) {
            throw new KesalahanEduweb('Sinkron lain sedang berjalan. Tunggu sampai selesai.', 'sibuk');
        }

        set_time_limit(300);
        $mulai = microtime(true);
        $h = new HasilSinkron;

        try {
            $kerja($h);
            $this->catat($jenis, 'berhasil', $pelaku, $h, null, $mulai);
            AuditLog::catat("eduweb.sinkron_{$jenis}", 'berhasil', $pelaku);

            return $h;
        } catch (KesalahanEduweb $e) {
            $this->catat($jenis, 'gagal', $pelaku, $h, $e->getMessage(), $mulai);
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $this->catat($jenis, 'gagal', $pelaku, $h, 'Galat tak terduga saat menyimpan data. Tidak ada data yang diubah. Periksa log aplikasi.', $mulai);
            throw new KesalahanEduweb('Galat tak terduga saat sinkron. Tidak ada data yang diubah; periksa log aplikasi.', 'tak_terduga');
        } finally {
            $kunci->release();
        }
    }

    private function catat(string $jenis, string $status, ?User $pelaku, HasilSinkron $h, ?string $galat, float $mulai): void
    {
        DB::table('sinkron_log')->insert([
            'jenis' => $jenis, 'status' => $status, 'user_id' => $pelaku?->id,
            'ringkasan' => json_encode($h->ringkasan(), JSON_UNESCAPED_UNICODE), 'galat' => $galat ? mb_substr($galat, 0, 300) : null,
            'durasi_ms' => (int) round((microtime(true) - $mulai) * 1000), 'created_at' => now(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function semuaHalaman(string $jalur, array $query = []): array
    {
        $hasil = [];
        for ($hal = 1; $hal <= 200; $hal++) {
            $j = $this->klien->get($jalur, $query + ['per_page' => self::HALAMAN, 'page' => $hal]);
            array_push($hasil, ...($j['data'] ?? []));
            if (($j['current_page'] ?? $hal) >= ($j['last_page'] ?? 1)) {
                break;
            }
        }

        return $hasil;
    }

    private function statusSiswa(?string $status, bool $aktif): ?string
    {
        if ($status === null) {
            return $aktif ? null : 'keluar';
        }

        return match ($status) {
            'aktif' => $aktif ? 'aktif' : 'keluar',
            'alumni' => 'lulus',
            'keluar', 'pindah' => 'keluar',
            default => null,
        };
    }

    private function kelompokMapel(string $teks): string
    {
        if (preg_match('/muatan\s+lokal/i', $teks)) {
            return 'ML';
        }
        if (preg_match('/kelompok\s+(C[123]|[AB])\b/i', $teks, $m)) {
            return strtoupper($m[1]);
        }

        return 'A';
    }

    private function bersihkanUsername(string $teks): string
    {
        return mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '', $teks) ?? '', 0, 50);
    }

    private function usernameBebas(string $dasar): string
    {
        $dasar = mb_substr($this->bersihkanUsername($dasar), 0, 44) ?: 'guru';
        $kandidat = $dasar;
        for ($i = 2; User::whereRaw('lower(username) = ?', [mb_strtolower($kandidat)])->exists(); $i++) {
            $kandidat = $dasar.$i;
        }

        return $kandidat;
    }

    private function petaKelas(int $idEdu): ?int
    {
        if (! isset($this->peta['kelas'])) {
            $this->peta['kelas'] = Kelas::whereNotNull('eduweb_id')->pluck('id', 'eduweb_id')->all()
                + DB::table('eduweb_alias')->where('entitas', 'kelas')->pluck('lokal_id', 'eduweb_id')->all();
        }

        return $this->peta['kelas'][$idEdu] ?? null;
    }

    private function setKeanggotaan(int $siswaId, int $kelasId, string $status, array $ganti): void
    {
        $lama = DB::table('keanggotaan_kelas')->where('siswa_id', $siswaId)->where('kelas_id', $kelasId)->first();
        if (! $lama) {
            DB::table('keanggotaan_kelas')->insert(['siswa_id' => $siswaId, 'kelas_id' => $kelasId, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        } elseif ($lama->status !== $status && in_array($lama->status, $ganti, true)) {
            DB::table('keanggotaan_kelas')->where('id', $lama->id)->update(['status' => $status, 'updated_at' => now()]);
        }
    }

    /** @param  class-string  $model */
    private function lewatAlias(string $entitas, int $idEdu, string $model): mixed
    {
        $lokal = DB::table('eduweb_alias')->where('entitas', $entitas)->where('eduweb_id', $idEdu)->value('lokal_id');

        return $lokal ? $model::find($lokal) : null;
    }

    private function tambahAlias(string $entitas, int $idEdu, int $lokalId): void
    {
        DB::table('eduweb_alias')->updateOrInsert(['entitas' => $entitas, 'eduweb_id' => $idEdu], ['lokal_id' => $lokalId]);
    }
}
