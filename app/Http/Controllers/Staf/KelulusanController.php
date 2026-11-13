<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Kelas;
use App\Models\PengaturanSekolah;
use App\Models\Siswa;
use App\Models\Periode;
use App\Services\LayananTranskrip;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Transkrip nilai dan Surat Keterangan Lulus (SKL) siswa kelas XII. Hanya admin. */
class KelulusanController extends Controller
{
    private const JENIS = ['transkrip' => 'Transkrip US', 'skl' => 'Cetak SKL'];

    public function __construct(private LayananTranskrip $transkrip) {}

    public function daftar(Request $request, string $jenis): View
    {
        abort_unless(isset(self::JENIS[$jenis]), 404);

        $tahun = Periode::where('aktif', true)->value('tahun_ajaran_id') ?? Kelas::max('tahun_ajaran_id');
        $daftarKelas = Kelas::where('tahun_ajaran_id', $tahun)->where('tingkat', 'XII')->orderBy('nama')->get();
        $kelas = $daftarKelas->firstWhere('id', (int) $request->input('kelas')) ?? $daftarKelas->first();

        return view('staf.kelulusan.daftar', [
            'jenis' => $jenis, 'judul' => self::JENIS[$jenis], 'daftarKelas' => $daftarKelas, 'kelas' => $kelas,
            'siswa' => $kelas ? Siswa::whereIn('id', $this->transkrip->siswa($kelas)->pluck('id'))->orderBy('nama')->get() : collect(),
        ]);
    }

    public function cetak(Request $request, string $jenis): Response
    {
        abort_unless(isset(self::JENIS[$jenis]), 404);
        $in = $request->validate([
            'kelas' => ['required', 'integer'], 'siswa' => ['nullable', 'integer'],
            'no_surat' => ['nullable', 'string', 'max:80'], 'tgl' => ['nullable', 'date'], 'tgl_lulus' => ['nullable', 'date'],
            'no_ijazah' => ['nullable', 'string', 'max:60'], 'format' => ['nullable', 'in:cetak,word'],
        ], [], ['tgl' => 'Tanggal']);

        $kelas = Kelas::with('jurusan', 'tahunAjaran')->where('tingkat', 'XII')->findOrFail((int) $in['kelas']);
        $siswa = $this->transkrip->siswa($kelas);
        if (! empty($in['siswa'])) {
            $siswa = $siswa->where('id', (int) $in['siswa'])->values();
        }
        abort_if($siswa->isEmpty(), 404);

        $lembar = Siswa::whereIn('id', $siswa->pluck('id'))->orderBy('nama')->get()
            ->map(fn (Siswa $s) => ['siswa' => $s] + $this->transkrip->susun($s))->all();

        $sekolah = PengaturanSekolah::ambil();
        $tanggal = fn (?string $t) => $t ? Carbon::parse($t)->locale('id')->translatedFormat('j F Y') : null;
        $tgl = $tanggal($in['tgl'] ?? now()->toDateString());
        $word = ($in['format'] ?? 'cetak') === 'word';

        AuditLog::catat($jenis.'.cetak', "kelas {$kelas->id}: ".count($lembar).' lembar'.($word ? ' (Word)' : ''), $request->user('web'));

        $isi = view('staf.kelulusan.'.$jenis, [
            'lembar' => $lembar, 'kelas' => $kelas, 'sekolah' => $sekolah, 'word' => $word, 'slot' => LayananTranskrip::SLOT, 'namaKelompok' => LayananTranskrip::KELOMPOK,
            'noSurat' => $in['no_surat'] ?? '', 'noIjazah' => $in['no_ijazah'] ?? '',
            'kotaTanggal' => trim(($sekolah->kota ? $sekolah->kota.', ' : '').$tgl),
            'tglLulus' => $tanggal($in['tgl_lulus'] ?? null) ?? $tgl,
            'judul' => self::JENIS[$jenis].' '.$kelas->nama,
        ])->render();

        $respons = response($isi);
        if ($word) {
            $nama = ucfirst($jenis).'_'.Str::slug($kelas->nama, '_').'_'.now()->format('Ymd_His').'.doc';
            $respons->header('Content-Type', 'application/msword; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="'.$nama.'"');
        }

        return $respons;
    }
}
