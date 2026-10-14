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

class MasukStafController extends Controller
{
    private const GUARD = 'web';

    public function tampil(): View
    {
        return view('auth.masuk-staf');
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

        $username = trim($data['username']);
        PercobaanLogin::pastikanBolehMencoba($request, $username, self::GUARD);

        $berhasil = Auth::guard(self::GUARD)->attempt(
            ['username' => $username, 'password' => $data['password'], 'aktif' => true]
        );

        if (! $berhasil) {
            PercobaanLogin::gagal($request, $username, self::GUARD);
            AuditLog::catat('staf.masuk_gagal', "Pengguna: {$username}");

            throw ValidationException::withMessages([
                'username' => 'Nama pengguna atau kata sandi salah.',
            ]);
        }

        PercobaanLogin::berhasil($request, $username, self::GUARD);
        $request->session()->regenerate();

        $user = Auth::guard(self::GUARD)->user();
        $user->forceFill(['login_terakhir' => now()])->save();
        AuditLog::catat('staf.masuk', null, $user);

        return redirect()->intended(route('staf.beranda'));
    }

    public function keluar(Request $request): RedirectResponse
    {
        AuditLog::catat('staf.keluar', null, Auth::guard(self::GUARD)->user());
        Auth::guard(self::GUARD)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('staf.masuk');
    }
}
