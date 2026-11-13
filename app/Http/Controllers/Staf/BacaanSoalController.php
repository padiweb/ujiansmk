<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BacaanSoal;
use App\Models\BankSoal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Bacaan/stimulus yang dipakai bersama beberapa soal dalam satu bank (ditulis sekali, dipilih saat membuat soal). */
class BacaanSoalController extends Controller
{
    public function create(Request $request, BankSoal $bank): View
    {
        $this->izin($request, $bank);

        return view('staf.bacaan.form', ['bank' => $bank, 'bacaan' => null]);
    }

    public function store(Request $request, BankSoal $bank): RedirectResponse
    {
        $this->izin($request, $bank);
        $data = $this->validasi($request);

        $b = $bank->bacaan()->create($data + ['sumber' => 'manual']);
        AuditLog::catat('bacaan.tambah', "bank {$bank->id} bacaan {$b->id}", $request->user('web'));

        return redirect()->route('staf.bank-soal.show', $bank)->with('berhasil', 'Bacaan ditambahkan. Pilih bacaan ini saat membuat soal.');
    }

    public function edit(Request $request, BankSoal $bank, BacaanSoal $bacaan): View
    {
        $this->izin($request, $bank, $bacaan);

        return view('staf.bacaan.form', ['bank' => $bank, 'bacaan' => $bacaan]);
    }

    public function update(Request $request, BankSoal $bank, BacaanSoal $bacaan): RedirectResponse
    {
        $this->izin($request, $bank, $bacaan);
        $bacaan->update($this->validasi($request));
        AuditLog::catat('bacaan.ubah', "bacaan {$bacaan->id}", $request->user('web'));

        return redirect()->route('staf.bank-soal.show', $bank)->with('berhasil', 'Bacaan diperbarui. Semua soal yang memakainya ikut berubah.');
    }

    /** Soal yang memakai bacaan ini tetap ada (tanpa bacaan). */
    public function destroy(Request $request, BankSoal $bank, BacaanSoal $bacaan): RedirectResponse
    {
        $this->izin($request, $bank, $bacaan);
        $dipakai = $bacaan->soal()->count();
        $bacaan->delete();
        AuditLog::catat('bacaan.hapus', "bacaan {$bacaan->id}", $request->user('web'));

        return back()->with('berhasil', 'Bacaan dihapus.'.($dipakai > 0 ? " {$dipakai} soal yang memakainya kini tanpa bacaan." : ''));
    }

    private function izin(Request $request, BankSoal $bank, ?BacaanSoal $bacaan = null): void
    {
        abort_unless($bank->dapatDikelola($request->user('web')), 403, 'Anda tidak memiliki akses ke bank soal ini.');
        abort_if($bacaan && $bacaan->bank_soal_id !== $bank->id, 404);
    }

    /** @return array{judul:?string,teks:string} */
    private function validasi(Request $request): array
    {
        $d = $request->validate([
            'judul' => ['nullable', 'string', 'max:150'], 'teks' => ['required', 'string', 'max:8000'],
        ], [], ['judul' => 'Judul', 'teks' => 'Teks bacaan']);

        return ['judul' => filled($d['judul'] ?? null) ? trim($d['judul']) : null, 'teks' => trim($d['teks'])];
    }
}
