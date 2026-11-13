<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\PengaturanSekolah;
use App\Models\Periode;
use App\Services\LayananStatistik;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Statistik nilai rapor semester: peringkat kelas, juara paralel, dan rata-rata mapel. Hanya admin. */
class StatistikController extends Controller
{
    public function __construct(private LayananStatistik $statistik) {}

    public function index(Request $request): View
    {
        $daftarPeriode = Periode::with('tahunAjaran')->where('tipe', 'reguler')->orderByDesc('tahun_ajaran_id')->orderBy('semester')->get();
        $periode = $daftarPeriode->firstWhere('id', (int) $request->input('periode')) ?? $daftarPeriode->firstWhere('aktif', true) ?? $daftarPeriode->first();
        $siswa = $periode ? $this->statistik->siswa($periode) : collect();
        $perKelas = $this->statistik->perKelas($siswa);
        $kelasId = (int) $request->input('kelas');
        $kelasPilihan = $perKelas->has($kelasId) ? $kelasId : null;

        return view('staf.statistik.index', [
            'daftarPeriode' => $daftarPeriode, 'periode' => $periode, 'siswa' => $siswa,
            'perKelas' => $kelasPilihan ? $perKelas->only([$kelasPilihan]) : $perKelas,
            'daftarKelas' => Kelas::whereIn('id', $perKelas->keys())->orderBy('tingkat')->orderBy('nama')->get(), 'kelasId' => $kelasPilihan,
            'perTingkat' => $this->statistik->perTingkat($siswa),
            'perMapel' => $periode ? $this->statistik->perMapel($periode, $kelasPilihan) : collect(),
            'kelasNama' => Kelas::whereIn('id', $perKelas->keys())->pluck('nama', 'id'),
        ]);
    }

    /** Daftar peringkat satu kelas siap cetak (F4). */
    public function cetak(Request $request, Kelas $kelas)
    {
        $periode = Periode::with('tahunAjaran')->where('tipe', 'reguler')->findOrFail((int) $request->input('periode'));
        $baris = $this->statistik->perKelas($this->statistik->siswa($periode))->get($kelas->id, collect());
        abort_if($baris->isEmpty(), 404);

        return response(view('staf.statistik.cetak', ['kelas' => $kelas, 'periode' => $periode, 'baris' => $baris, 'sekolah' => PengaturanSekolah::ambil()])->render());
    }
}
