<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\JadwalUjian;
use App\Models\Jawaban;
use App\Models\PenyediaOtomatis;
use App\Models\Soal;
use App\Otomatis\KesalahanOtomatis;
use App\Services\KesalahanUjian;
use App\Services\LayananKoreksi;
use App\Services\LayananOtomatis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KoreksiController extends Controller
{
    private const PER_HALAMAN = 10;

    public function __construct(private LayananKoreksi $koreksi, private LayananOtomatis $otomatis) {}

    public function index(Request $request): View
    {
        $jadwal = $this->koreksi->jadwalUntuk($request->user('web'))->with('mapel')
            ->whereHas('soalBeku.soal', fn ($q) => $q->where('tipe', Soal::ESAI)->withTrashed())
            ->orderByRaw("case status when 'aktif' then 0 else 1 end")->orderByDesc('mulai_pada')->get();

        return view('staf.koreksi.index', ['jadwal' => $jadwal]);
    }

    public function show(Request $request, JadwalUjian $jadwal): View
    {
        $this->izinkan($request, $jadwal);

        return view('staf.koreksi.show', ['j' => $jadwal->load('mapel'), 'soal' => $this->koreksi->soalEsai($jadwal)]);
    }

    public function soal(Request $request, JadwalUjian $jadwal, Soal $soal): View
    {
        $this->izinkan($request, $jadwal);
        abort_unless($this->koreksi->soalMilik($jadwal, $soal), 404);

        $kueri = $this->koreksi->jawabanSoal($jadwal, $soal);
        $belum = $request->query('saring') === 'belum';
        if ($belum) {
            $kueri->whereNull('nilai_esai');
        }

        return view('staf.koreksi.soal', [
            'j' => $jadwal->load('mapel'),
            'soal' => $soal,
            'jawaban' => $kueri->paginate(self::PER_HALAMAN)->withQueryString(),
            'kemajuan' => $this->koreksi->kemajuan($jadwal, $soal),
            'belum' => $belum,
            'penyedia' => PenyediaOtomatis::aktif(),
            'sisa' => $this->otomatis->sisaHariIni($request->user('web')),
        ]);
    }

    public function nilai(Request $request, JadwalUjian $jadwal, Jawaban $jawaban): JsonResponse
    {
        $this->izinkan($request, $jadwal);
        abort_unless($this->koreksi->jawabanMilik($jadwal, $jawaban), 404);

        $mentah = $request->input('nilai');
        if (is_string($mentah)) {
            $mentah = str_replace(',', '.', trim($mentah));
        }
        if ($mentah !== null && $mentah !== '' && ! is_numeric($mentah)) {
            return response()->json(['kode' => 'nilai_salah', 'pesan' => 'Nilai harus berupa angka 0 sampai 100.'], 422);
        }

        try {
            $baru = $this->koreksi->simpanNilai($jawaban, ($mentah === null || $mentah === '') ? null : round((float) $mentah, 2), $request->user('web'));
        } catch (KesalahanUjian $e) {
            return response()->json(['kode' => $e->kode, 'pesan' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'ok' => true,
            'nilai' => $baru->nilai_esai,
            'kemajuan' => $this->koreksi->kemajuan($jadwal, $jawaban->soal),
        ]);
    }

    /** Minta saran nilai "Otomatis" untuk beberapa jawaban satu soal (hanya saran). */
    public function saran(Request $request, JadwalUjian $jadwal, Soal $soal): JsonResponse
    {
        $this->izinkan($request, $jadwal);
        abort_unless($this->koreksi->soalMilik($jadwal, $soal), 404);

        if ($jadwal->nilaiDikunci()) {
            return response()->json(['kode' => 'dikunci', 'pesan' => 'Nilai jadwal ini sudah dikunci.'], 409);
        }

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.LayananOtomatis::MAKS_SARAN],
            'ids.*' => ['integer'],
        ]);

        $jawaban = $this->koreksi->jawabanSoal($jadwal, $soal)->reorder()->whereIn('id', $data['ids'])->get();
        if ($jawaban->isEmpty()) {
            return response()->json(['kode' => 'kosong', 'pesan' => 'Tidak ada jawaban yang bisa dimintakan saran.'], 422);
        }

        try {
            $saran = $this->otomatis->sarankanNilai(
                $request->user('web'), $soal, $jadwal->mapel->nama,
                $jawaban->map(fn (Jawaban $j) => ['id' => $j->id, 'teks' => (string) $j->teks_esai])->all(),
            );
        } catch (KesalahanOtomatis $e) {
            return response()->json(['kode' => 'otomatis', 'pesan' => $e->getMessage()], 422);
        }

        $hasil = [];
        foreach ($jawaban as $j) {
            if (isset($saran[$j->id])) {
                $this->koreksi->simpanSaran($j, $saran[$j->id]['nilai'], $saran[$j->id]['catatan']);
                $hasil[$j->id] = $saran[$j->id];
            }
        }

        return response()->json(['ok' => true, 'saran' => $hasil, 'sisa' => $this->otomatis->sisaHariIni($request->user('web'))]);
    }

    private function izinkan(Request $request, JadwalUjian $jadwal): void
    {
        abort_unless($this->koreksi->boleh($request->user('web'), $jadwal), 404);
    }
}
