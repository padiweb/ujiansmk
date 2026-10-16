<?php

namespace App\Http\Controllers;

use App\Eduweb\KlienEduweb;
use App\Master\DefinisiMaster;
use App\Models\PengaturanSekolah;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BerandaController extends Controller
{
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

        return view('staf.beranda', ['user' => $user, 'langkah' => $langkah, 'dataEduweb' => $user->role === 'admin' ? $this->statusDataEduweb() : null]);
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
