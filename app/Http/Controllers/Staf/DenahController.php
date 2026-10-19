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
use App\Models\TahunAjaran;
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

        $edit = $request->filled('edit_id') ? Ruang::find((int) $request->query('edit_id')) : null;
        $belumPunyaRuang = Siswa::where('status', 'aktif')->whereNotIn('id', $penempatan->pluck('siswa_id'))->count();

        return view('staf.denah.index', [
            'ruang' => Ruang::orderBy('nama')->get(),
            'terisi' => $terisi,
            'edit' => $edit,
            'belumPunyaRuang' => $belumPunyaRuang,
            'jumlah' => $penempatan->count(),
            'daftarKelas' => Kelas::where('tahun_ajaran_id', $tahunId)->orderBy('tingkat')->orderBy('nama')->get(),
        ]);
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
        $ruang->update($data);
        $denah = $this->layanan->utama($request->user('web'));
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

    public function bagi(Request $request): RedirectResponse
    {
        $denah = $this->layanan->utama($request->user('web'));
        $data = $request->validate([
            'kelas_ids' => ['required', 'array', 'min:1'],
            'kelas_ids.*' => ['integer', Rule::exists('kelas', 'id')->where('tahun_ajaran_id', $this->tahunId($denah))],
            'campur' => ['nullable', 'boolean'],
        ], ['kelas_ids.required' => 'Pilih minimal satu kelas.'], ['kelas_ids' => 'Kelas']);

        try {
            $h = $this->layanan->bagi($denah, array_map('intval', $data['kelas_ids']), $request->user('web'), null, (bool) ($data['campur'] ?? false));
        } catch (KesalahanJadwal $e) {
            return back()->with('gagal', $e->getMessage())->withInput();
        }
        $this->layanan->ikatJadwalUtama($denah);

        $pesan = "{$h['ditempatkan']} siswa ditempatkan di ruang.";
        if ($h['tanpa_tempat'] > 0) {
            return back()->with('catatan', "{$pesan} {$h['tanpa_tempat']} siswa belum mendapat ruang: naikkan kapasitas atau tambah ruang, lalu bagi ulang.");
        }
        if ($h['meja_sejenis'] > 0) {
            return back()->with('catatan', "{$pesan} {$h['meja_sejenis']} meja terpaksa berisi siswa dari tingkat yang sama.");
        }

        return back()->with('berhasil', $pesan);
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

    /** @return array{nama:string,kapasitas:int} */
    private function validasiRuang(Request $request, ?Ruang $ruang = null): array
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:50', Rule::unique('ruang', 'nama')->ignore($ruang?->id)],
            'kapasitas' => ['required', 'integer', 'between:1,500'],
        ], ['nama.unique' => 'Nama ruang itu sudah ada.'], ['nama' => 'Nama ruang', 'kapasitas' => 'Kapasitas']);
        $data['nama'] = trim($data['nama']);

        return $data;
    }

    private function ruangDenah(Denah $denah, Ruang $ruang): DenahRuang
    {
        return DenahRuang::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)->firstOrFail();
    }

    private function tahunId(Denah $denah): int
    {
        return (int) (TahunAjaran::where('aktif', true)->value('id') ?? $denah->periode()->value('tahun_ajaran_id'));
    }

    /** Kelas (pada tahun ajaran yang dipakai) tiap siswa. @param list<int> $siswaIds */
    private function kelasSiswa(array $siswaIds, int $tahunId)
    {
        return DB::table('keanggotaan_kelas as k')->join('kelas as kl', 'kl.id', '=', 'k.kelas_id')
            ->whereIn('k.siswa_id', $siswaIds)->where('kl.tahun_ajaran_id', $tahunId)
            ->select('k.siswa_id', 'kl.nama', 'kl.tingkat')->get()->unique('siswa_id')->keyBy('siswa_id');
    }
}
