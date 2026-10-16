<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\JadwalUjian;
use App\Services\KesalahanJadwal;
use App\Services\LayananKartu;
use App\Services\LayananMonitor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KartuUjianController extends Controller
{
    public function __construct(private LayananKartu $kartu, private LayananMonitor $monitor) {}

    /** Halaman pilihan kartu ujian (admin). */
    public function index(JadwalUjian $jadwal): View
    {
        $jadwal->load('mapel', 'kelas', 'denah');
        $peserta = $this->kartu->peserta($jadwal);

        return view('staf.kartu.index', [
            'j' => $jadwal,
            'peserta' => $peserta,
            'perKelas' => $peserta->groupBy('kelas_id')->map->count(),
            'tanpaTempat' => $peserta->whereNull('ruang_id')->count(),
            'maks' => LayananKartu::MAKS_SANDI_PER_PERMINTAAN,
        ]);
    }

    /** Kartu tanpa kata sandi. */
    public function cetak(Request $request, JadwalUjian $jadwal): View
    {
        $data = $request->validate(['kelas' => ['nullable', 'array'], 'kelas.*' => ['integer']]);
        $kelas = isset($data['kelas']) ? array_map('intval', $data['kelas']) : null;

        return view('staf.kartu.cetak', [
            'j' => $jadwal->load('mapel'),
            'peserta' => $this->urut($this->kartu->peserta($jadwal, $kelas)),
            'sandi' => null,
        ]);
    }

    /** Buat kata sandi sementara baru untuk kelas terpilih, lalu cetak kartu atau unduh Excel. Sandi tidak disimpan polos. */
    public function sandi(Request $request, JadwalUjian $jadwal): Response|StreamedResponse|View|\Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'kelas' => ['required', 'array', 'min:1'],
            'kelas.*' => ['integer'],
            'format' => ['required', 'in:cetak,excel'],
        ], ['kelas.required' => 'Pilih minimal satu kelas.']);

        $peserta = $this->urut($this->kartu->peserta($jadwal->load('mapel'), array_map('intval', $data['kelas'])));

        try {
            $sandi = $this->kartu->buatSandi($peserta, $request->user('web'), $jadwal);
        } catch (KesalahanJadwal $e) {
            return back()->with('gagal', $e->getMessage());
        }

        if ($data['format'] === 'excel') {
            return $this->excel($jadwal, $peserta, $sandi);
        }

        return response()->view('staf.kartu.cetak', ['j' => $jadwal, 'peserta' => $peserta, 'sandi' => $sandi])
            ->header('Cache-Control', 'no-store, private');
    }

    /** Daftar hadir per ruang: admin, atau pengawas yang ditugaskan (dibatasi ruangnya). */
    public function hadir(Request $request, JadwalUjian $jadwal): View
    {
        $user = $request->user('web');
        abort_unless($user->role === 'admin' || ($user->role === 'pengawas' && $this->monitor->bolehLihat($user, $jadwal)), 403, 'Anda tidak berwenang mencetak daftar hadir ini.');

        $batas = $this->monitor->ruangDitugaskan($user, $jadwal);
        $peserta = $this->kartu->peserta($jadwal->load('mapel'))
            ->when($batas !== null, fn ($c) => $c->filter(fn ($p) => in_array($p['ruang_id'], $batas, true)))
            ->when($request->filled('ruang') && $batas === null, fn ($c) => $c->filter(fn ($p) => $p['ruang_id'] === (int) $request->query('ruang')));

        $perRuang = $peserta->sortBy([['meja', 'asc'], ['nama', 'asc']])->groupBy(fn ($p) => $p['ruang'] ?? 'Belum ditempatkan')->sortKeys();

        return view('staf.kartu.hadir', ['j' => $jadwal, 'perRuang' => $perRuang]);
    }

    private function urut($peserta)
    {
        return $peserta->sortBy([['kelas', 'asc'], ['nama', 'asc']])->values();
    }

    private function excel(JadwalUjian $jadwal, $peserta, array $sandi): StreamedResponse
    {
        $buku = new Spreadsheet;
        $lembar = $buku->getActiveSheet()->setTitle('Kartu ujian');
        $baris = [['Nama', 'Nama pengguna', 'Kelas', 'Kata sandi sementara', 'Ruang', 'Meja']];
        foreach ($peserta as $p) {
            $baris[] = [$p['nama'], $p['pengguna'], $p['kelas'], $sandi[$p['siswa']->id] ?? '', $p['ruang'] ?? '', $p['meja'] ?? ''];
        }
        foreach ($baris as $r => $isi) {
            foreach ($isi as $k => $nilai) {
                if ($nilai !== '' && $nilai !== null) {
                    $lembar->getCell([$k + 1, $r + 1])->setValueExplicit((string) $nilai, DataType::TYPE_STRING); // bukan rumus
                }
            }
        }
        foreach (range('A', 'F') as $k) {
            $lembar->getColumnDimension($k)->setAutoSize(true);
        }
        $lembar->getStyle('A1:F1')->getFont()->setBold(true);

        return response()->streamDownload(fn () => (new Xlsx($buku))->save('php://output'), 'kartu-ujian-'.$jadwal->id.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
