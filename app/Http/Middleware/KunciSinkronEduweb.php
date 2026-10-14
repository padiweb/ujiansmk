<?php

namespace App\Http\Middleware;

use App\Master\DefinisiMaster;
use App\Models\PengaturanSekolah;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saat mode sinkron EduWeb aktif, data master yang datang dari EduWeb
 * hanya bisa dibaca: tambah, ubah, hapus, dan impor ditolak.
 */
class KunciSinkronEduweb
{
    public function handle(Request $request, Closure $next): Response
    {
        $definisi = DefinisiMaster::temukan((string) $request->route('entitas'));

        if ($definisi && $definisi->dikunciSinkron() && PengaturanSekolah::sinkronAktif()) {
            abort(403, 'Data ini dikelola dari EduWeb dan tidak dapat diubah di sini. Ubah di EduWeb, lalu sinkronkan.');
        }

        return $next($request);
    }
}
