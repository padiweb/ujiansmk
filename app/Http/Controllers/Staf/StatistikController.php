<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\PengaturanSekolah;
use App\Models\Periode;
use App\Models\TahunAjaran;
use App\Services\LayananStatistik;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Statistik nilai rapor semester: peringkat kelas, juara paralel, dan rata-rata mapel. Hanya admin. */
class StatistikController extends Controller
{
    public function __construct(private LayananStatistik $statistik) {}

    public function index(Request $request): View
    {
        if ($request->input('jenis') === 'us') {
            return $this->indexUs($request);
        }

        $daftarPeriode = Periode::with('tahunAjaran')->where('tipe', 'reguler')->orderByDesc('tahun_ajaran_id')->orderBy('semester')->get();
        $periode = $daftarPeriode->firstWhere('id', (int) $request->input('periode')) ?? $daftarPeriode->firstWhere('aktif', true) ?? $daftarPeriode->first();
        $siswa = $periode ? $this->statistik->siswa($periode) : collect();
        $perKelas = $this->statistik->perKelas($siswa);
        $kelasId = (int) $request->input('kelas');
        $kelasPilihan = $perKelas->has($kelasId) ? $kelasId : null;

        return view('staf.statistik.index', [
            'jenis' => 'rapor', 'keterangan' => 'Semester: '.($periode?->label() ?? '–'), 'daftarTahun' => collect(), 'tahun' => null,
            'cetakParam' => $periode ? ['periode' => $periode->id] : [],
            'daftarPeriode' => $daftarPeriode, 'periode' => $periode, 'siswa' => $siswa,
            'perKelas' => $kelasPilihan ? $perKelas->only([$kelasPilihan]) : $perKelas,
            'daftarKelas' => Kelas::whereIn('id', $perKelas->keys())->orderBy('tingkat')->orderBy('nama')->get(), 'kelasId' => $kelasPilihan,
            'perTingkat' => $this->statistik->perTingkat($siswa),
            'perMapel' => $periode ? $this->statistik->perMapel($periode, $kelasPilihan) : collect(),
            'kelasNama' => Kelas::whereIn('id', $perKelas->keys())->pluck('nama', 'id'),
        ]);
    }

    /** Peringkat Ujian Sekolah kelas XII per tahun ajaran (rata-rata nilai akhir transkrip). */
    private function indexUs(Request $request): View
    {
        $daftarTahun = TahunAjaran::whereHas('kelas', fn ($q) => $q->where('tingkat', 'XII'))->orderByDesc('nama')->get();
        $tahun = $daftarTahun->firstWhere('id', (int) $request->input('tahun')) ?? $daftarTahun->firstWhere('aktif', true) ?? $daftarTahun->first();
        $siswa = $tahun ? $this->statistik->siswaUs($tahun) : collect();
        $perKelas = $this->statistik->perKelas($siswa);
        $kelasId = (int) $request->input('kelas');
        $kelasPilihan = $perKelas->has($kelasId) ? $kelasId : null;

        return view('staf.statistik.index', [
            'jenis' => 'us', 'keterangan' => 'Ujian Sekolah kelas XII · '.($tahun ? 'Tahun ajaran '.$tahun->nama : '–'),
            'daftarTahun' => $daftarTahun, 'tahun' => $tahun, 'cetakParam' => $tahun ? ['jenis' => 'us', 'tahun' => $tahun->id] : [],
            'daftarPeriode' => collect(), 'periode' => null, 'siswa' => $siswa,
            'perKelas' => $kelasPilihan ? $perKelas->only([$kelasPilihan]) : $perKelas,
            'daftarKelas' => Kelas::whereIn('id', $perKelas->keys())->orderBy('nama')->get(), 'kelasId' => $kelasPilihan,
            'perTingkat' => $this->statistik->perTingkat($siswa), 'perMapel' => collect(),
            'kelasNama' => Kelas::whereIn('id', $perKelas->keys())->pluck('nama', 'id'),
        ]);
    }

    /** Daftar peringkat satu kelas siap cetak (F4). */
    public function cetak(Request $request, Kelas $kelas)
    {
        if ($request->input('jenis') === 'us') {
            abort_unless($kelas->tingkat === 'XII', 404);
            $tahun = TahunAjaran::findOrFail((int) $request->input('tahun'));
            abort_unless($kelas->tahun_ajaran_id === $tahun->id, 404);
            $baris = $this->statistik->perKelas($this->statistik->siswaUs($tahun))->get($kelas->id, collect());
            $label = 'Ujian Sekolah · Tahun ajaran '.$tahun->nama;
        } else {
            $periode = Periode::with('tahunAjaran')->where('tipe', 'reguler')->findOrFail((int) $request->input('periode'));
            $baris = $this->statistik->perKelas($this->statistik->siswa($periode))->get($kelas->id, collect());
            $label = $periode->label();
        }
        abort_if($baris->isEmpty(), 404);

        return response(view('staf.statistik.cetak', ['kelas' => $kelas, 'label' => $label, 'baris' => $baris, 'sekolah' => PengaturanSekolah::ambil()])->render());
    }
}
