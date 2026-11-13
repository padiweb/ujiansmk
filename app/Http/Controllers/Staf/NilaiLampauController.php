<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Periode;
use App\Services\LayananNilaiLampau;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Menu Nilai Lampau: mengisi NP dan NK semester lama yang tidak pernah diisi lewat aplikasi ini. */
class NilaiLampauController extends Controller
{
    public function __construct(private LayananNilaiLampau $lampau) {}

    public function index(Request $request): View
    {
        $user = $request->user('web');
        $daftarPeriode = $this->lampau->daftarPeriode();
        $periode = $daftarPeriode->firstWhere('id', (int) $request->input('periode')) ?? $daftarPeriode->first();
        $daftarKelas = $periode ? $this->lampau->daftarKelas($periode) : collect();
        $kelas = $daftarKelas->firstWhere('id', (int) $request->input('kelas'));
        $daftarMapel = $this->lampau->daftarMapel($user);
        $mapel = $daftarMapel->firstWhere('id', (int) $request->input('mapel'));

        return view('staf.lampau.index', [
            'daftarPeriode' => $daftarPeriode, 'periode' => $periode,
            'daftarKelas' => $daftarKelas, 'kelas' => $kelas,
            'daftarMapel' => $daftarMapel, 'mapel' => $mapel,
            'baris' => $periode && $kelas && $mapel ? $this->lampau->tabel($mapel, $kelas, $periode) : [],
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $request->validate([
            'periode' => ['required', 'integer'], 'kelas' => ['required', 'integer'], 'mapel' => ['required', 'integer'],
            'nilai' => ['nullable', 'array'], 'nilai.*' => ['array'],
        ]);
        $periode = Periode::findOrFail((int) $request->input('periode'));
        $kelas = Kelas::findOrFail((int) $request->input('kelas'));
        $mapel = Mapel::findOrFail((int) $request->input('mapel'));
        abort_unless($this->lampau->boleh($request->user('web'), $mapel), 404);

        try {
            $h = $this->lampau->simpan($request->user('web'), $mapel, $kelas, $periode, $request->input('nilai', []));
        } catch (DomainException $e) {
            return back()->with('gagal', $e->getMessage());
        }

        $pesan = "Nilai {$h['disimpan']} siswa disimpan.".($h['salah'] > 0 ? " {$h['salah']} baris dilewati karena bukan angka 0–100." : '');

        return back()->with($h['salah'] > 0 ? 'catatan' : 'berhasil', $pesan);
    }
}
