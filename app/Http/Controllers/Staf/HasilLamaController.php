<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\HasilUjianLama;
use App\Models\Periode;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Arsip hasil ujian CBT dari aplikasi lama: hanya dibaca, khusus admin. */
class HasilLamaController extends Controller
{
    public function index(Request $request): View
    {
        $periode = (int) $request->input('periode');
        $mapel = trim((string) $request->input('mapel'));
        $kelas = trim((string) $request->input('kelas'));
        $q = trim((string) $request->input('q'));

        $daftarPeriode = Periode::with('tahunAjaran')->whereIn('id', HasilUjianLama::select('periode_id'))->get()
            ->sortByDesc(fn ($p) => $p->tahunAjaran->nama.$p->semester)->values();
        $daftarMapel = HasilUjianLama::query()->when($periode > 0, fn ($w) => $w->where('periode_id', $periode))
            ->distinct()->orderBy('mapel_nama')->pluck('mapel_nama');
        $daftarKelas = HasilUjianLama::query()->when($periode > 0, fn ($w) => $w->where('periode_id', $periode))
            ->whereNotNull('kelas_nama')->distinct()->orderBy('kelas_nama')->pluck('kelas_nama');

        $hasil = HasilUjianLama::query()->with(['siswa', 'periode.tahunAjaran'])
            ->when($periode > 0, fn ($w) => $w->where('periode_id', $periode))
            ->when($mapel !== '', fn ($w) => $w->where('mapel_nama', $mapel))
            ->when($kelas !== '', fn ($w) => $w->where('kelas_nama', $kelas))
            ->when($q !== '', fn ($w) => $w->whereHas('siswa', fn ($s) => $s->where('nama', 'like', "%{$q}%")->orWhere('nisn', 'like', "%{$q}%")))
            ->orderByDesc('selesai_pada')->orderBy('id')
            ->paginate(50)->withQueryString();

        return view('staf.hasil-lama.index', compact('hasil', 'daftarPeriode', 'daftarMapel', 'daftarKelas', 'periode', 'mapel', 'kelas', 'q'));
    }
}
