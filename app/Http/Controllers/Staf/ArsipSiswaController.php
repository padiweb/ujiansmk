<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\RaporSiswa;
use App\Models\Siswa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Arsip Siswa: siswa yang sudah lulus atau keluar. Hanya dibaca; rapor final, transkrip, SKL, dan surat mutasi tetap bisa dicetak. */
class ArsipSiswaController extends Controller
{
    public function index(Request $request): View
    {
        $status = in_array($request->input('status'), ['lulus', 'keluar'], true) ? $request->input('status') : 'lulus';
        $q = trim((string) $request->input('q'));
        $kelasId = (int) $request->input('kelas');

        $kueri = Siswa::query()->where('status', $status)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('nama', 'like', "%{$q}%")->orWhere('nisn', 'like', "%{$q}%")->orWhere('nis', 'like', "%{$q}%")))
            ->when($kelasId > 0, fn ($w) => $w->whereHas('kelas', fn ($k) => $k->where('kelas.id', $kelasId)))
            ->with('kelas.tahunAjaran')->orderBy('nama');
        $siswa = $kueri->paginate(40)->withQueryString();

        $rapor = RaporSiswa::with('periode.tahunAjaran')->where('status', 'final')->whereIn('siswa_id', $siswa->pluck('id'))->get()->groupBy('siswa_id');

        return view('staf.arsip.index', [
            'siswa' => $siswa, 'status' => $status, 'q' => $q, 'kelasId' => $kelasId, 'rapor' => $rapor,
            'daftarKelas' => Kelas::with('tahunAjaran')->whereIn('id', DB::table('keanggotaan_kelas')->whereIn('siswa_id', Siswa::whereIn('status', ['lulus', 'keluar'])->select('id'))->select('kelas_id'))
                ->orderByDesc('tahun_ajaran_id')->orderBy('nama')->get(),
        ]);
    }
}
