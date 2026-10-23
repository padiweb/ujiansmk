<?php

namespace App\Http\Controllers\Staf;

use App\Eduweb\KesalahanEduweb;
use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\Periode;
use App\Services\LayananWaliKelas;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Menu Wali Kelas: absensi, catatan, keputusan, dan finalisasi rapor kelas yang diwalikan. */
class WaliKelasController extends Controller
{
    public function __construct(private LayananWaliKelas $wali) {}

    public function index(Request $request): View
    {
        $periode = $this->periode($request);
        $kelas = $periode ? $this->wali->kelas($request->user('web'), $periode) : collect();

        return view('staf.wali.index', [
            'periode' => $periode,
            'daftarPeriode' => Periode::with('tahunAjaran')->orderByDesc('tahun_ajaran_id')->orderBy('semester')->get(),
            'admin' => $request->user('web')->role === 'admin',
            'kelas' => $kelas->map(fn (Kelas $k) => [
                'kelas' => $k,
                'siswa' => count($this->wali->baris($k, $periode)),
                'mapel' => $mapel = $this->wali->kelengkapan($k, $periode),
                'lengkap' => collect($mapel)->filter(fn ($m) => $m['siswa'] > 0 && $m['lengkap'] >= $m['siswa'])->count(),
                'final' => $this->wali->final($k, $periode),
            ]),
        ]);
    }

    public function show(Request $request, Kelas $kelas): View
    {
        $periode = $this->izinkan($request, $kelas);

        return view('staf.wali.show', [
            'periode' => $periode,
            'kelas' => $kelas->load('waliKelas:id,nama'),
            'mapel' => $this->wali->kelengkapan($kelas, $periode),
            'baris' => $this->wali->baris($kelas, $periode),
            'final' => $this->wali->final($kelas, $periode),
            'sudahPindah' => $this->wali->sudahPindah($kelas, $periode),
            'hambatan' => $this->wali->hambatanFinal($kelas, $periode),
            'admin' => $request->user('web')->role === 'admin',
            'keputusan' => LayananWaliKelas::KEPUTUSAN,
        ]);
    }

    public function simpan(Request $request, Kelas $kelas): RedirectResponse
    {
        $periode = $this->izinkan($request, $kelas);
        $request->validate(['rapor' => ['nullable', 'array'], 'rapor.*' => ['array']]);

        $h = $this->wali->simpan($request->user('web'), $kelas, $periode, $request->input('rapor', []));
        $pesan = "Data {$h['disimpan']} siswa disimpan.";

        return $h['dilewati_final'] > 0
            ? back()->with('catatan', "{$pesan} {$h['dilewati_final']} siswa sudah final dan tidak diubah.")
            : back()->with('berhasil', $pesan);
    }

    public function absensi(Request $request, Kelas $kelas): RedirectResponse
    {
        $periode = $this->izinkan($request, $kelas);

        try {
            $n = $this->wali->ambilAbsensi($request->user('web'), $kelas, $periode);
        } catch (DomainException|KesalahanEduweb $e) {
            return back()->with('gagal', $e->getMessage());
        }

        return back()->with($n > 0 ? 'berhasil' : 'catatan', $n > 0 ? "Absensi {$n} siswa diambil dari EduWeb." : 'EduWeb belum memiliki data absensi kelas ini pada rentang semester.');
    }

    public function finalkan(Request $request, Kelas $kelas): RedirectResponse
    {
        $periode = $this->izinkan($request, $kelas);

        try {
            $this->wali->finalkan($request->user('web'), $kelas, $periode);
        } catch (DomainException $e) {
            return back()->with('gagal', $e->getMessage());
        }

        return back()->with('berhasil', 'Rapor kelas '.$kelas->nama.' difinalkan. Nilai tidak dapat diubah lagi.');
    }

    public function bukaFinal(Request $request, Kelas $kelas): RedirectResponse
    {
        $periode = $this->izinkan($request, $kelas);
        $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:300']], [], ['alasan' => 'Alasan']);

        try {
            $this->wali->bukaFinal($request->user('web'), $kelas, $periode, $data['alasan']);
        } catch (DomainException $e) {
            return back()->with('gagal', $e->getMessage());
        }

        return back()->with('berhasil', 'Final rapor kelas '.$kelas->nama.' dibuka. Nilai dapat diubah kembali.');
    }

    private function izinkan(Request $request, Kelas $kelas): Periode
    {
        abort_unless($this->wali->boleh($request->user('web'), $kelas), 404);

        $periode = $this->periode($request, $kelas);
        abort_if(! $periode || ! $periode->rapor_di_sini || $periode->tahun_ajaran_id !== $kelas->tahun_ajaran_id, 404);

        return $periode;
    }

    private function periode(Request $request, ?Kelas $kelas = null): ?Periode
    {
        if ($request->filled('periode')) {
            return Periode::find((int) $request->input('periode'));
        }

        return Periode::where('aktif', true)->first()
            ?? ($kelas ? Periode::where('tahun_ajaran_id', $kelas->tahun_ajaran_id)->orderByDesc('id')->first() : Periode::orderByDesc('id')->first());
    }
}
