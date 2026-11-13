<?php

namespace App\Services;

use App\Models\Kelas;
use App\Models\NilaiRapor;
use App\Models\NilaiUs;
use App\Models\Siswa;
use Illuminate\Support\Collection;

/**
 * Menyusun transkrip nilai dan SKL: nilai rapor enam semester (X-1 sampai XII-2) dari semua semester reguler siswa,
 * ditambah nilai Ujian Sekolah. Rumus mengikuti aplikasi lama:
 *  - Tiga tahun ajaran terakhir yang punya nilai dipetakan dari belakang: terakhir = XII, sebelumnya = XI, lalu X.
 *  - Nilai rata-rata mapel = rata-rata semester yang bernilai > 0; bila tidak ada, memakai nilai US.
 *  - Nilai US mengisi kolom XII-2 hanya bila kolom itu kosong dan mapelnya ditandai "masuk XII-2" saat Input US.
 */
class LayananTranskrip
{
    public const SLOT = ['X 1', 'X 2', 'XI 1', 'XI 2', 'XII 1', 'XII 2'];

    public const KELOMPOK = ['UMUM' => 'Kelompok Mata Pelajaran Umum', 'KEJURUAN' => 'Kelompok Mata Pelajaran Kejuruan', 'ML' => 'Muatan Lokal'];

    public function __construct(private LayananNilaiRapor $nilai) {}

    /** @return Collection<int,Siswa> siswa kelas XII ini (aktif atau lulus) */
    public function siswa(Kelas $kelas): Collection
    {
        return $this->nilai->siswa($kelas);
    }

    /**
     * @return array{
     *   kelompok:array<string,list<array{nama:string,slot:array<int,?float>,us:?float,rata:?float}>>,
     *   rata_slot:array<int,?float>, rata_us:?float, rata_semua:?float, ada:bool
     * }
     */
    public function susun(Siswa $siswa): array
    {
        $rapor = NilaiRapor::with('mapel', 'periode.tahunAjaran')->where('siswa_id', $siswa->id)->whereNotNull('na')
            ->whereHas('periode', fn ($p) => $p->where('tipe', 'reguler'))->get();
        $us = NilaiUs::with('mapel')->where('siswa_id', $siswa->id)->get();

        // Tiga tahun ajaran terakhir dipetakan dari belakang: XII, XI, X.
        $tahun = $rapor->map(fn ($n) => $n->periode->tahunAjaran->nama)->unique()->sort()->values()->all();
        $tahun = array_slice($tahun, -3);
        $dasar = [];
        $jumlah = count($tahun);
        foreach ($tahun as $i => $nama) {
            $dasar[$nama] = (3 - $jumlah + $i) * 2; // 3 tahun: 0,2,4; 2 tahun: 2,4; 1 tahun: 4
        }

        $mapel = []; // kunci: id mapel
        foreach ($rapor as $n) {
            $nama = $n->periode->tahunAjaran->nama;
            if (! isset($dasar[$nama]) || (int) $n->na <= 0) {
                continue;
            }
            $slot = $dasar[$nama] + ($n->periode->semester === 'genap' ? 1 : 0);
            $mapel[$n->mapel_id]['mapel'] = $n->mapel;
            $mapel[$n->mapel_id]['slot'][$slot] = (float) $n->na;
        }
        foreach ($us as $u) {
            if ($u->dipakai() === null || $u->dipakai() <= 0) {
                continue;
            }
            $mapel[$u->mapel_id]['mapel'] ??= $u->mapel;
            $mapel[$u->mapel_id]['us'] = $u->dipakai();
            $mapel[$u->mapel_id]['xii2'] = $u->masuk_xii2;
        }

        $kelompok = ['UMUM' => [], 'KEJURUAN' => [], 'ML' => []];
        foreach ($mapel as $m) {
            $slot = array_fill(0, 6, null);
            foreach ($m['slot'] ?? [] as $i => $v) {
                $slot[$i] = $v;
            }
            $nilaiUs = $m['us'] ?? null;
            if ($slot[5] === null && ($m['xii2'] ?? false) && $nilaiUs !== null) {
                $slot[5] = $nilaiUs;
            }
            $ada = array_filter($slot, fn ($v) => $v !== null && $v > 0);
            $rata = $ada ? round(array_sum($ada) / count($ada), 2) : ($nilaiUs !== null ? round($nilaiUs, 2) : null);
            if ($rata === null) {
                continue;
            }

            $k = $m['mapel']->kelompok;
            $grup = str_starts_with((string) $k, 'C') ? 'KEJURUAN' : ($k === 'ML' ? 'ML' : 'UMUM');
            $kelompok[$grup][] = ['nama' => $m['mapel']->nama_skl ?: $m['mapel']->nama, 'slot' => $slot, 'us' => $nilaiUs, 'rata' => $rata, 'urut' => $m['mapel']->nama];
        }
        foreach ($kelompok as $g => $baris) {
            usort($baris, fn ($a, $b) => strcmp($a['urut'], $b['urut']));
            $kelompok[$g] = array_map(fn ($b) => array_diff_key($b, ['urut' => 1]), $baris);
        }
        $kelompok = array_filter($kelompok);

        $semua = collect($kelompok)->flatten(1);
        $rataSlot = [];
        for ($i = 0; $i < 6; $i++) {
            $v = $semua->pluck("slot.$i")->filter(fn ($x) => $x !== null && $x > 0);
            $rataSlot[$i] = $v->isEmpty() ? null : round($v->avg(), 2);
        }
        $vus = $semua->pluck('us')->filter(fn ($x) => $x !== null && $x > 0);
        $vr = $semua->pluck('rata')->filter();

        return [
            'kelompok' => $kelompok, 'rata_slot' => $rataSlot,
            'rata_us' => $vus->isEmpty() ? null : round($vus->avg(), 2),
            'rata_semua' => $vr->isEmpty() ? null : round($vr->avg(), 2),
            'ada' => $semua->isNotEmpty(),
        ];
    }
}
