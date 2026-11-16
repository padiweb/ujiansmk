<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\BankSoal;
use App\Models\Kelas;
use App\Models\Pengampu;
use App\Models\TahunAjaran;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pemantauan admin: guru dan mapel yang belum membuat bank soal.
 * Dasarnya pengampu tahun ajaran aktif (guru, mapel, tingkat); bank soal dicocokkan lewat pembuat, mapel, dan tingkat.
 */
class KelengkapanBankController extends Controller
{
    private const STATUS = [
        'belum' => 'Belum ada bank',
        'kosong' => 'Bank masih kosong',
        'lain' => 'Ada bank dari pengguna lain',
        'sendiri' => 'Sudah ada soal',
    ];

    public function index(Request $request): View
    {
        $tahun = TahunAjaran::aktif();
        $status = (string) $request->query('status');
        $tingkat = (string) $request->query('tingkat');
        $q = mb_strtolower(trim((string) $request->query('q')));

        $baris = collect();
        if ($tahun) {
            $banks = BankSoal::query()->withCount('soal')->get();
            $perPembuat = $banks->groupBy(fn ($b) => $b->dibuat_oleh.'|'.$b->mapel_id.'|'.$b->tingkat);
            $perMapel = $banks->groupBy(fn ($b) => $b->mapel_id.'|'.$b->tingkat);

            $baris = Pengampu::with(['guru:id,nama', 'mapel:id,nama', 'kelas:id,nama,tingkat,tahun_ajaran_id'])
                ->whereHas('kelas', fn ($k) => $k->where('tahun_ajaran_id', $tahun->id))
                ->whereHas('guru')->whereHas('mapel')
                ->get()
                ->groupBy(fn ($p) => $p->guru_id.'|'.$p->mapel_id.'|'.$p->kelas->tingkat)
                ->map(function ($grup, $kunci) use ($perPembuat, $perMapel) {
                    $p = $grup->first();
                    $tk = $p->kelas->tingkat;
                    $sendiri = $perPembuat->get($p->guru_id.'|'.$p->mapel_id.'|'.$tk, collect());
                    $lain = $perMapel->get($p->mapel_id.'|'.$tk, collect())->where('dibuat_oleh', '!=', $p->guru_id);
                    $soalSendiri = (int) $sendiri->sum('soal_count');
                    $soalLain = (int) $lain->sum('soal_count');

                    $status = match (true) {
                        $soalSendiri > 0 => 'sendiri',
                        $sendiri->isNotEmpty() => 'kosong',
                        $soalLain > 0 => 'lain',
                        default => 'belum',
                    };

                    return [
                        'guru' => $p->guru->nama, 'mapel' => $p->mapel->nama, 'tingkat' => $tk,
                        'kelas' => $grup->pluck('kelas.nama')->unique()->sort()->values()->all(),
                        'status' => $status, 'soal' => $status === 'lain' ? $soalLain : $soalSendiri,
                    ];
                })->values();
        }

        $ringkas = collect(self::STATUS)->map(fn ($label, $k) => $baris->where('status', $k)->count());

        $tampil = $baris
            ->when(isset(self::STATUS[$status]), fn ($c) => $c->where('status', $status))
            ->when(in_array($tingkat, Kelas::TINGKAT, true), fn ($c) => $c->where('tingkat', $tingkat))
            ->when($q !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['guru'].' '.$r['mapel']), $q)))
            ->sortBy([
                fn ($a, $b) => array_search($a['status'], array_keys(self::STATUS)) <=> array_search($b['status'], array_keys(self::STATUS)),
                fn ($a, $b) => strcasecmp($a['guru'], $b['guru']),
                fn ($a, $b) => strcasecmp($a['mapel'], $b['mapel']),
            ])->values();

        return view('staf.bank-soal.kelengkapan', [
            'tahun' => $tahun, 'baris' => $tampil, 'ringkas' => $ringkas, 'status' => $status, 'tingkat' => $tingkat, 'q' => $q,
            'labelStatus' => self::STATUS, 'total' => $baris->count(),
        ]);
    }
}
