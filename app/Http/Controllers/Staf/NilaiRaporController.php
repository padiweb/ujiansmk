<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Periode;
use App\Services\LayananNilaiRapor;
use App\Services\LayananRapor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Menu Input Nilai: guru mapel mengisi nilai rapor per mapel dan kelas yang diampunya. */
class NilaiRaporController extends Controller
{
    public function __construct(private LayananNilaiRapor $nilai, private LayananRapor $rapor) {}

    public function index(Request $request): View
    {
        $periode = $this->periode($request);

        return view('staf.nilai.index', [
            'periode' => $periode,
            'daftarPeriode' => Periode::with('tahunAjaran')->orderByDesc('tahun_ajaran_id')->orderBy('semester')->get(),
            'pasangan' => $periode ? $this->nilai->pasangan($request->user('web'), $periode) : collect(),
            'admin' => $request->user('web')->role === 'admin',
        ]);
    }

    public function show(Request $request, Mapel $mapel, Kelas $kelas): View
    {
        $periode = $this->izinkan($request, $mapel, $kelas);
        $bobot = $this->rapor->bobot();

        return view('staf.nilai.tabel', [
            'periode' => $periode,
            'mapel' => $mapel,
            'kelas' => $kelas,
            'baris' => $this->nilai->tabel($mapel, $kelas, $periode),
            'aktif' => $this->rapor->komponenAktif($bobot),
            'bobot' => $bobot,
            'skala' => $this->rapor->skala()->map(fn ($s) => ['min' => $s->nilai_min, 'p' => $s->predikat])->values(),
            'label' => LayananRapor::LABEL,
        ]);
    }

    public function simpan(Request $request, Mapel $mapel, Kelas $kelas): RedirectResponse
    {
        $periode = $this->izinkan($request, $mapel, $kelas);
        $request->validate(['nilai' => ['nullable', 'array'], 'nilai.*' => ['array']]);

        $h = $this->nilai->simpan($request->user('web'), $mapel, $kelas, $periode, $request->input('nilai', []));
        $pesan = "Nilai {$h['disimpan']} siswa disimpan.";
        if ($h['dilewati_final'] > 0) {
            return back()->with('catatan', "{$pesan} {$h['dilewati_final']} siswa sudah final dan tidak diubah.");
        }

        return back()->with('berhasil', $pesan);
    }

    public function ambilUlang(Request $request, Mapel $mapel, Kelas $kelas): RedirectResponse
    {
        $periode = $this->izinkan($request, $mapel, $kelas);
        $n = $this->nilai->ambilUlangUjian($request->user('web'), $mapel, $kelas, $periode);

        return back()->with($n > 0 ? 'berhasil' : 'catatan', $n > 0 ? "Nilai Ujian {$n} siswa dikembalikan ke hasil ujian." : 'Tidak ada nilai Ujian otomatis yang bisa diambil. Pastikan nilai ujian kelas ini sudah dikunci.');
    }

    private function izinkan(Request $request, Mapel $mapel, Kelas $kelas): Periode
    {
        abort_unless($this->nilai->boleh($request->user('web'), $mapel, $kelas), 404);

        $periode = $this->periode($request, $kelas);
        abort_if(! $periode || $periode->tahun_ajaran_id !== $kelas->tahun_ajaran_id, 404);

        return $periode;
    }

    /** Semester dari ?periode=, bawaan: yang aktif (atau yang tahun ajarannya sama dengan kelas). */
    private function periode(Request $request, ?Kelas $kelas = null): ?Periode
    {
        if ($request->filled('periode')) {
            return Periode::find((int) $request->input('periode'));
        }

        return Periode::where('aktif', true)->first()
            ?? ($kelas ? Periode::where('tahun_ajaran_id', $kelas->tahun_ajaran_id)->orderByDesc('id')->first() : Periode::orderByDesc('id')->first());
    }
}
