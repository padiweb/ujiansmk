<?php

namespace App\Http\Controllers\Staf;

use App\Eduweb\KesalahanEduweb;
use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\Periode;
use App\Services\LayananCetakRapor;
use App\Services\LayananWaliKelas;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/** Menu Wali Kelas: absensi, catatan, keputusan, dan finalisasi rapor kelas yang diwalikan. */
class WaliKelasController extends Controller
{
    public function __construct(private LayananWaliKelas $wali, private LayananCetakRapor $cetak) {}

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
            'siswaFinal' => $this->cetak->siswaFinal($kelas, $periode),
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

        $pesan = $n > 0 ? "Absensi {$n} siswa diambil dari EduWeb." : 'EduWeb belum memiliki data absensi kelas ini pada rentang semester.';

        // Ekskul diambil sekalian; kegagalannya (mis. kunci belum punya lingkup nilai) tidak membatalkan absensi.
        try {
            $e = $this->wali->ambilEkskul($request->user('web'), $kelas, $periode);
            $pesan .= $e > 0 ? " Ekskul {$e} siswa diambil." : ' EduWeb belum memiliki nilai ekskul semester ini.';
        } catch (DomainException|KesalahanEduweb $ex) {
            return back()->with('catatan', $pesan.' Ekskul belum bisa diambil: '.$ex->getMessage());
        }

        return back()->with($n > 0 ? 'berhasil' : 'catatan', $pesan);
    }

    /**
     * Dipanggil berkala oleh halaman (tanpa klik): menyegarkan absensi/ekskul dari EduWeb paling sering sekali per 15 detik
     * per kelas, lalu mengembalikan absensi terkini dari database (isian manual wali tidak disentuh).
     */
    public function segarkan(Request $request, Kelas $kelas): JsonResponse
    {
        $periode = $this->izinkan($request, $kelas);
        if ($this->wali->final($kelas, $periode)) {
            return response()->json(['status' => 'final', 'pesan' => null, 'data' => []]);
        }

        $kunci = "wali-segar:{$kelas->id}:{$periode->id}";
        $galat = Cache::get($kunci.':galat');
        $data = null;

        if (Cache::add($kunci, now()->timestamp, 15)) {
            try {
                $data = $this->wali->segarkan($request->user('web'), $kelas, $periode);
                Cache::forget($kunci.':galat');
                $galat = null;
            } catch (DomainException|KesalahanEduweb $e) {
                $galat = $e->getMessage();
                Cache::put($kunci.':galat', $galat, 60);
            }
        }

        return response()->json([
            'status' => $galat ? 'galat' : 'ok', 'pesan' => $galat, 'waktu' => now()->format('H:i:s'),
            'data' => $data ?? $this->wali->absensiTerkini($kelas, $periode),
        ]);
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
