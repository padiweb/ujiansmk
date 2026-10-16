<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Siswa;
use App\Support\PercobaanLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MasukSiswaController extends Controller
{
    private const GUARD = 'siswa';

    private const HASH_PALSU = '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    public function tampil(): View
    {
        return view('auth.masuk-siswa');
    }

    public function masuk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:200'],
        ], [
            'username.required' => 'Nama pengguna wajib diisi.',
            'password.required' => 'Kata sandi wajib diisi.',
        ]);

        $nama = trim($data['username']);
        PercobaanLogin::pastikanBolehMencoba($request, $nama, self::GUARD);

        // Sama seperti EduWeb: nama pengguna dulu, lalu NISN (NIS bila NISN kosong di EduWeb).
        $siswa = Siswa::whereRaw('lower(username) = ?', [mb_strtolower($nama)])->first()
            ?? Siswa::where('nisn', $nama)->first();

        $berhasil = $siswa !== null && Auth::guard(self::GUARD)->attempt(
            ['id' => $siswa->id, 'password' => $data['password'], 'status' => 'aktif']
        );

        if (! $berhasil) {
            if ($siswa === null) {
                Hash::check($data['password'], self::HASH_PALSU); // samakan waktu tanggap agar nama pengguna tak bisa ditebak
            }
            PercobaanLogin::gagal($request, $nama, self::GUARD);
            AuditLog::catat('siswa.masuk_gagal', "Pengguna: {$nama}");

            throw ValidationException::withMessages([
                'username' => 'Nama pengguna atau kata sandi salah.',
            ]);
        }

        PercobaanLogin::berhasil($request, $nama, self::GUARD);
        $request->session()->regenerate();
        AuditLog::catat('siswa.masuk', null, Auth::guard(self::GUARD)->user());

        return redirect()->intended(route('siswa.beranda'));
    }

    public function keluar(Request $request): RedirectResponse
    {
        AuditLog::catat('siswa.keluar', null, Auth::guard(self::GUARD)->user());
        Auth::guard(self::GUARD)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('siswa.masuk');
    }
}
