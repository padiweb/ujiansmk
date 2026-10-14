<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Master\DefinisiMaster;
use App\Models\AuditLog;
use App\Models\PengaturanSekolah;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class MasterDataController extends Controller
{
    public function index(Request $request, string $entitas): View
    {
        $def = $this->definisi($entitas);
        $kueri = $def->kueri();

        if (filled($q = trim((string) $request->query('q')))) {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $kueri->where(function ($w) use ($def, $like) {
                foreach ($def->cari() as $kolom) {
                    $w->orWhere($kolom, 'like', $like);
                }
            });
        }

        foreach ($def->filter() as $f) {
            $nilai = $request->query($f['name']);
            if (filled($nilai) && array_key_exists($nilai, $f['options'])) {
                ($f['terapkan'])($kueri, $nilai);
            }
        }

        return view('staf.master.index', [
            'def' => $def,
            'baris' => $kueri->paginate(20)->withQueryString(),
            'terkunci' => $def->dikunciSinkron() && PengaturanSekolah::sinkronAktif(),
            'pengimpor' => \App\Impor\Pengimpor::temukan($entitas) !== null,
        ]);
    }

    public function create(string $entitas): View
    {
        $def = $this->definisi($entitas);

        return view('staf.master.form', ['def' => $def, 'm' => null, 'bidang' => $def->bidang(null)]);
    }

    public function store(Request $request, string $entitas): RedirectResponse
    {
        $def = $this->definisi($entitas);
        $data = $this->validasi($request, $def, null);
        $data = $def->siapkan($data, null);

        $catatan = null;
        $m = DB::transaction(function () use ($def, $data, &$catatan) {
            $kelas = $def->model();
            $m = new $kelas;
            $m->fill(Arr::only($data, $m->getFillable()))->save();
            $catatan = $def->sesudahSimpan($m, $data, true);

            return $m;
        });

        AuditLog::catat("master.{$entitas}.tambah", "ID {$m->getKey()}", $request->user('web'));

        return redirect()->route('staf.master.index', $entitas)
            ->with('berhasil', $def->judul().' berhasil ditambahkan.')
            ->with('catatan', $catatan);
    }

    public function edit(string $entitas, int $id): View
    {
        $def = $this->definisi($entitas);
        $m = $def->kueri()->findOrFail($id);

        return view('staf.master.form', ['def' => $def, 'm' => $m, 'bidang' => $def->bidang($m)]);
    }

    public function update(Request $request, string $entitas, int $id): RedirectResponse
    {
        $def = $this->definisi($entitas);
        $m = $def->kueri()->findOrFail($id);
        $data = $this->validasi($request, $def, $m);
        $data = $def->siapkan($data, $m);

        $catatan = null;
        DB::transaction(function () use ($def, $data, $m, &$catatan) {
            $m->fill(Arr::only($data, $m->getFillable()))->save();
            $catatan = $def->sesudahSimpan($m, $data, false);
        });

        AuditLog::catat("master.{$entitas}.ubah", "ID {$m->getKey()}", $request->user('web'));

        return redirect()->route('staf.master.index', $entitas)
            ->with('berhasil', 'Perubahan disimpan.')
            ->with('catatan', $catatan);
    }

    public function destroy(Request $request, string $entitas, int $id): RedirectResponse
    {
        $def = $this->definisi($entitas);
        $m = $def->kueri()->findOrFail($id);

        if ($alasan = $def->tolakHapus($m)) {
            return back()->with('gagal', $alasan);
        }

        try {
            $m->delete();
        } catch (QueryException) {
            return back()->with('gagal', 'Data ini masih dipakai oleh data lain sehingga tidak bisa dihapus.');
        }

        AuditLog::catat("master.{$entitas}.hapus", "ID {$id}", $request->user('web'));

        return redirect()->route('staf.master.index', $entitas)->with('berhasil', 'Data dihapus.');
    }

    private function definisi(string $entitas): DefinisiMaster
    {
        return DefinisiMaster::temukan($entitas) ?? abort(404);
    }

    private function validasi(Request $request, DefinisiMaster $def, $m): array
    {
        return Validator::make($request->all(), $def->aturan($m), [], $def->namaBidang($m))->validate();
    }
}
