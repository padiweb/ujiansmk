<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DenahPenempatan;
use App\Models\JadwalUjian;
use App\Models\Kelas;
use App\Models\PengaturanSekolah;
use App\Models\SesiUjian;
use App\Models\Siswa;
use App\Models\User;
use App\Support\KataSandi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Data kartu ujian dan daftar hadir, serta pembuatan kata sandi sementara massal. */
class LayananKartu
{
    /** Hash bcrypt makan CPU (±0,1 dtk): satu permintaan dibatasi agar tidak melewati batas waktu hosting. */
    public const MAKS_SANDI_PER_PERMINTAAN = 150;

    public function __construct(private LayananJadwal $jadwal) {}

    /**
     * Peserta jadwal beserta kelas, ruang, dan meja. Jadwal aktif memakai sesi yang sudah dibuat;
     * jadwal draf memakai calon peserta dan penempatan dari denah yang dipasang.
     *
     * @param  list<int>|null  $kelasIds  batasi ke kelas tertentu
     * @return Collection<int,array<string,mixed>>
     */
    public function peserta(JadwalUjian $jadwal, ?array $kelasIds = null, ?int $ruangId = null): Collection
    {
        $kelasJadwal = $jadwal->kelas()->get()->keyBy('id');
        $punyaSesi = $jadwal->sesi()->exists();
        $sesi = collect();

        $tempat = $jadwal->denah_id
            ? DenahPenempatan::where('denah_id', $jadwal->denah_id)->with('ruang:id,nama')->get()->keyBy('siswa_id')
            : collect();

        if ($punyaSesi) {
            $sesi = SesiUjian::where('jadwal_id', $jadwal->id)->with('ruang:id,nama')->get()->keyBy('siswa_id');
            $siswa = Siswa::whereIn('id', $sesi->keys())->get();
        } else {
            $siswa = Siswa::whereIn('id', $this->jadwal->pesertaBerhak($jadwal))->get();
        }

        $kelasSiswa = $this->kelasSiswa($siswa->pluck('id')->all(), $kelasJadwal->keys()->all());

        return $siswa->map(function (Siswa $s) use ($punyaSesi, $sesi, $tempat, $kelasSiswa) {
            $kelas = $kelasSiswa[$s->id] ?? null;
            $ruang = $punyaSesi ? $sesi[$s->id]->ruang : ($tempat[$s->id]->ruang ?? null);
            $meja = $punyaSesi ? $sesi[$s->id]->no_meja : ($tempat[$s->id]->meja ?? null);

            return [
                'siswa' => $s, 'nama' => $s->nama, 'nisn' => $s->nisn,
                'kelas_id' => $kelas?->id, 'kelas' => $kelas?->nama ?? '—',
                'ruang_id' => $ruang?->id, 'ruang' => $ruang?->nama, 'meja' => $meja,
            ];
        })
            ->when($kelasIds !== null, fn ($c) => $c->filter(fn ($p) => in_array($p['kelas_id'], $kelasIds, true)))
            ->when($ruangId !== null, fn ($c) => $c->filter(fn ($p) => $p['ruang_id'] === $ruangId))
            ->values();
    }

    /** Buat kata sandi sementara baru untuk siswa terpilih. @return array<int,string> siswa_id => sandi polos */
    public function buatSandi(Collection $peserta, User $pelaku, JadwalUjian $jadwal): array
    {
        if (PengaturanSekolah::sinkronAktif()) {
            throw new KesalahanJadwal('Mode sinkron EduWeb aktif: kata sandi siswa dikelola dari EduWeb, bukan di sini.');
        }
        if ($peserta->isEmpty()) {
            throw new KesalahanJadwal('Tidak ada siswa yang dipilih.');
        }
        if ($peserta->count() > self::MAKS_SANDI_PER_PERMINTAAN) {
            throw new KesalahanJadwal('Terlalu banyak siswa sekaligus ('.$peserta->count().'). Pilih kelas lebih sedikit, maksimal '.self::MAKS_SANDI_PER_PERMINTAAN.' siswa per kali.');
        }

        set_time_limit(180);
        $hasil = [];
        foreach ($peserta as $p) {
            $sandi = KataSandi::acak();
            $p['siswa']->forceFill(['password' => $sandi])->save(); // dihash oleh cast
            $hasil[$p['siswa']->id] = $sandi;
        }

        AuditLog::catat('kartu.sandi_massal', "jadwal {$jadwal->id}: {$peserta->count()} siswa", $pelaku);

        return $hasil;
    }

    /** @return array<int,Kelas> siswa_id => kelas (dari kelas jadwal bila ada) */
    private function kelasSiswa(array $siswaIds, array $kelasJadwal): array
    {
        $hasil = [];
        $baris = DB::table('keanggotaan_kelas')->whereIn('siswa_id', $siswaIds)->orderByRaw('case when kelas_id in ('.($kelasJadwal ? implode(',', array_map('intval', $kelasJadwal)) : '0').') then 0 else 1 end')->get();
        $kelas = Kelas::whereIn('id', $baris->pluck('kelas_id'))->get()->keyBy('id');
        foreach ($baris as $b) {
            $hasil[$b->siswa_id] ??= $kelas[$b->kelas_id];
        }

        return $hasil;
    }
}
