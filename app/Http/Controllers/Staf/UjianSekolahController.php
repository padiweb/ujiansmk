<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Services\LayananUjianSekolah;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Menu Input Nilai US: guru mapel mengisi nilai Ujian Sekolah kelas XII untuk mapel yang diampunya. */
class UjianSekolahController extends Controller
{
    public function __construct(private LayananUjianSekolah $us) {}

    public function index(Request $request): View
    {
        return view('staf.us.index', [
            'pasangan' => $this->us->pasangan($request->user('web')),
            'periodeUs' => $this->us->periodeUs(),
            'admin' => $request->user('web')->role === 'admin',
        ]);
    }

    public function show(Request $request, Mapel $mapel, Kelas $kelas): View
    {
        abort_unless($this->us->boleh($request->user('web'), $mapel, $kelas), 404);

        return view('staf.us.tabel', ['mapel' => $mapel, 'kelas' => $kelas, 'periodeUs' => $this->us->periodeUs()] + $this->us->tabel($mapel, $kelas));
    }

    public function simpan(Request $request, Mapel $mapel, Kelas $kelas): RedirectResponse
    {
        abort_unless($this->us->boleh($request->user('web'), $mapel, $kelas), 404);
        $request->validate(['nilai' => ['nullable', 'array'], 'nilai.*' => ['array'], 'masuk_xii2' => ['nullable', 'boolean']]);

        $h = $this->us->simpan($request->user('web'), $mapel, $kelas, $request->input('nilai', []), $request->boolean('masuk_xii2'));
        $pesan = "Nilai US {$h['disimpan']} siswa disimpan.".($h['salah'] > 0 ? " {$h['salah']} baris dilewati karena bukan angka 0–100." : '');

        return back()->with($h['salah'] > 0 ? 'catatan' : 'berhasil', $pesan);
    }
}
