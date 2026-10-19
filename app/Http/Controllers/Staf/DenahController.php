<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Denah;
use App\Models\DenahPenempatan;
use App\Models\DenahRuang;
use App\Models\Kelas;
use App\Models\Ruang;
use App\Models\Siswa;
use App\Services\KesalahanJadwal;
use App\Services\LayananDenah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Menu Ruang (seperti aplikasi lama): ruang tetap, siswa ditugaskan ke ruang, nomor meja = urutan. */
class DenahController extends Controller
{
    public function __construct(private LayananDenah $layanan) {}

    public function index(Request $request): View
    {
        $denah = $this->layanan->utama($request->user('web'));
        $tahunId = $this->tahunId($denah);

        $penempatan = DenahPenempatan::where('denah_id', $denah->id)->get(['siswa_id', 'ruang_id']);
        $terisi = $penempatan->countBy('ruang_id');

        $kelasSiswa = $this->kelasSiswa($penempatan->pluck('siswa_id')->all(), $tahunId);
        $komposisi = $penempatan->groupBy('ruang_id')->map(fn ($g) => $g->map(fn ($p) => $kelasSiswa[$p->siswa_id]->nama ?? '—')->countBy()->sortKeys());

        $belumPunyaRuang = Siswa::where('status', 'aktif')->whereNotIn('id', $penempatan->pluck('siswa_id'))->count();

        return view('staf.denah.index', [
            'ruang' => Ruang::orderBy('nama')->get(),
            'terisi' => $terisi,
            'belumPunyaRuang' => $belumPunyaRuang,
            'komposisi' => $komposisi,
            'jumlah' => $penempatan->count(),
        ]);
    }

    public function create(): View
    {
        return view('staf.denah.form', ['ruang' => null]);
    }

    public function edit(Ruang $ruang): View
    {
        return view('staf.denah.form', ['ruang' => $ruang]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validasiRuang($request);
        $ruang = Ruang::create($data);
        $this->layanan->utama($request->user('web'));
        AuditLog::catat('ruang.tambah', "ruang {$ruang->id}", $request->user('web'));

        return redirect()->route('staf.ruang.index')->with('berhasil', 'Ruang baru berhasil ditambahkan.');
    }

    public function update(Request $request, Ruang $ruang): RedirectResponse
    {
        $data = $this->validasiRuang($request, $ruang);
        $denah = $this->layanan->utama($request->user('web'));

        if ($data['campur']) {
            $maks = $ruang->porsiTingkat($data['kapasitas']);
            foreach ($this->layanan->komposisiTingkat($denah, $ruang) as $tingkat => $n) {
                if ($n > $maks) {
                    return back()->withInput()->with('gagal', "Ruang ini berisi {$n} siswa tingkat {$tingkat}, melebihi 50% kapasitas ({$maks}). Keluarkan sebagian kelasnya dulu.");
                }
            }
        }
        $ruang->update($data);
        $this->layanan->aturRuang($denah, [$ruang->id => $ruang->kapasitas], $request->user('web'));
        AuditLog::catat('ruang.ubah', "ruang {$ruang->id}", $request->user('web'));

        return redirect()->route('staf.ruang.index')->with('berhasil', 'Data ruang berhasil diperbarui.');
    }

    public function destroy(Request $request, Ruang $ruang): RedirectResponse
    {
        $dilepas = DenahPenempatan::where('ruang_id', $ruang->id)->count();
        DB::transaction(function () use ($ruang) {
            DenahPenempatan::where('ruang_id', $ruang->id)->delete();
            DenahRuang::where('ruang_id', $ruang->id)->delete();
            $ruang->delete();
        });
        $this->layanan->perbaruiJadwal($this->layanan->utama());
        AuditLog::catat('ruang.hapus', "ruang {$ruang->id}", $request->user('web'));

        return redirect()->route('staf.ruang.index')->with('berhasil', 'Ruang berhasil dihapus.'.($dilepas > 0 ? " {$dilepas} siswa dilepas dari ruang (data siswa tetap ada)." : ''));
    }

    /** Masukkan satu atau beberapa kelas sekaligus ke ruang ini. */
    public function masukkanKelas(Request $request, Ruang $ruang): RedirectResponse
    {
        $denah = $this->layanan->utama($request->user('web'));
        $data = $request->validate([
            'kelas_ids' => ['required', 'array', 'min:1'],
            'kelas_ids.*' => ['integer', Rule::exists('kelas', 'id')->where('tahun_ajaran_id', $this->tahunId($denah))],
        ], ['kelas_ids.required' => 'Pilih minimal satu kelas.'], ['kelas_ids' => 'Kelas']);

        $h = $this->layanan->masukkanKelas($denah, $ruang, array_map('intval', $data['kelas_ids']), $request->user('web'));

        if ($h['masuk'] === 0) {
            return back()->with('catatan', $ruang->campur
                ? 'Tidak ada siswa yang masuk: ruang penuh, porsi tingkat sudah 50%, atau kelas itu sudah punya ruang semua.'
                : 'Tidak ada siswa yang masuk: ruang penuh atau kelas itu sudah punya ruang semua.');
        }
        $pesan = "{$h['masuk']} siswa dimasukkan ke {$ruang->nama}.";
        if ($h['sisa'] > 0) {
            return back()->with('catatan', "{$pesan} {$h['sisa']} siswa belum muat; masukkan sisanya ke ruang lain.");
        }

        return back()->with('berhasil', $pesan);
    }

    public function keluarkanKelas(Request $request, Ruang $ruang, Kelas $kelas): RedirectResponse
    {
        $n = $this->layanan->keluarkanKelas($this->layanan->utama(), $ruang, $kelas, $request->user('web'));

        return back()->with('berhasil', "{$n} siswa kelas {$kelas->nama} dikeluarkan dari ruang.");
    }

    // ── Satu ruang ("Lihat Siswa") ──────────────────────────────────────────

    public function show(Request $request, Ruang $ruang): View
    {
        $denah = $this->layanan->utama($request->user('web'));
        $dr = $this->ruangDenah($denah, $ruang);
        $baris = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)->with('siswa:id,nama,nisn')->orderBy('meja')->orderBy('posisi')->get();
        $kelasSiswa = $this->kelasSiswa($baris->pluck('siswa_id')->all(), $this->tahunId($denah));

        return view('staf.denah.ruang', [
            'd' => $denah,
            'ruang' => $ruang,
            'dr' => $dr,
            'baris' => $baris,
            'kelasSiswa' => $kelasSiswa,
            'komposisi' => $baris->map(fn ($p) => $kelasSiswa[$p->siswa_id]->nama ?? '—')->countBy()->sortKeys(),
            'tingkatIsi' => $this->layanan->komposisiTingkat($denah, $ruang),
            'kelasBelum' => $this->kelasBelumPunyaRuang($denah),
            'idKelasRuang' => Kelas::whereIn('nama', $baris->map(fn ($p) => $kelasSiswa[$p->siswa_id]->nama ?? null)->filter()->unique())->where('tahun_ajaran_id', $this->tahunId($denah))->pluck('nama', 'id'),
            'ruangLain' => Ruang::where('id', '!=', $ruang->id)->orderBy('nama')->get(),
        ]);
    }

