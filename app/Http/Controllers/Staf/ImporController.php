<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Impor\Pengimpor;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImporController extends Controller
{
    private const FOLDER = 'impor';

    public function form(string $entitas): View
    {
        return view('staf.impor.form', ['p' => $this->pengimpor($entitas), 'entitas' => $entitas, 'rute' => $this->rute($entitas)]);
    }

    public function templat(string $entitas): StreamedResponse
    {
        $p = $this->pengimpor($entitas);

        return $this->unduhXlsx($p->buatTemplat(), "templat-{$entitas}.xlsx");
    }

    public function pratinjau(Request $request, string $entitas): View
    {
        $p = $this->pengimpor($entitas);
        $request->validate([
            'berkas' => ['required', 'file', 'mimes:xlsx', 'max:2048'],
        ], [
            'berkas.required' => 'Pilih berkas Excel (.xlsx) terlebih dahulu.',
            'berkas.mimes' => 'Berkas harus berformat .xlsx.',
            'berkas.max' => 'Ukuran berkas maksimal 2 MB.',
        ]);

        $token = Str::random(40);
        Storage::disk('local')->putFileAs(self::FOLDER, $request->file('berkas'), "{$token}.xlsx");
        $hasil = $p->jalankan($this->jalur($token), false);

        if ($hasil->berhasil()) {
            session(["impor.{$token}" => ['entitas' => $entitas, 'pengguna' => $request->user('web')->id]]);
        } else {
            Storage::disk('local')->delete(self::FOLDER."/{$token}.xlsx");
            $token = null;
        }

        return view('staf.impor.hasil', ['p' => $p, 'entitas' => $entitas, 'hasil' => $hasil, 'token' => $token, 'selesai' => false, 'tokenSandi' => null, 'rute' => $this->rute($entitas)]);
    }

    public function simpan(Request $request, string $entitas, string $token): View
    {
        $p = $this->pengimpor($entitas);
        $this->pastikanMilik($request, $entitas, $token);

        $hasil = $p->jalankan($this->jalur($token), true);
        Storage::disk('local')->delete(self::FOLDER."/{$token}.xlsx");
        $request->session()->forget("impor.{$token}");

        $tokenSandi = null;
        if ($hasil->berhasil()) {
            AuditLog::catat("impor.{$entitas}", "{$hasil->baru} baru, {$hasil->diperbarui} diperbarui", $request->user('web'));

            if ($hasil->sandi !== []) {
                $tokenSandi = Str::random(40);
                $buku = new Spreadsheet;
                $lembar = $buku->getActiveSheet()->setTitle('Kata sandi');
                $lembar->fromArray([['Nama pengguna', 'Nama', 'Kata sandi sementara']], null, 'A1');
                $lembar->fromArray($hasil->sandi, null, 'A2');
                foreach (['A', 'B', 'C'] as $k) {
                    $lembar->getColumnDimension($k)->setAutoSize(true);
                }
                Storage::disk('local')->makeDirectory(self::FOLDER);
                (new Xlsx($buku))->save($this->jalur("sandi-{$tokenSandi}"));
                session(["impor.sandi.{$tokenSandi}" => ['pengguna' => $request->user('web')->id]]);
            }
        }

        return view('staf.impor.hasil', ['p' => $p, 'entitas' => $entitas, 'hasil' => $hasil, 'token' => null, 'selesai' => true, 'tokenSandi' => $tokenSandi, 'rute' => $this->rute($entitas)]);
    }

    /** Daftar kata sandi hanya bisa diunduh sekali, lalu berkasnya dihapus. */
    public function unduhSandi(Request $request, string $token): Response
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);
        $kunci = "impor.sandi.{$token}";
        $milik = session($kunci);
        abort_unless($milik && $milik['pengguna'] === $request->user('web')->id, 404);

        $jalur = $this->jalur("sandi-{$token}");
        abort_unless(is_file($jalur), 404);
        $request->session()->forget($kunci);

        return response()->download($jalur, 'kata-sandi-sementara.xlsx')->deleteFileAfterSend(true);
    }

    private function rute(string $entitas): array
    {
        return [
            'templat' => route('staf.impor.templat', $entitas),
            'pratinjau' => route('staf.impor.pratinjau', $entitas),
            'simpan' => route('staf.impor.simpan', [$entitas, ':token']),
            'form' => route('staf.impor.form', $entitas),
            'kembali' => route('staf.master.index', $entitas),
            'kembaliLabel' => $this->pengimpor($entitas)->judul(),
        ];
    }

    private function pengimpor(string $entitas): Pengimpor
    {
        return Pengimpor::temukan($entitas) ?? abort(404);
    }

    private function pastikanMilik(Request $request, string $entitas, string $token): void
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);
        $milik = session("impor.{$token}");
        abort_unless($milik && $milik['entitas'] === $entitas && $milik['pengguna'] === $request->user('web')->id, 404);
        abort_unless(is_file($this->jalur($token)), 404);
    }

    private function jalur(string $nama): string
    {
        $nama = Str::endsWith($nama, '.xlsx') ? $nama : "{$nama}.xlsx";

        return Storage::disk('local')->path(self::FOLDER."/{$nama}");
    }

    private function unduhXlsx(Spreadsheet $buku, string $nama): StreamedResponse
    {
        return response()->streamDownload(function () use ($buku) {
            (new Xlsx($buku))->save('php://output');
        }, $nama, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
