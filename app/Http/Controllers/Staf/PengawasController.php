<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Admin menetapkan siapa yang bertugas sebagai pengawas (guru dari EduWeb atau akun pengawas lokal). */
class PengawasController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $like = '%'.addcslashes($q, '%_\\').'%';
        $cari = fn ($kueri) => $q === '' ? $kueri : $kueri->where(fn ($w) => $w->where('nama', 'like', $like)->orWhere('username', 'like', $like));

        return view('staf.pengawas.index', [
            'q' => $q,
            'pengawas' => $cari(User::query()->where(fn ($w) => $w->where('role', User::PENGAWAS)->orWhere(fn ($g) => $g->where('role', User::GURU)->where('bisa_mengawasi', true))))
                ->orderBy('nama')->get(),
            'guru' => $cari(User::query()->where('role', User::GURU)->where('aktif', true)->where('bisa_mengawasi', false))
                ->orderBy('nama')->limit(200)->get(),
        ]);
    }

    /** Tambah akun pengawas lokal (di luar guru EduWeb). */
    public function tambah(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'username' => ['required', 'regex:'.User::POLA_USERNAME, 'max:50', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'min:10', 'max:100'],
        ], [], ['nama' => 'Nama', 'username' => 'Nama pengguna', 'password' => 'Kata sandi']);

        $u = User::create($data + ['role' => User::PENGAWAS, 'aktif' => true]);
        AuditLog::catat('pengawas.tambah', "user {$u->id}", $request->user('web'));

        return back()->with('berhasil', "Pengawas {$u->nama} ditambahkan.");
    }

    /** Jadikan / cabut tugas pengawas (guru), atau aktif/nonaktifkan akun pengawas lokal. */
    public function ubah(Request $request, User $user): RedirectResponse
    {
        abort_unless(in_array($user->role, [User::GURU, User::PENGAWAS], true), 404);
        $aktif = $request->boolean('aktif');

        if ($user->role === User::GURU) {
            $user->update(['bisa_mengawasi' => $aktif]);
            AuditLog::catat($aktif ? 'pengawas.jadikan' : 'pengawas.cabut', "user {$user->id}", $request->user('web'));

            return back()->with('berhasil', $aktif ? "{$user->nama} sekarang bertugas sebagai pengawas." : "Tugas pengawas {$user->nama} dicabut.");
        }

        $user->update(['aktif' => $aktif]);
        AuditLog::catat($aktif ? 'pengawas.aktifkan' : 'pengawas.nonaktifkan', "user {$user->id}", $request->user('web'));

        return back()->with('berhasil', $aktif ? "Akun {$user->nama} diaktifkan." : "Akun {$user->nama} dinonaktifkan.");
    }
}