    public function urutan(Request $request, Ruang $ruang): JsonResponse
    {
        $denah = $this->layanan->utama();
        $dr = $this->ruangDenah($denah, $ruang);
        $data = $request->validate(['ids' => ['required', 'array', 'max:600'], 'ids.*' => ['integer']]);
        $this->layanan->urutkan($denah, $dr, $data['ids'], $request->user('web'));

        return response()->json(['ok' => true]);
    }

    public function urutkanPerKelas(Request $request, Ruang $ruang): RedirectResponse
    {
        $denah = $this->layanan->utama();
        $this->layanan->urutkanPerKelas($denah, $this->ruangDenah($denah, $ruang), $request->user('web'));

        return back()->with('berhasil', 'Urutan dikembalikan menurut kelas dan nama.');
    }

    /** Pindahkan siswa ke ruang lain, atau keluarkan dari ruang (ruang_id = "keluar"). */
    public function pindah(Request $request, Siswa $siswa): RedirectResponse
    {
        $data = $request->validate(['ruang_id' => ['required', 'regex:/^(\d+|keluar)$/']], [], ['ruang_id' => 'Ruang tujuan']);
        $tujuan = (string) $data['ruang_id'] === 'keluar' ? null : (int) $data['ruang_id'];

        try {
            $this->layanan->pindah($this->layanan->utama(), $siswa, $tujuan, $request->user('web'));
        } catch (KesalahanJadwal $e) {
            return back()->with('gagal', $e->getMessage());
        }

        return back()->with('berhasil', $tujuan === null ? "{$siswa->nama} dikeluarkan dari ruang." : "{$siswa->nama} dipindahkan.");
    }

    public function lepas(Request $request, Siswa $siswa): RedirectResponse
    {
        $this->layanan->pindah($this->layanan->utama(), $siswa, null, $request->user('web'));

        return back()->with('berhasil', 'Siswa dikeluarkan dari ruang.');
    }

