<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PengaturanSekolah;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PengaturanSekolahController extends Controller
{
    public function edit(): View
    {
        return view('staf.pengaturan', ['p' => PengaturanSekolah::ambil()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:150'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'kota' => ['nullable', 'string', 'max:60'],
            'provinsi' => ['nullable', 'string', 'max:60'],
            'npsn' => ['nullable', 'string', 'max:20'],
            'kepala_sekolah' => ['nullable', 'string', 'max:100'],
            'nip_kepala_sekolah' => ['nullable', 'string', 'max:50'],
            'kurikulum' => ['required', 'string', 'max:60'],
            'mode_sinkron_eduweb' => ['required', 'boolean'],
        ]);

        PengaturanSekolah::ambil()->update($data);
        AuditLog::catat('pengaturan.ubah', 'mode_sinkron_eduweb='.$data['mode_sinkron_eduweb'], $request->user('web'));

        return back()->with('berhasil', 'Pengaturan disimpan.');
    }
}
