<?php

namespace App\Http\Controllers\Staf;

use App\Eduweb\KesalahanEduweb;
use App\Eduweb\KlienEduweb;
use App\Eduweb\PenyinkronEduweb;
use App\Eduweb\SinkronOtomatis;
use App\Http\Controllers\Controller;
use App\Models\LogOtomatis;
use App\Models\PengaturanSekolah;
use App\Models\PenyediaOtomatis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SinkronEduwebController extends Controller
{
    private const FOLDER = 'eduweb-sandi';

    public function __construct(private PenyinkronEduweb $penyinkron, private KlienEduweb $klien, private SinkronOtomatis $otomatis) {}

    public function index(): View
    {
        return view('staf.sinkron.index', [
            'p' => PengaturanSekolah::ambil(),
            'terkonfigurasi' => $this->klien->terkonfigurasi(),
            'otomatisAktif' => $this->otomatis->aktif(),
            'webhookSiap' => filled(config('eduweb.webhook_rahasia')),
            'urlWebhook' => route('eduweb.webhook'),
            'ujianBerlangsung' => $this->otomatis->ujianBerlangsung(),
            'cekMenit' => (int) config('eduweb.cek_menit'),
            'penyedia' => PenyediaOtomatis::aktif(),
            'logOtomatis' => LogOtomatis::with('user')->latest('id')->limit(15)->get(),
            'batasOtomatis' => (int) config('ujian.batas_otomatis_harian'),
            'log' => DB::table('sinkron_log')->orderByDesc('id')->limit(15)->get()->map(function ($l) {
                $l->ringkasan = json_decode((string) $l->ringkasan, true);
                $l->created_at = \Illuminate\Support\Carbon::parse($l->created_at);

                return $l;
            }),
        ]);
    }

    /** Halaman ringkas untuk admin (tanpa mode pengembang): status sinkron dan tombol "Sinkron sekarang". */
    public function halaman(): View
    {
        return view('staf.eduweb', [
            'p' => PengaturanSekolah::ambil(),
            'terkonfigurasi' => $this->klien->terkonfigurasi(),
            'ujianBerlangsung' => $this->otomatis->ujianBerlangsung(),
            'log' => DB::table('sinkron_log')->orderByDesc('id')->limit(8)->get()->map(function ($l) {
                $l->created_at = \Illuminate\Support\Carbon::parse($l->created_at);

                return $l;
            }),
        ]);
    }

    /** Sinkron penuh oleh admin. Ditolak selama ada ujian berlangsung agar data peserta tidak berubah di tengah ujian. */
    public function sekarang(Request $request): RedirectResponse
    {
        if ($this->otomatis->ujianBerlangsung()) {
            return back()->with('gagal', 'Ada ujian yang sedang atau akan segera berlangsung. Sinkron ditunda sampai ujian selesai.');
        }

        return $this->jalankan($request, fn () => $this->penyinkron->penuh($request->user('web')), 'Sinkron dengan EduWeb selesai.');
    }

    public function uji(): RedirectResponse
    {
        try {
            $ping = $this->klien->get('ping');
        } catch (KesalahanEduweb $e) {
            return back()->with('gagal', $e->getMessage());
        }

        return back()->with('berhasil', 'Terhubung ke EduWeb: '.($ping['school']['name'] ?? '?').' (API versi '.($ping['api_version'] ?? '?').').');
    }

    public function penuh(Request $request): RedirectResponse
    {
        return $this->jalankan($request, fn () => $this->penyinkron->penuh($request->user('web')), 'Sinkron penuh selesai.');
    }

    public function kejadian(Request $request): RedirectResponse
    {
        return $this->jalankan($request, fn () => $this->penyinkron->kejadian($request->user('web')), 'Kejadian terbaru diterapkan.');
    }

    /** Daftar akun guru baru hanya bisa diunduh sekali oleh admin yang menjalankan sinkron, lalu berkasnya dihapus. */
    public function unduhSandi(Request $request, string $token): StreamedResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);
        $kunci = "eduweb.sandi.{$token}";
        abort_unless($request->session()->get($kunci) === $request->user('web')->id, 404);
        $berkas = self::FOLDER."/{$token}.xlsx";
        abort_unless(Storage::disk('local')->exists($berkas), 404);

        $request->session()->forget($kunci);
        $jalur = Storage::disk('local')->path($berkas);

        return response()->streamDownload(function () use ($jalur, $berkas) {
            readfile($jalur);
            Storage::disk('local')->delete($berkas);
        }, 'akun-guru-baru.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store, private']);
    }

    private function jalankan(Request $request, callable $aksi, string $pesan): RedirectResponse
    {
        try {
            $h = $aksi();
        } catch (KesalahanEduweb $e) {
            return back()->with('gagal', $e->getMessage());
        }

        $kembali = back()->with('berhasil', $pesan)->with('hasil', $h->ringkasan());
        if ($h->akunBaru) {
            $kembali->with('sandi_token', $this->simpanSandi($request, $h->akunBaru));
        }

        return $kembali;
    }

    /** @param  list<array{username:string,nama:string,sandi:string}>  $akun */
    private function simpanSandi(Request $request, array $akun): string
    {
        $token = Str::random(40);
        $buku = new Spreadsheet;
        $lembar = $buku->getActiveSheet()->setTitle('Akun guru baru');
        $baris = array_merge([['Nama pengguna', 'Nama', 'Kata sandi sementara']], array_map(fn ($a) => [$a['username'], $a['nama'], $a['sandi']], $akun));
        foreach ($baris as $r => $isi) {
            foreach ($isi as $k => $nilai) {
                $lembar->getCell([$k + 1, $r + 1])->setValueExplicit((string) $nilai, DataType::TYPE_STRING); // bukan rumus
            }
        }
        foreach (['A', 'B', 'C'] as $k) {
            $lembar->getColumnDimension($k)->setAutoSize(true);
        }
        Storage::disk('local')->makeDirectory(self::FOLDER);
        (new Xlsx($buku))->save(Storage::disk('local')->path(self::FOLDER."/{$token}.xlsx"));
        $request->session()->put("eduweb.sandi.{$token}", $request->user('web')->id);

        return $token;
    }
}