    public function cetakMeja(Ruang $ruang): View
    {
        $denah = $this->layanan->utama();
        $this->ruangDenah($denah, $ruang);
        $baris = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)->with('siswa:id,nama,nisn')->orderBy('meja')->orderBy('posisi')->get();

        return view('staf.denah.cetak-meja', [
            'ruang' => $ruang, 'baris' => $baris,
            'kelasSiswa' => $this->kelasSiswa($baris->pluck('siswa_id')->all(), $this->tahunId($denah)),
        ]);
    }

    public function absen(Ruang $ruang): StreamedResponse
    {
        $denah = $this->layanan->utama();
        $this->ruangDenah($denah, $ruang);
        $baris = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)->with('siswa:id,nama,nisn')->orderBy('meja')->orderBy('posisi')->get();
        $kelasSiswa = $this->kelasSiswa($baris->pluck('siswa_id')->all(), $this->tahunId($denah));

        $buku = new Spreadsheet;
        $lembar = $buku->getActiveSheet()->setTitle('Absen');
        $isi = [["Daftar hadir — {$ruang->nama}"], [config('ujian.sekolah')], [], ['No Meja', 'Nama', 'NISN', 'Kelas', 'Tanda tangan']];
        foreach ($baris as $p) {
            $isi[] = [$p->meja, $p->siswa->nama, (string) $p->siswa->nisn, $kelasSiswa[$p->siswa_id]->nama ?? '', ''];
        }
        foreach ($isi as $r => $kolom) {
            foreach ($kolom as $k => $nilai) {
                if ($nilai !== '' && $nilai !== null) {
                    $lembar->getCell([$k + 1, $r + 1])->setValueExplicit(is_int($nilai) ? $nilai : (string) $nilai, is_int($nilai) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
            }
        }
        foreach (range('A', 'E') as $k) {
            $lembar->getColumnDimension($k)->setAutoSize(true);
        }
        $lembar->getStyle('A4:E4')->getFont()->setBold(true);

        $nama = 'absen-'.preg_replace('/[^A-Za-z0-9]+/', '-', $ruang->nama).'.xlsx';

        return response()->streamDownload(fn () => (new Xlsx($buku))->save('php://output'), $nama, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Kelas (tahun ajaran berjalan) beserta jumlah siswa aktifnya yang belum punya ruang; hanya yang masih ada sisa. */
    private function kelasBelumPunyaRuang(Denah $denah)
    {
        $hitung = DB::table('keanggotaan_kelas as k')->join('siswa as s', 's.id', '=', 'k.siswa_id')
            ->where('k.status', 'aktif')->where('s.status', 'aktif')
            ->whereNotIn('s.id', DenahPenempatan::where('denah_id', $denah->id)->select('siswa_id'))
            ->select('k.kelas_id', DB::raw('count(distinct s.id) as n'))->groupBy('k.kelas_id')->pluck('n', 'kelas_id');

        return Kelas::where('tahun_ajaran_id', $this->tahunId($denah))->orderBy('tingkat')->orderBy('nama')->get()
            ->filter(fn ($k) => ($hitung[$k->id] ?? 0) > 0)->each(fn ($k) => $k->belum = (int) $hitung[$k->id])->values();
    }

    /** @return array{nama:string,kapasitas:int,campur:bool} */
    private function validasiRuang(Request $request, ?Ruang $ruang = null): array
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:50', Rule::unique('ruang', 'nama')->ignore($ruang?->id)],
            'kapasitas' => ['required', 'integer', 'between:1,500'],
            'campur' => ['nullable', 'boolean'],
        ], ['nama.unique' => 'Nama ruang itu sudah ada.'], ['nama' => 'Nama ruang', 'kapasitas' => 'Kapasitas']);
        $data['nama'] = trim($data['nama']);
        $data['campur'] = (bool) ($data['campur'] ?? false);

        return $data;
    }

    private function ruangDenah(Denah $denah, Ruang $ruang): DenahRuang
    {
        return DenahRuang::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)->firstOrFail();
    }

    private function tahunId(Denah $denah): int
    {
        return $this->layanan->tahunId($denah);
    }

    /** Kelas (pada tahun ajaran yang dipakai) tiap siswa. @param list<int> $siswaIds */
    private function kelasSiswa(array $siswaIds, int $tahunId)
    {
        return DB::table('keanggotaan_kelas as k')->join('kelas as kl', 'kl.id', '=', 'k.kelas_id')
            ->whereIn('k.siswa_id', $siswaIds)->where('kl.tahun_ajaran_id', $tahunId)
            ->select('k.siswa_id', 'kl.nama', 'kl.tingkat')->get()->unique('siswa_id')->keyBy('siswa_id');
    }
}
