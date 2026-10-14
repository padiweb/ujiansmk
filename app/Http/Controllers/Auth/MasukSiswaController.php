<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\PercobaanLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MasukSiswaController extends Controller
{
    private const GUARD = 'siswa';

    public function tampil(): View
    {
        return view('auth.masuk-siswa');
    }

    public function masuk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'max:200'],
        ], [
            'username.required' => 'NISN wajib diisi.',
            'password.required' => 'Kata sandi wajib diisi.',
        ]);

        $nisn = trim($data['username']);
        PercobaanLogin::pastikanBolehMencoba($request, $nisn, self::GUARD);

        $berhasil = Auth::guard(self::GUARD)->attempt(
            ['nisn' => $nisn, 'password' => $data['password'], 'status' => 'aktif']
        );

        if (! $berhasil) {
            PercobaanLogin::gagal($request, $nisn, self::GUARD);
            AuditLog::catat('siswa.masuk_gagal', "NISN: {$nisn}");

            throw ValidationException::withMessages([
                'username' => 'NISN atau kata sandi salah.',
            ]);
        }

        PercobaanLogin::berhasil($request, $nisn, self::GUARD);
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
