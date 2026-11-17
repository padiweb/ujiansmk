<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AksiPengawas;
use App\Models\JadwalUjian;
use App\Models\Pelanggaran;
use App\Models\Ruang;
use App\Models\SesiUjian;
use App\Services\KesalahanUjian;
use App\Services\LayananMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MonitorController extends Controller
{
    public function __construct(private LayananMonitor $monitor) {}

    /** Daftar jadwal aktif yang boleh dipantau pengguna ini. */
    public function index(Request $request): View
    {
        $jadwal = $this->monitor->jadwalUntuk($request->user('web'))
            ->with('mapel')->orderBy('mulai_pada')->get();

        $hitung = SesiUjian::query()->whereIn('jadwal_id', $jadwal->pluck('id'))
            ->select('jadwal_id', 'status', DB::raw('count(*) as jumlah'))->groupBy('jadwal_id', 'status')->get()
            ->groupBy('jadwal_id')->map(fn ($g) => $g->pluck('jumlah', 'status'));

        $user = $request->user('web');
        $pengawas = $user->role === 'admin' || $user->mengawasi();

        return view('staf.monitor.index', [
            'jadwal' => $jadwal,
            'hitung' => $hitung,
            'pengawas' => $pengawas,
            'ruang' => $pengawas ? Ruang::orderBy('nama')->get() : collect(),
            'ringkasRuang' => $pengawas ? $this->monitor->ringkasRuang() : [],
        ]);
    }

    /** Pantau satu ruang: semua ujian aktif di ruang itu beserta tokennya (admin dan pengawas). */
    public function ruang(Request $request, Ruang $ruang): View
    {
        $this->izinkanPengawas($request);

        return view('staf.monitor.ruang', [
            'ruang' => $ruang,
            'jadwal' => $this->monitor->jadwalRuang($ruang),
            'data' => $this->monitor->dataRuang($ruang),
            'semuaRuang' => Ruang::orderBy('nama')->get(),
            'aksiTerakhir' => AksiPengawas::query()
                ->whereIn('sesi_id', SesiUjian::query()->where('ruang_id', $ruang->id)->whereIn('jadwal_id', JadwalUjian::where('status', JadwalUjian::AKTIF)->select('id'))->select('id'))
                ->with('pelaku:id,nama', 'sesi.siswa:id,nama')->latest('id')->limit(8)->get(),
        ]);
    }

    public function dataRuang(Request $request, Ruang $ruang): JsonResponse
    {
        $this->izinkanPengawas($request);

        return response()->json($this->monitor->dataRuang($ruang))->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, JadwalUjian $jadwal): View
    {
        $user = $request->user('web');
        $this->izinkanLihat($request, $jadwal);

        return view('staf.monitor.show', [
            'j' => $jadwal->load('mapel'),
            'bolehBertindak' => $this->monitor->bolehBertindak($user, $jadwal),
            'aksiBoleh' => $this->monitor->aksiBoleh($user, $jadwal),
            'ruang' => $jadwal->sesi()->whereNotNull('ruang_id')->with('ruang:id,nama')->get()->pluck('ruang')->unique('id')->sortBy('nama')->values(),
            'data' => $this->monitor->data($jadwal),
            'aksiTerakhir' => AksiPengawas::query()->whereIn('sesi_id', $jadwal->sesi()->select('id'))
                ->with('pelaku:id,nama', 'sesi.siswa:id,nama')->latest('id')->limit(8)->get(),
        ]);
    }

    public function data(Request $request, JadwalUjian $jadwal): JsonResponse
    {
        $this->izinkanLihat($request, $jadwal);

        if ($jadwal->status !== JadwalUjian::AKTIF) {
            return response()->json(['kode' => 'tidak_aktif', 'pesan' => 'Jadwal ini sudah tidak aktif.'], 409);
        }

        return response()->json($this->monitor->data($jadwal))->header('Cache-Control', 'no-store');
    }

    /** Riwayat pelanggaran satu siswa (untuk lembar rincian di HP); dimuat hanya saat lembar dibuka. */
    public function pelanggaran(Request $request, JadwalUjian $jadwal, SesiUjian $sesi): JsonResponse
    {
        $this->izinkanLihat($request, $jadwal);
        abort_unless($sesi->jadwal_id === $jadwal->id, 404);

        $daftar = Pelanggaran::query()->where('sesi_id', $sesi->id)->latest('created_at')->latest('id')->limit(30)->get()
            ->map(fn (Pelanggaran $p) => [
                'waktu' => $p->created_at?->format('H:i:s'),
                'jenis' => Pelanggaran::label($p->jenis),
                'dihitung' => (bool) $p->dihitung,
            ])->values();

        return response()->json(['pelanggaran' => $daftar])->header('Cache-Control', 'no-store');
    }

    /** Tindakan pengawas terhadap satu sesi siswa. */
    public function aksi(Request $request, JadwalUjian $jadwal, SesiUjian $sesi, string $aksi): JsonResponse
    {
        $user = $request->user('web');
        abort_unless($sesi->jadwal_id === $jadwal->id, 404);
        abort_unless(in_array($aksi, $this->monitor->aksiBoleh($user, $jadwal), true), 403, 'Anda tidak berwenang melakukan tindakan ini.');

        try {
            match ($aksi) {
                'aktifkan' => $this->monitor->aktifkanKembali($sesi, $user),
                'reset' => $this->monitor->resetPerangkat($sesi, $user),
                'waktu' => $this->monitor->tambahWaktu($sesi, $user, (int) $request->validate(['menit' => ['required', 'integer', 'min:1', 'max:'.LayananMonitor::MAKS_TAMBAH_MENIT]])['menit']),
                'selesai' => $this->monitor->paksaSelesai($sesi, $user),
                default => abort(404),
            };
        } catch (KesalahanUjian $e) {
            return response()->json(['kode' => $e->kode, 'pesan' => $e->getMessage()], $e->status);
        }

        $catatan = AksiPengawas::where('sesi_id', $sesi->id)->latest('id')->first();

        return response()->json([
            'ok' => true,
            'pesan' => 'Tindakan dicatat.',
            'catatan' => now()->format('H:i').' · '.$user->nama.' — '.AksiPengawas::LABEL[$catatan->aksi].' · '.$sesi->siswa->nama.($catatan->detail ? " ({$catatan->detail})" : ''),
        ]);
    }

    private function izinkanPengawas(Request $request): void
    {
        $user = $request->user('web');
        abort_unless($user->role === 'admin' || $user->mengawasi(), 403, 'Hanya pengawas yang dapat memantau per ruang.');
    }

    private function izinkanLihat(Request $request, JadwalUjian $jadwal): void
    {
        abort_unless($this->monitor->bolehLihat($request->user('web'), $jadwal), 404);
    }
}
