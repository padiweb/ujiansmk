<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankSoal;
use App\Models\PenyediaOtomatis;
use App\Otomatis\KesalahanOtomatis;
use App\Otomatis\PermintaanSoal;
use App\Services\LayananOtomatis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OtomatisSoalController extends Controller
{
    public function __construct(private LayananOtomatis $layanan) {}

    public function form(Request $request, BankSoal $bank): View
    {
        $this->izin($request, $bank);

        return view('staf.bank-soal.otomatis', [
            'bank' => $bank->load('mapel'),
            'penyedia' => PenyediaOtomatis::aktif(),
            'sisa' => $this->layanan->sisaHariIni($request->user('web')),
            'batas' => $this->layanan->batasHarian(),
            'maks' => config('ujian.maks_soal_otomatis'),
        ]);
    }

    public function proses(Request $request, BankSoal $bank): RedirectResponse
    {
        $this->izin($request, $bank);
        $maks = (int) config('ujian.maks_soal_otomatis');

        $data = $request->validate([
            'topik' => ['required', 'string', 'max:300'],
            'jumlah' => ['required', 'integer', "between:1,{$maks}"],
            'tipe' => ['required', Rule::in(PermintaanSoal::TIPE)],
            'jumlah_opsi' => ['required', Rule::in([4, 5])],
            'kesulitan' => ['required', Rule::in(PermintaanSoal::KESULITAN)],
            'materi' => ['nullable', 'string', 'max:4000'],
            'soal_per_bacaan' => ['nullable', 'integer', 'between:0,10'],
        ], [], ['soal_per_bacaan' => 'Soal per bacaan', 'topik' => 'Topik', 'jumlah' => 'Jumlah soal', 'tipe' => 'Jenis soal', 'jumlah_opsi' => 'Jumlah opsi',
            'kesulitan' => 'Tingkat kesulitan', 'materi' => 'Materi acuan']);

        $permintaan = new PermintaanSoal(
            mapel: $bank->mapel->nama,
            tingkat: $bank->tingkat,
            topik: $data['topik'],
            jumlah: (int) $data['jumlah'],
            tipe: $data['tipe'],
            jumlahOpsi: (int) $data['jumlah_opsi'],
            kesulitan: $data['kesulitan'],
            materi: $data['materi'] ?? null,
            soalPerBacaan: (int) ($data['soal_per_bacaan'] ?? 0),
        );

        try {
            $jumlah = $this->layanan->susun($request->user('web'), $bank, $permintaan);
        } catch (KesalahanOtomatis $e) {
            return back()->withInput()->with('gagal', $e->getMessage());
        }

        AuditLog::catat('soal.otomatis', "bank {$bank->id}: {$jumlah} soal draf", $request->user('web'));

        return redirect()->route('staf.bank-soal.show', [$bank, 'status' => 'draf'])
            ->with('berhasil', "{$jumlah} soal draf berhasil disusun. Periksa setiap soal, lalu tandai siap bila sudah benar.");
    }

    private function izin(Request $request, BankSoal $bank): void
    {
        abort_unless($bank->dapatDikelola($request->user('web')), 403, 'Anda tidak memiliki akses ke bank soal ini.');
    }
}
