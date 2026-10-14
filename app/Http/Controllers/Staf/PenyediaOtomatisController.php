<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LogOtomatis;
use App\Models\PenyediaOtomatis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PenyediaOtomatisController extends Controller
{
    public function index(): View
    {
        return view('staf.penyedia.index', [
            'penyedia' => PenyediaOtomatis::orderByDesc('aktif')->orderBy('nama')->get(),
            'log' => LogOtomatis::with('user', 'penyedia')->latest('id')->limit(30)->get(),
            'batas' => config('ujian.batas_otomatis_harian'),
        ]);
    }

    public function create(): View
    {
        return view('staf.penyedia.form', ['p' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->aturan(true), [], $this->nama());
        $penyedia = PenyediaOtomatis::create(['aktif' => false] + $data);

        if ($request->boolean('aktif')) {
            $penyedia->jadikanAktif();
        }

        AuditLog::catat('penyedia.tambah', "{$penyedia->driver}:{$penyedia->model}", $request->user('web'));

        return redirect()->route('staf.penyedia.index')->with('berhasil', 'Penyedia ditambahkan.');
    }

    public function edit(PenyediaOtomatis $penyedia): View
    {
        return view('staf.penyedia.form', ['p' => $penyedia]);
    }

    public function update(Request $request, PenyediaOtomatis $penyedia): RedirectResponse
    {
        $data = $request->validate($this->aturan(false), [], $this->nama());

        if (blank($data['kunci'] ?? null)) {
            unset($data['kunci']); // kosong = kunci lama dipertahankan
        }

        $penyedia->update($data);
        AuditLog::catat('penyedia.ubah', "ID {$penyedia->id}", $request->user('web'));

        return redirect()->route('staf.penyedia.index')->with('berhasil', 'Perubahan disimpan.');
    }

    public function aktifkan(Request $request, PenyediaOtomatis $penyedia): RedirectResponse
    {
        $penyedia->jadikanAktif();
        AuditLog::catat('penyedia.aktifkan', "ID {$penyedia->id}", $request->user('web'));

        return back()->with('berhasil', "{$penyedia->nama} sekarang aktif.");
    }

    public function nonaktifkan(Request $request, PenyediaOtomatis $penyedia): RedirectResponse
    {
        $penyedia->update(['aktif' => false]);
        AuditLog::catat('penyedia.nonaktifkan', "ID {$penyedia->id}", $request->user('web'));

        return back()->with('berhasil', 'Penyedia dinonaktifkan. Penyusunan otomatis tidak tersedia sampai ada penyedia aktif.');
    }

    public function destroy(Request $request, PenyediaOtomatis $penyedia): RedirectResponse
    {
        $penyedia->delete();
        AuditLog::catat('penyedia.hapus', "ID {$penyedia->id}", $request->user('web'));

        return redirect()->route('staf.penyedia.index')->with('berhasil', 'Penyedia dihapus beserta kuncinya.');
    }

    private function aturan(bool $baru): array
    {
        return [
            'nama' => ['required', 'string', 'max:80'],
            'driver' => ['required', Rule::in(array_keys(PenyediaOtomatis::DRIVER))],
            // Nama model masuk ke alamat permintaan (Gemini), jadi dibatasi ke karakter aman.
            'model' => ['required', 'regex:/^[A-Za-z0-9._:-]{1,80}$/'],
            'kunci' => [$baru ? 'required' : 'nullable', 'string', 'min:10', 'max:500'],
        ];
    }

    private function nama(): array
    {
        return ['nama' => 'Nama', 'driver' => 'Jenis penyedia', 'model' => 'Model', 'kunci' => 'Kunci'];
    }
}
