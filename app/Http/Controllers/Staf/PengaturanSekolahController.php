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
            'nama_induk' => ['nullable', 'string', 'max:150'],
            'alamat' => ['nullable', 'string', 'max:500'],
            'telepon' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:120'],
            'kota' => ['nullable', 'string', 'max:60'],
            'provinsi' => ['nullable', 'string', 'max:60'],
            'npsn' => ['nullable', 'string', 'max:20'],
            'kepala_sekolah' => ['nullable', 'string', 'max:100'],
            'nip_kepala_sekolah' => ['nullable', 'string', 'max:50'],
            'kurikulum' => ['required', 'string', 'max:60'],
            'mode_sinkron_eduweb' => ['sometimes', 'boolean'],
        ]);

        // Hanya bisa diubah lewat halaman ini saat MODE_PENGEMBANG aktif; selain itu nilainya tidak disentuh.
        if (! config('ujian.mode_pengembang')) {
            unset($data['mode_sinkron_eduweb']);
        }

        PengaturanSekolah::ambil()->update($data);
        AuditLog::catat('pengaturan.ubah', isset($data['mode_sinkron_eduweb']) ? 'mode_sinkron_eduweb='.$data['mode_sinkron_eduweb'] : 'data sekolah', $request->user('web'));

        return back()->with('berhasil', 'Pengaturan disimpan.');
    }
}
