<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\JadwalUjian;
use App\Services\KesalahanUjian;
use App\Services\LayananKoreksi;
use App\Services\LayananRekap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RekapController extends Controller
{
    public function __construct(private LayananRekap $rekap, private LayananKoreksi $akses) {}

    /** Pilih semester, mata pelajaran, dan kelas yang diampu sebelum membuka rekap. */
    public function index(Request $request): View
    {
        return view('staf.rekap.index', $this->akses->dataPilihan(
            $request->user('web'), $request->query('periode'), $request->query('mapel'), $request->query('kelas'), hanyaEsai: false,
        ) + ['kunciPerKelas' => true]);
    }

    public function show(Request $request, JadwalUjian $jadwal): View
    {
        $kelas = $this->izinkan($request, $jadwal);

        return view('staf.rekap.show', [
            'j' => $jadwal->load('mapel'),
            'kelasPilihan' => $jadwal->kelas()->whereIn('kelas.id', $kelas)->orderBy('nama')->pluck('nama')->implode(', '),
            'kelasId' => $request->query('kelas'),
        ] + $this->rekap->data($jadwal, $kelas));
    }

    public function cetak(Request $request, JadwalUjian $jadwal): View
    {
        $kelas = $this->izinkan($request, $jadwal);

        return view('staf.rekap.cetak', ['j' => $jadwal->load('mapel'), 'kelasPilihan' => $jadwal->kelas()->whereIn('kelas.id', $kelas)->orderBy('nama')->pluck('nama')->implode(', ')] + $this->rekap->data($jadwal, $kelas));
    }

    public function ekspor(Request $request, JadwalUjian $jadwal): StreamedResponse
    {
        $kelas = $this->izinkan($request, $jadwal);
        $data = $this->rekap->data($jadwal, $kelas);

        $buku = new Spreadsheet;
        $lembar = $buku->getActiveSheet()->setTitle('Rekap nilai');
        $judul = [['Rekap nilai: '.$jadwal->nama], [$jadwal->mapel->nama.' · KKM '.$data['kkm']], []];
        $kepala = ['No', 'Nama', 'NISN', 'Kelas', 'Ruang', 'Status', 'Benar', 'Salah', 'Kosong', 'Skor PG', 'Skor esai', 'Skor akhir', 'Keterangan', 'Pelanggaran'];

        $this->tulis($lembar, array_merge($judul, [$kepala]));
        $r = count($judul) + 2;
        foreach ($data['baris'] as $i => $b) {
            $this->tulis($lembar, [[
                $i + 1, $b['nama'], (string) $b['nisn'], $b['kelas'], $b['ruang'] ?? '', $this->namaStatus($b['status']),
                $b['benar'], $b['salah'], $b['kosong'], $b['skor_pg'], $b['skor_esai'], $b['skor_akhir'], $b['keterangan'], $b['pelanggaran'],
            ]], $r++);
        }
        foreach (range('A', 'N') as $k) {
            $lembar->getColumnDimension($k)->setAutoSize(true);
        }
        $lembar->getStyle('A4:N4')->getFont()->setBold(true);

        $nama = 'rekap-'.preg_replace('/[^A-Za-z0-9]+/', '-', $jadwal->nama).'.xlsx';

        return response()->streamDownload(fn () => (new Xlsx($buku))->save('php://output'), $nama, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function kunci(Request $request, JadwalUjian $jadwal): RedirectResponse
    {
        $kelas = $this->izinkan($request, $jadwal, $request->input('kelas'));

        return $this->jalankan(fn () => $this->rekap->kunci($jadwal, $request->user('web'), $kelas), 'Nilai dikunci. Nilai ini sekarang bisa dipakai untuk rapor.');
    }

    public function bukaKunci(Request $request, JadwalUjian $jadwal): RedirectResponse
    {
        $kelas = $this->izinkan($request, $jadwal, $request->input('kelas'));

        return $this->jalankan(fn () => $this->rekap->bukaKunci($jadwal, $request->user('web'), $kelas), 'Kunci nilai dibuka. Ubah atau tambahkan nilai, lalu kunci lagi.');
    }

    public function tampilkan(Request $request, JadwalUjian $jadwal): RedirectResponse
    {
        $this->izinkan($request, $jadwal, null, tanpaPilihan: true);

        return $this->jalankan(fn () => $this->rekap->tampilkanKeSiswa($jadwal, $request->user('web')), 'Hasil sekarang tampil kepada siswa.');
    }

    private function jalankan(callable $aksi, string $pesan): RedirectResponse
    {
        try {
            $aksi();
        } catch (KesalahanUjian $e) {
            return back()->with('gagal', $e->getMessage());
        }

        return back()->with('berhasil', $pesan);
    }

    /** @return list<int> kelas yang boleh ditangani (dipersempit ?kelas= bila ada) */
    private function izinkan(Request $request, JadwalUjian $jadwal, int|string|null $pilihan = null, bool $tanpaPilihan = false): array
    {
        $kelas = $this->akses->lingkup($request->user('web'), $jadwal, $tanpaPilihan ? null : ($pilihan ?? $request->query('kelas')));
        abort_if($kelas === [] || ! $this->akses->boleh($request->user('web'), $jadwal), 404);

        return $kelas;
    }

    private function namaStatus(string $status): string
    {
        return ['belum' => 'Belum mulai', 'berlangsung' => 'Mengerjakan', 'selesai' => 'Selesai', 'diblokir' => 'Terblokir'][$status] ?? $status;
    }

    /** Tulis baris ke lembar; teks selalu sebagai string agar tidak ditafsirkan sebagai rumus. */
    private function tulis($lembar, array $baris, int $mulai = 1): void
    {
        foreach ($baris as $i => $isi) {
            foreach (array_values($isi) as $k => $nilai) {
                $sel = $lembar->getCell([$k + 1, $mulai + $i]);
                if (is_int($nilai) || is_float($nilai)) {
                    $sel->setValueExplicit($nilai, DataType::TYPE_NUMERIC);
                } elseif ($nilai !== null && $nilai !== '') {
                    $sel->setValueExplicit((string) $nilai, DataType::TYPE_STRING);
                }
            }
        }
    }
}
