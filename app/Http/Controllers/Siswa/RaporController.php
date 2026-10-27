<?php

namespace App\Http\Controllers\Siswa;

use App\Http\Controllers\Controller;
use App\Models\Periode;
use App\Models\RaporSiswa;
use App\Services\LayananCetakRapor;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Siswa melihat rapornya sendiri; hanya semester yang sudah difinalkan wali kelas. */
class RaporController extends Controller
{
    public function __construct(private LayananCetakRapor $cetak) {}

    public function show(Request $request, Periode $periode): View
    {
        $siswa = $request->user('siswa');

        $rapor = RaporSiswa::with('kelas')->where('siswa_id', $siswa->id)->where('periode_id', $periode->id)->where('status', 'final')->firstOrFail();
        abort_unless($periode->tampil_siswa, 404);
        $l = $this->cetak->data($rapor->kelas, $periode, $siswa->id)[0] ?? abort(404);

        return view('siswa.rapor', ['siswa' => $siswa, 'periode' => $periode->load('tahunAjaran'), 'l' => $l]);
    }
}
