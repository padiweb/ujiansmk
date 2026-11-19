<?php

namespace App\Services;

use App\Models\Kelas;
use App\Models\Periode;
use App\Models\TahunAjaran;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Statistik nilai rapor satu semester: peringkat siswa per kelas, juara paralel per tingkat, dan rata-rata per mapel. */
class LayananStatistik
{
    /**
     * @return Collection<int,object{siswa_id:int,nama:string,nisn:?string,kelas_id:int,kelas:string,tingkat:string,rata:float,mapel:int}> urut dari rata-rata tertinggi
     */
    public function siswa(Periode $periode): Collection
    {
        return DB::table('nilai_rapor as n')
            ->join('siswa as s', 's.id', '=', 'n.siswa_id')
            ->join('kelas as k', 'k.id', '=', 'n.kelas_id')
            ->where('n.periode_id', $periode->id)->where('n.na', '>', 0)
            ->groupBy('n.siswa_id', 'n.kelas_id', 's.nama', 's.nisn', 'k.nama', 'k.tingkat')
            ->select('n.siswa_id', 's.nama', 's.nisn', 'n.kelas_id', 'k.nama as kelas', 'k.tingkat', DB::raw('round(avg(n.na), 2) as rata'), DB::raw('count(*) as mapel'))
            ->orderByDesc('rata')->orderBy('s.nama')->get()
            ->map(function ($r) {
                $r->rata = (float) $r->rata;

                return $r;
            });
    }

    /**
     * Peringkat Ujian Sekolah: siswa kelas XII pada satu tahun ajaran, diurutkan menurut rata-rata nilai akhir transkrip
     * (rata-rata semester rapor dan nilai US per mapel, rumus yang sama dengan transkrip dan SKL). Bentuk baris sama dengan siswa().
     *
     * @return Collection<int,object{siswa_id:int,nama:string,nisn:?string,kelas_id:int,kelas:string,tingkat:string,rata:float,mapel:int}>
     */
    public function siswaUs(TahunAjaran $tahun): Collection
    {
        $transkrip = app(LayananTranskrip::class);
        $baris = collect();

        foreach (Kelas::where('tahun_ajaran_id', $tahun->id)->where('tingkat', 'XII')->orderBy('nama')->get() as $kelas) {
            foreach ($transkrip->siswa($kelas) as $siswa) {
                $t = $transkrip->susun($siswa);
                if ($t['rata_semua'] === null) {
                    continue;
                }
                $baris->push((object) [
                    'siswa_id' => $siswa->id, 'nama' => $siswa->nama, 'nisn' => $siswa->nisn, 'kelas_id' => $kelas->id,
                    'kelas' => $kelas->nama, 'tingkat' => $kelas->tingkat, 'rata' => (float) $t['rata_semua'],
                    'mapel' => collect($t['kelompok'])->flatten(1)->count(),
                ]);
            }
        }

        return $baris->sortBy([['rata', 'desc'], ['nama', 'asc']])->values();
    }

    /** @return Collection<int,Collection> kelas_id => siswa terurut peringkat (dengan properti peringkat) */
    public function perKelas(Collection $siswa): Collection
    {
        return $siswa->groupBy('kelas_id')->map(fn (Collection $g) => $this->beriPeringkat($g->sortByDesc('rata')->values()));
    }

    /** Juara paralel: peringkat seluruh siswa satu tingkat lintas kelas. @return Collection<string,Collection> */
    public function perTingkat(Collection $siswa, int $batas = 10): Collection
    {
        return $siswa->groupBy('tingkat')->sortKeys()->map(fn (Collection $g) => $this->beriPeringkat($g->sortByDesc('rata')->values())->take($batas));
    }

    /** @return Collection<int,object{mapel:string,rata:float,tertinggi:int,terendah:int,siswa:int,tuntas:int,kkm:int}> */
    public function perMapel(Periode $periode, ?int $kelasId = null): Collection
    {
        return DB::table('nilai_rapor as n')->join('mapel as m', 'm.id', '=', 'n.mapel_id')
            ->where('n.periode_id', $periode->id)->where('n.na', '>', 0)->when($kelasId, fn ($q) => $q->where('n.kelas_id', $kelasId))
            ->groupBy('m.id', 'm.nama', 'm.kkm')
            ->select('m.nama as mapel', 'm.kkm', DB::raw('round(avg(n.na), 2) as rata'), DB::raw('max(n.na) as tertinggi'), DB::raw('min(n.na) as terendah'),
                DB::raw('count(*) as siswa'), DB::raw('sum(case when n.na >= m.kkm then 1 else 0 end) as tuntas'))
            ->orderBy('m.nama')->get();
    }

    /** Peringkat berbagi untuk nilai sama (1,2,2,4). */
    private function beriPeringkat(Collection $urut): Collection
    {
        $prev = null;
        $rank = 0;

        return $urut->map(function ($r, $i) use (&$prev, &$rank) {
            if ($prev === null || $r->rata !== $prev) {
                $rank = $i + 1;
            }
            $prev = $r->rata;
            $r->peringkat = $rank;

            return $r;
        });
    }
}
