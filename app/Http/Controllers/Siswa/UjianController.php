<?php

namespace App\Http\Controllers\Siswa;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\JadwalUjian;
use App\Models\SesiUjian;
use App\Models\Siswa;
use App\Services\KesalahanUjian;
use App\Services\LayananSesi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class UjianController extends Controller
{
    /** Izin masuk (token benar) berlaku sekian detik sebelum siswa menekan Mulai. */
    private const MASA_IZIN = 1800;

    public function __construct(private LayananSesi $layanan) {}

    public function beranda(Request $request): View
    {
        $siswa = $request->user('siswa');
        $kelasIds = $siswa->kelas()->pluck('kelas.id');

        $tersedia = $siswa->status !== 'aktif' ? collect() : JadwalUjian::query()
            ->where('status', JadwalUjian::AKTIF)
            ->whereHas('kelas', fn ($q) => $q->whereIn('kelas.id', $kelasIds))
            ->with('mapel')->orderBy('mulai_pada')->get();

        $sesi = SesiUjian::where('siswa_id', $siswa->id)->whereIn('jadwal_id', $tersedia->pluck('id'))->with('hasil')->get()->keyBy('jadwal_id');

        $riwayat = SesiUjian::where('siswa_id', $siswa->id)->where('status', SesiUjian::SELESAI)
            ->with('hasil', 'jadwal.mapel')->latest('selesai_pada')->limit(20)->get();

        return view('siswa.beranda', [
            'siswa' => $siswa,
            // Yang sudah selesai pindah ke riwayat.
            'tersedia' => $tersedia->reject(fn ($j) => ($sesi[$j->id]->status ?? null) === SesiUjian::SELESAI)->values(),
            'sesi' => $sesi,
            'riwayat' => $riwayat,
            'rapor' => \App\Models\RaporSiswa::with('periode.tahunAjaran')->where('siswa_id', $siswa->id)->where('status', 'final')->get()
                ->sortByDesc('periode_id')->values(),
        ]);
    }

    /** Halaman token. Bila sesi sudah berjalan di perangkat ini, langsung lanjut. */
    public function token(Request $request, JadwalUjian $jadwal): View|RedirectResponse
    {
        $sesi = $this->sesi($request, $jadwal);

        if ($sesi->status === SesiUjian::SELESAI) {
            return redirect()->route('siswa.ujian.hasil', $jadwal);
        }

        if (in_array($sesi->status, [SesiUjian::BERLANGSUNG, SesiUjian::DIBLOKIR], true)) {
            if ($this->layanan->perangkatCocok($sesi, $request->cookie($this->namaCookie($sesi)))) {
                return redirect()->route('siswa.ujian.kerjakan', $jadwal);
            }

            // Perangkat dilepas pengawas (token_perangkat kosong): siswa boleh masuk lagi dengan token.
            if ($sesi->token_perangkat !== null) {
                return view('siswa.ujian.perangkat-lain', ['jadwal' => $jadwal]);
            }
        }

        return view('siswa.ujian.token', ['jadwal' => $jadwal->load('mapel'), 'jendela' => $jadwal->jendela()]);
    }

    public function verifikasiToken(Request $request, JadwalUjian $jadwal): RedirectResponse
    {
        $siswa = $request->user('siswa');
        $sesi = $this->sesi($request, $jadwal);
        $data = $request->validate(['token' => ['required', 'string', 'max:20']], ['token.required' => 'Token wajib diisi.']);

        $kunci = "ujian-token:{$siswa->id}:{$jadwal->id}";
        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            return back()->withErrors(['token' => 'Terlalu banyak percobaan. Tunggu '.RateLimiter::availableIn($kunci).' detik.']);
        }

        if (! $jadwal->token || ! hash_equals($jadwal->token, strtoupper(trim($data['token'])))) {
            RateLimiter::hit($kunci, 60);
            AuditLog::catat('ujian.token_salah', "jadwal {$jadwal->id}", $siswa);

            return back()->withErrors(['token' => 'Token salah. Minta token yang benar kepada pengawas.']);
        }

        RateLimiter::clear($kunci);
        $request->session()->put("ujian_izin.{$jadwal->id}", now()->timestamp);

        return redirect()->route('siswa.ujian.siap', $jadwal);
    }

    public function siap(Request $request, JadwalUjian $jadwal): View|RedirectResponse
    {
        $sesi = $this->sesi($request, $jadwal);

        if (! $this->punyaIzin($request, $jadwal)) {
            return redirect()->route('siswa.ujian.token', $jadwal);
        }

        return view('siswa.ujian.siap', ['jadwal' => $jadwal->load('mapel'), 'sesi' => $sesi]);
    }

    public function mulai(Request $request, JadwalUjian $jadwal): RedirectResponse|Response
    {
        $sesi = $this->sesi($request, $jadwal);
        $cookie = $request->cookie($this->namaCookie($sesi));

        // Melanjutkan sesi yang sudah berjalan tidak butuh token lagi; sesi baru wajib punya izin dari token.
        $melanjutkan = $sesi->status !== SesiUjian::BELUM && $this->layanan->perangkatCocok($sesi, $cookie);
        if (! $melanjutkan && ! $this->punyaIzin($request, $jadwal)) {
            return redirect()->route('siswa.ujian.token', $jadwal);
        }

        try {
            $tokenPerangkat = $this->layanan->mulai($sesi, $cookie, $request);
        } catch (KesalahanUjian $e) {
            return $e->kode === 'selesai'
                ? redirect()->route('siswa.ujian.hasil', $jadwal)
                : redirect()->route('siswa.ujian.token', $jadwal)->with('gagal', $e->getMessage());
        }

        $request->session()->forget("ujian_izin.{$jadwal->id}");

        return redirect()->route('siswa.ujian.kerjakan', $jadwal)->withCookie(cookie(
            $this->namaCookie($sesi), $tokenPerangkat, 60 * 12, '/', null, $request->isSecure(), true, false, 'lax',
        ));
    }

    public function kerjakan(Request $request, JadwalUjian $jadwal): View|RedirectResponse
    {
        $sesi = $this->sesi($request, $jadwal);

        if ($sesi->status === SesiUjian::SELESAI) {
            return redirect()->route('siswa.ujian.hasil', $jadwal);
        }

        $cookie = $request->cookie($this->namaCookie($sesi));
        if (! in_array($sesi->status, [SesiUjian::BERLANGSUNG, SesiUjian::DIBLOKIR], true) || ! $this->layanan->perangkatCocok($sesi, $cookie)) {
            return redirect()->route('siswa.ujian.token', $jadwal);
        }

        return view('siswa.ujian.kerjakan', [
            'jadwal' => $jadwal->load('mapel'),
            'sesi' => $sesi,
            'siswa' => $request->user('siswa'),
            'perangkat' => $cookie,
            'alamat' => [
                'paket' => url("api/ujian/{$sesi->id}/paket"),
                'sinkron' => url("api/ujian/{$sesi->id}/sinkron"),
                'pelanggaran' => url("api/ujian/{$sesi->id}/pelanggaran"),
                'kirim' => url("api/ujian/{$sesi->id}/kirim"),
                'hasil' => route('siswa.ujian.hasil', $jadwal),
            ],
        ]);
    }

    public function hasil(Request $request, JadwalUjian $jadwal): View|RedirectResponse
    {
        $sesi = $this->sesi($request, $jadwal, buatBila: false);

        if ($sesi->status !== SesiUjian::SELESAI) {
            return redirect()->route('siswa.ujian.token', $jadwal);
        }

        return view('siswa.ujian.hasil', [
            'jadwal' => $jadwal->load('mapel'),
            'sesi' => $sesi,
            'hasil' => $sesi->hasil,
            'tampil' => $jadwal->tampilkan_hasil === 'langsung',
        ]);
    }

    /** Sesi milik siswa yang sedang login; bila bukan peserta jadwal ini dianggap tidak ada (404). */
    private function sesi(Request $request, JadwalUjian $jadwal, bool $buatBila = true): SesiUjian
    {
        /** @var Siswa $siswa */
        $siswa = $request->user('siswa');

        if (! $buatBila) {
            // Hasil tetap bisa dilihat walau jadwal sudah ditutup; yang penting sesi itu miliknya.
            return SesiUjian::where('jadwal_id', $jadwal->id)->where('siswa_id', $siswa->id)->with('hasil')->firstOrFail();
        }

        return $this->layanan->sesiUntuk($siswa, $jadwal) ?? abort(404);
    }

    private function punyaIzin(Request $request, JadwalUjian $jadwal): bool
    {
        $waktu = $request->session()->get("ujian_izin.{$jadwal->id}");

        return $waktu !== null && (now()->timestamp - (int) $waktu) <= self::MASA_IZIN;
    }

    private function namaCookie(SesiUjian $sesi): string
    {
        return "ujian_p{$sesi->id}";
    }
}
