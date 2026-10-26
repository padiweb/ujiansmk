<?php

namespace App\Services;

use App\Models\Kelas;
use App\Models\NilaiRapor;
use App\Models\Periode;
use App\Models\RaporSiswa;

/** Menyusun data satu lembar rapor per siswa dari data yang sudah final (snapshot), siap dicetak. */
class LayananCetakRapor
{
    private const URUT_KELOMPOK = ['A' => 1, 'B' => 2, 'ML' => 3, 'C1' => 4, 'C2' => 5, 'C3' => 6];

    /** Urutan mapel dalam satu kelompok (kata kunci nama mapel; yang tidak cocok di akhir). */
    private const URUT_NAMA = [
        'agama', 'pancasila', 'indonesia', 'sejarah', 'seni', ['olahraga', 'pjok'], 'matematika', 'inggris', 'informatika',
        ['ipas', 'ilmu pengetahuan'], 'dasar', ['konsentrasi', 'keahlian'], ['kreativitas', 'kewirausahaan'],
        ['pkl', 'kerja industri', 'kerja lapangan'], 'jepang',
    ];

    public const KETERANGAN_EKSKUL = ['A' => 'Sangat Baik', 'B' => 'Baik', 'C' => 'Cukup', 'D' => 'Kurang'];

    /** Siswa kelas ini yang rapornya sudah final: id => nama (untuk pilihan cetak). */
    public function siswaFinal(Kelas $kelas, Periode $periode): array
    {
        return RaporSiswa::with('siswa:id,nama')->where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->where('status', 'final')
            ->get()->sortBy(fn ($r) => $r->siswa->nama)->mapWithKeys(fn ($r) => [$r->siswa_id => $r->siswa->nama])->all();
    }

    /**
     * @return list<array<string,mixed>> satu elemen per siswa final (diurutkan menurut nama)
     */
    public function data(Kelas $kelas, Periode $periode, ?int $siswaId = null): array
    {
        $kelas->loadMissing('waliKelas:id,nama', 'jurusan:id,nama,program_keahlian');
        $periode->loadMissing('tahunAjaran');
        $sekolah = \App\Models\PengaturanSekolah::ambil();

        $rapor = RaporSiswa::with('siswa')->where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->where('status', 'final')
            ->when($siswaId, fn ($q) => $q->where('siswa_id', $siswaId))->get()->sortBy(fn ($r) => mb_strtolower($r->siswa->nama))->values();

        $nilai = NilaiRapor::with('mapel:id,nama,kelompok')->where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->where('status', 'final')
            ->whereIn('siswa_id', $rapor->pluck('siswa_id'))->get()->groupBy('siswa_id');

        $genap = $periode->semester === 'genap';
        $xii = $kelas->tingkat === 'XII';
        $tujuan = preg_replace('/^(XI|X)(?=\s|$)/', $kelas->tingkat === 'X' ? 'XI' : 'XII', $kelas->nama);

        return $rapor->map(function (RaporSiswa $r) use ($nilai, $kelas, $periode, $sekolah, $genap, $xii, $tujuan) {
            [$umum, $kejuruan] = $this->mapel($nilai[$r->siswa_id] ?? collect());

            return [
                'siswa' => $r->siswa,
                'kelas' => $r->kelas_nama ?: $kelas->nama,
                'jurusan' => $kelas->jurusan?->nama,
                'fase' => $kelas->tingkat === 'X' ? 'E' : 'F',
                'semester' => $periode->semester === 'genap' ? 'Genap' : 'Gasal',
                'tahun_ajaran' => $periode->tahunAjaran?->nama,
                'umum' => $umum, 'kejuruan' => $kejuruan,
                'ekskul' => collect($r->ekskul ?? [])->map(fn ($e) => $e + ['keterangan' => self::KETERANGAN_EKSKUL[strtoupper($e['predikat'] ?? '')] ?? '-'])->all(),
                'sakit' => $r->sakit, 'izin' => $r->izin, 'alpa' => $r->alpa,
                'catatan' => $r->catatan_wali,
                'bagian_keputusan' => $genap,
                'xii' => $xii, 'keputusan' => $r->keputusan, 'kelas_tujuan' => $tujuan,
                'wali_kelas' => $kelas->waliKelas?->nama,
            ];
        })->all();
    }

    /** @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>} [umum, kejuruan] siap tampil, bernomor lanjut */
    private function mapel($nilai): array
    {
        $baris = $nilai->filter(fn (NilaiRapor $n) => $n->na !== null)->map(function (NilaiRapor $n) {
            $nama = $n->snapshot['mapel_nama'] ?? $n->mapel->nama;
            $k = strtoupper(trim((string) $n->mapel->kelompok));

            return [
                'nama' => $nama, 'np' => $n->np, 'nk' => $n->nk, 'na' => $n->na, 'predikat' => $n->predikat,
                'umum' => in_array($k, ['A', 'B', 'ML'], true) || str_contains(mb_strtolower($nama), 'jepang'),
                'urut' => [self::URUT_KELOMPOK[$k] ?? 7, $this->urutNama($nama), mb_strtolower($nama)],
            ];
        })->sortBy('urut')->values();

        $no = 1;
        $beri = function ($kumpulan) use (&$no) {
            return $kumpulan->map(function ($b) use (&$no) {
                $b['no'] = $no++;

                return $b;
            })->all();
        };

        return [$beri($baris->where('umum', true)->values()), $beri($baris->where('umum', false)->values())];
    }

    private function urutNama(string $nama): int
    {
        $n = mb_strtolower($nama);
        foreach (self::URUT_NAMA as $i => $kata) {
            foreach ((array) $kata as $k) {
                if (str_contains($n, $k)) {
                    return $i + 1;
                }
            }
        }

        return 99;
    }
}
