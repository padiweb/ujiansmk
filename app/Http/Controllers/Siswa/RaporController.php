<?php

namespace App\Http\Controllers\Siswa;

use App\Http\Controllers\Controller;
use App\Models\Periode;
use App\Models\RaporSiswa;
use App\Models\PengaturanSekolah;
use App\Services\LayananCetakRapor;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Siswa melihat rapornya sendiri; hanya semester yang sudah difinalkan wali kelas. */
class RaporController extends Controller
{
    public function __construct(private LayananCetakRapor $cetak) {}

    public function show(Request $request, Periode $periode): View
    {
        $siswa = $request->user('siswa');
        $l = $this->lembar($siswa, $periode);

        return view('siswa.rapor', ['siswa' => $siswa, 'periode' => $periode->load('tahunAjaran'), 'l' => $l]);
    }

    /** Unduh rapor sendiri sebagai PDF (hanya yang final dan dibuka untuk siswa; semester lampau tetap bisa selama masih dibuka). */
    public function pdf(Request $request, Periode $periode): Response
    {
        $siswa = $request->user('siswa');
        $l = $this->lembar($siswa, $periode);
        $sekolah = PengaturanSekolah::ambil();
        $tgl = now()->locale('id')->translatedFormat('j F Y');

        $html = view('staf.wali.rapor', [
            'lembar' => [$l], 'sekolah' => $sekolah, 'word' => false, 'pdf' => true,
            'kotaTanggal' => trim(($sekolah->kota ? $sekolah->kota.', ' : '').$tgl),
            'judul' => 'Rapor '.$siswa->nama.' - '.$periode->label(),
        ])->render();

        $opsi = new Options;
        $opsi->set('isRemoteEnabled', false); // tidak mengambil sumber dari luar
        $opsi->set('isPhpEnabled', false);
        $opsi->set('defaultFont', 'Times');
        $opsi->set('chroot', base_path('public'));
        $dompdf = new Dompdf($opsi);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([0, 0, 21.5 / 2.54 * 72, 33 / 2.54 * 72]); // F4, sama dengan cetak rapor wali kelas
        $dompdf->render();

        \App\Models\AuditLog::catat('rapor.unduh', "siswa {$siswa->id} periode {$periode->id}", $siswa);
        $nama = 'Rapor_'.Str::slug($siswa->nama, '_').'_'.Str::slug($periode->label(), '_').'.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nama.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string,mixed> */
    private function lembar($siswa, Periode $periode): array
    {
        $rapor = RaporSiswa::with('kelas')->where('siswa_id', $siswa->id)->where('periode_id', $periode->id)->where('status', 'final')->firstOrFail();
        abort_unless($periode->tampil_siswa, 404);

        return $this->cetak->data($rapor->kelas, $periode, $siswa->id)[0] ?? abort(404);
    }
}
