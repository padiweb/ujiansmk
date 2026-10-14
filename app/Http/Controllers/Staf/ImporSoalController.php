<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Impor\SoalPengimpor;
use App\Models\AuditLog;
use App\Models\BankSoal;
use App\Services\LayananSoal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImporSoalController extends Controller
{
    private const FOLDER = 'impor';

    public function __construct(private LayananSoal $layanan) {}

    public function form(Request $request, BankSoal $bank): View
    {
        $this->izin($request, $bank);

        return view('staf.impor.form', ['p' => $this->pengimpor($bank), 'entitas' => 'soal', 'rute' => $this->rute($bank)]);
    }

    public function templat(Request $request, BankSoal $bank): StreamedResponse
    {
        $this->izin($request, $bank);
        $buku = $this->pengimpor($bank)->buatTemplat();

        return response()->streamDownload(fn () => (new Xlsx($buku))->save('php://output'), 'templat-soal.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function pratinjau(Request $request, BankSoal $bank): View
    {
        $this->izin($request, $bank);
        $request->validate(['berkas' => ['required', 'file', 'mimes:xlsx', 'max:2048']], [
            'berkas.required' => 'Pilih berkas Excel (.xlsx) terlebih dahulu.',
            'berkas.mimes' => 'Berkas harus berformat .xlsx.',
            'berkas.max' => 'Ukuran berkas maksimal 2 MB.',
        ]);

        $token = Str::random(40);
        Storage::disk('local')->putFileAs(self::FOLDER, $request->file('berkas'), "{$token}.xlsx");
        $hasil = $this->pengimpor($bank)->jalankan(Storage::disk('local')->path(self::FOLDER."/{$token}.xlsx"), false);

        if ($hasil->berhasil()) {
            session(["impor.{$token}" => ['entitas' => "soal-{$bank->id}", 'pengguna' => $request->user('web')->id]]);
        } else {
            Storage::disk('local')->delete(self::FOLDER."/{$token}.xlsx");
            $token = null;
        }

        return view('staf.impor.hasil', ['p' => $this->pengimpor($bank), 'entitas' => 'soal', 'hasil' => $hasil, 'token' => $token,
            'selesai' => false, 'tokenSandi' => null, 'rute' => $this->rute($bank)]);
    }

    public function simpan(Request $request, BankSoal $bank, string $token): View
    {
        $this->izin($request, $bank);
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);
        $milik = session("impor.{$token}");
        abort_unless($milik && $milik['entitas'] === "soal-{$bank->id}" && $milik['pengguna'] === $request->user('web')->id, 404);

        $jalur = Storage::disk('local')->path(self::FOLDER."/{$token}.xlsx");
        abort_unless(is_file($jalur), 404);

        $hasil = $this->pengimpor($bank)->jalankan($jalur, true);
        Storage::disk('local')->delete(self::FOLDER."/{$token}.xlsx");
        $request->session()->forget("impor.{$token}");

        if ($hasil->berhasil()) {
            AuditLog::catat('impor.soal', "bank {$bank->id}: {$hasil->baru} soal", $request->user('web'));
        }

        return view('staf.impor.hasil', ['p' => $this->pengimpor($bank), 'entitas' => 'soal', 'hasil' => $hasil, 'token' => null,
            'selesai' => true, 'tokenSandi' => null, 'rute' => $this->rute($bank)]);
    }

    private function pengimpor(BankSoal $bank): SoalPengimpor
    {
        return new SoalPengimpor($bank, $this->layanan);
    }

    private function izin(Request $request, BankSoal $bank): void
    {
        abort_unless($bank->dapatDikelola($request->user('web')), 403, 'Anda tidak memiliki akses ke bank soal ini.');
    }

    private function rute(BankSoal $bank): array
    {
        return [
            'templat' => route('staf.bank-soal.impor.templat', $bank),
            'pratinjau' => route('staf.bank-soal.impor.pratinjau', $bank),
            'simpan' => route('staf.bank-soal.impor.simpan', [$bank, ':token']),
            'form' => route('staf.bank-soal.impor.form', $bank),
            'kembali' => route('staf.bank-soal.show', $bank),
            'kembaliLabel' => $bank->nama,
        ];
    }
}
