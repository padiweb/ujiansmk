<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Kelas;
use App\Models\PengaturanSekolah;
use App\Models\Periode;
use App\Services\LayananCetakRapor;
use App\Services\LayananWaliKelas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Cetak rapor (halaman cetak peramban atau berkas Word) untuk siswa yang rapornya sudah final. */
class CetakRaporController extends Controller
{
    public function __construct(private LayananWaliKelas $wali, private LayananCetakRapor $cetak) {}

    public function __invoke(Request $request, Kelas $kelas): Response|RedirectResponse
    {
        abort_unless($this->wali->boleh($request->user('web'), $kelas), 404);

        $input = $request->validate([
            'periode' => ['required', 'integer'],
            'siswa' => ['nullable', 'integer'],
            'tgl' => ['nullable', 'date'],
            'format' => ['nullable', 'in:cetak,word'],
        ], [], ['tgl' => 'Tanggal']);

        $periode = Periode::find($input['periode']);
        abort_if(! $periode || ! $periode->rapor_di_sini || $periode->tahun_ajaran_id !== $kelas->tahun_ajaran_id, 404);

        $data = $this->cetak->data($kelas, $periode, $input['siswa'] ?? null);
        if ($data === []) {
            return back()->with('gagal', 'Belum ada rapor final yang bisa dicetak. Finalkan rapor kelas ini lebih dulu.');
        }

        $sekolah = PengaturanSekolah::ambil();
        $tgl = Carbon::parse($input['tgl'] ?? now())->locale('id')->translatedFormat('j F Y');
        $word = ($input['format'] ?? 'cetak') === 'word';

        AuditLog::catat('rapor.cetak', "kelas {$kelas->id} periode {$periode->id}: ".count($data).' lembar'.($word ? ' (Word)' : ''), $request->user('web'));

        $isi = view('staf.wali.rapor', [
            'lembar' => $data, 'sekolah' => $sekolah, 'word' => $word,
            'kotaTanggal' => trim(($sekolah->kota ? $sekolah->kota.', ' : '').$tgl),
            'judul' => 'Rapor '.$kelas->nama.' — '.$periode->label(),
        ])->render();

        $respons = response($isi);
        if ($word) {
            $nama = 'Rapor_'.Str::slug($kelas->nama, '_').'_'.now()->format('Ymd_His').'.doc';
            $respons->header('Content-Type', 'application/msword; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="'.$nama.'"');
        }

        return $respons;
    }
}
