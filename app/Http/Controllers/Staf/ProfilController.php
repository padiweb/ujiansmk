<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\GambarAman;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/** Profil akun staf: foto yang tampil di bilah atas. Setiap pengguna hanya mengelola dan melihat fotonya sendiri. */
class ProfilController extends Controller
{
    public function tampil(Request $request): View
    {
        return view('staf.profil', ['pengguna' => $request->user('web')]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $pengguna = $request->user('web');

        if ($request->boolean('hapus')) {
            GambarAman::hapus($pengguna->foto);
            $pengguna->update(['foto' => null]);
            AuditLog::catat('profil.foto_hapus', "user {$pengguna->id}", $pengguna);

            return redirect()->route('staf.profil')->with('berhasil', 'Foto profil dihapus.');
        }

        $request->validate(
            ['foto' => ['required', 'file', 'max:5120']],
            ['foto.required' => 'Pilih foto terlebih dahulu.', 'foto.max' => 'Ukuran foto maksimal 5 MB.', 'foto.file' => 'Berkas tidak valid.'],
        );

        $baru = GambarAman::simpanPersegi($request->file('foto'));
        $lama = $pengguna->foto;
        $pengguna->update(['foto' => $baru]);
        GambarAman::hapus($lama);
        AuditLog::catat('profil.foto_ubah', "user {$pengguna->id}", $pengguna);

        return redirect()->route('staf.profil')->with('berhasil', 'Foto profil disimpan.');
    }

    public function foto(Request $request)
    {
        $foto = $request->user('web')->foto;
        abort_unless($foto && Storage::disk('local')->exists($foto), 404);

        return Storage::disk('local')->response($foto, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
