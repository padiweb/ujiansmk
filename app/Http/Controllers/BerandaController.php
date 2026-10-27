<?php

namespace App\Http\Controllers;

use App\Eduweb\KlienEduweb;
use App\Master\DefinisiMaster;
use App\Models\PengaturanSekolah;
use App\Models\Ruang;
use App\Models\Siswa;
use App\Models\Soal;
use App\Models\User;
use App\Services\LayananMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BerandaController extends Controller
{
    public function __construct(private LayananMonitor $monitor) {}

    public function staf(Request $request): View
    {
        $user = $request->user('web');
        $langkah = [];

        if ($user->role === 'admin') {
            $urutan = ['tahun-ajaran', 'periode', 'jurusan', 'staf', 'kelas', 'mapel', 'siswa', 'pengampu', 'ruang'];
            foreach ($urutan as $slug) {
                $def = DefinisiMaster::temukan($slug);
                $kueri = $def->model()::query();
                if ($slug === 'staf') {
                    $kueri->where('role', 'guru');
                }
                $langkah[] = ['slug' => $slug, 'jumlah' => $kueri->count(),
                    'label' => $slug === 'staf' ? 'Guru' : $def->judul()];
            }
        }

        $pengawas = $user->role === 'admin' || $user->mengawasi();

        return view('staf.beranda', [
            'user' => $user, 'langkah' => $langkah, 'dataEduweb' => $user->role === 'admin' ? $this->statusDataEduweb() : null,
            'jadwal' => $this->monitor->jadwalUntuk($user)->with('mapel')->orderBy('mulai_pada')->get(),
            'ruang' => $pengawas ? Ruang::orderBy('nama')->get(['id', 'nama']) : collect(),
            'jumlah' => $user->role === 'admin' ? [
                'siswa' => Siswa::where('status', 'aktif')->count(),
                'guru' => User::where('role', 'guru')->count(),
                'soal' => Soal::count(),
            ] : null,
            'sapaan' => match (true) {
                now()->hour < 11 => 'Selamat pagi', now()->hour < 15 => 'Selamat siang', now()->hour < 18 => 'Selamat sore', default => 'Selamat malam',
            },
        ]);
    }

    /** Angka langsung beranda (diambil berkala oleh halaman). */
    public function data(Request $request): JsonResponse
    {
        $ruang = $request->integer('ruang') ?: null;

        return response()->json($this->monitor->ringkasanBeranda($request->user('web'), $ruang))->header('Cache-Control', 'no-store');
    }

    /** Satu baris status untuk admin; null bila penghubung tidak dipakai. Rincian teknis ada di halaman diagnosis. */
    private function statusDataEduweb(): ?array
    {
        if (! app(KlienEduweb::class)->terkonfigurasi()) {
            return null;
        }

        $p = PengaturanSekolah::ambil();
        if ($p->eduweb_sinkron_penuh_pada === null) {
            return ['masalah' => true, 'teks' => 'Data dari EduWeb belum pernah diambil. Hubungi pengelola aplikasi.'];
        }

        $terakhir = collect([$p->eduweb_sinkron_penuh_pada, $p->eduweb_cek_terakhir])->filter()->max();
        $gagal = DB::table('sinkron_log')->orderByDesc('id')->value('status') === 'gagal';

        return [
            'masalah' => $gagal,
            'teks' => 'Data dari EduWeb terakhir diperbarui '.$terakhir->format('d/m/Y H.i').'.'
                .($gagal ? ' Pembaruan terakhir tidak berhasil; hubungi pengelola aplikasi bila berlanjut.' : ''),
        ];
    }
}
