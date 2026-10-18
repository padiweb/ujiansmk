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
use App\Services\LayananKunciNilai;
use App\Services\LayananOtomatis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KoreksiController extends Controller
{
    private const PER_HALAMAN = 10;

    public function __construct(private LayananKoreksi $koreksi, private LayananOtomatis $otomatis, private LayananKunciNilai $kunci) {}

    /** Pilih semester, mata pelajaran, dan kelas yang diampu sebelum mengoreksi. */
    public function index(Request $request): View
    {
        return view('staf.koreksi.index', $this->koreksi->dataPilihan(
            $request->user('web'), $request->query('periode'), $request->query('mapel'), $request->query('kelas'), hanyaEsai: true,
        ));
    }

    public function show(Request $request, JadwalUjian $jadwal): View
    {
        $kelas = $this->lingkup($request, $jadwal);

        return view('staf.koreksi.show', [
            'j' => $jadwal->load('mapel'),
            'soal' => $this->koreksi->soalEsai($jadwal, $kelas),
            'kelasPilihan' => $this->namaKelas($jadwal, $kelas),
            'kelasId' => $request->query('kelas'),
        ]);
    }

    public function soal(Request $request, JadwalUjian $jadwal, Soal $soal): View
    {
        $kelas = $this->lingkup($request, $jadwal);
        abort_unless($this->koreksi->soalMilik($jadwal, $soal), 404);

        $kueri = $this->koreksi->jawabanSoal($jadwal, $soal, $kelas);
        $belum = $request->query('saring') === 'belum';
        if ($belum) {
            $kueri->whereNull('nilai_esai');
        }

        return view('staf.koreksi.soal', [
            'j' => $jadwal->load('mapel'),
            'soal' => $soal,
            'jawaban' => $kueri->paginate(self::PER_HALAMAN)->withQueryString(),
            'kemajuan' => $this->koreksi->kemajuan($jadwal, $soal, $kelas),
            'belum' => $belum,
            'kelasPilihan' => $this->namaKelas($jadwal, $kelas),
            'kelasId' => $request->query('kelas'),
            'terkunci' => $this->kunci->kelasTerkunci($jadwal, $kelas) !== [],
            'penyedia' => PenyediaOtomatis::aktif(),
            'sisa' => $this->otomatis->sisaHariIni($request->user('web')),
        ]);
    }

    public function nilai(Request $request, JadwalUjian $jadwal, Jawaban $jawaban): JsonResponse
    {
        $kelas = $this->lingkup($request, $jadwal, tanpaPilihan: true);
        abort_unless($this->koreksi->jawabanMilik($jadwal, $jawaban, $kelas), 404);

        $mentah = $request->input('nilai');
        if (is_string($mentah)) {
            $mentah = str_replace(',', '.', trim($mentah));
        }
        if ($mentah !== null && $mentah !== '' && ! is_numeric($mentah)) {
            return response()->json(['kode' => 'nilai_salah', 'pesan' => 'Nilai harus berupa angka 0 sampai 100.'], 422);
        }

        $sesi = $jawaban->sesi;
        $adaKunci = $this->kunci->terkunci($sesi);

        try {
            $baru = $this->koreksi->simpanNilai($jawaban, ($mentah === null || $mentah === '') ? null : round((float) $mentah, 2), $request->user('web'));
        } catch (KesalahanUjian $e) {
            return response()->json(['kode' => $e->kode, 'pesan' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'ok' => true,
            'nilai' => $baru->nilai_esai,
            'kunci_dibuka' => $adaKunci,
            'kemajuan' => $this->koreksi->kemajuan($jadwal, $jawaban->soal, $kelas),
        ]);
    }

    /** Nilai yang sama untuk semua jawaban satu soal (di kelas yang dipilih). */
    public function nilaiSemua(Request $request, JadwalUjian $jadwal, Soal $soal): RedirectResponse
    {
        $kelas = $this->lingkup($request, $jadwal, $request->input('kelas'));
        abort_unless($this->koreksi->soalMilik($jadwal, $soal), 404);

        $mentah = str_replace(',', '.', trim((string) $request->input('nilai')));
        if ($mentah === '' || ! is_numeric($mentah)) {
            return back()->with('gagal', 'Isi nilai berupa angka 0 sampai 100.');
        }

        $adaKunci = $this->kunci->kelasTerkunci($jadwal, $kelas) !== [];
        try {
            $n = $this->koreksi->nilaiSemua($jadwal, $soal, round((float) $mentah, 2), $request->boolean('timpa'), $kelas, $request->user('web'));
        } catch (KesalahanUjian $e) {
            return back()->with('gagal', $e->getMessage());
        }

        if ($n === 0) {
            return back()->with('info', 'Tidak ada jawaban yang diubah. Semua jawaban sudah bernilai (centang "timpa" bila ingin menggantinya).');
        }

        $pesan = "Nilai {$mentah} diberikan ke {$n} jawaban.".($adaKunci ? ' Kunci nilai dibuka karena nilai berubah; kunci lagi di Rekap Nilai setelah selesai.' : '');

        return back()->with('berhasil', $pesan);
    }

    /** Minta saran nilai "Otomatis" untuk beberapa jawaban satu soal (hanya saran). */
    public function saran(Request $request, JadwalUjian $jadwal, Soal $soal): JsonResponse
    {
        $kelas = $this->lingkup($request, $jadwal, tanpaPilihan: true);
        abort_unless($this->koreksi->soalMilik($jadwal, $soal), 404);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.LayananOtomatis::MAKS_SARAN],
            'ids.*' => ['integer'],
        ]);

        $jawaban = $this->koreksi->jawabanSoal($jadwal, $soal, $kelas)->reorder()->whereIn('id', $data['ids'])->get();
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

    /**
     * Kelas yang boleh ditangani (dipersempit ?kelas= bila ada). 404 bila pengguna tidak mengampu kelas itu.
     *
     * @return list<int>
     */
    private function lingkup(Request $request, JadwalUjian $jadwal, int|string|null $pilihan = null, bool $tanpaPilihan = false): array
    {
        $pilih = $tanpaPilihan ? null : ($pilihan ?? $request->query('kelas'));
        $kelas = $this->koreksi->lingkup($request->user('web'), $jadwal, $pilih);
        abort_if($kelas === [] || ! $this->koreksi->boleh($request->user('web'), $jadwal), 404);

        return $kelas;
    }

    /** @param list<int> $kelas */
    private function namaKelas(JadwalUjian $jadwal, array $kelas): string
    {
        return $jadwal->kelas()->whereIn('kelas.id', $kelas)->orderBy('nama')->pluck('nama')->implode(', ');
    }
}
