<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Denah;
use App\Models\DenahPenempatan;
use App\Models\DenahRuang;
use App\Models\KegiatanUjian;
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

class DenahController extends Controller
{
    public function __construct(private LayananDenah $layanan) {}

    /** Pilih kegiatan; denahnya dibuka (dibuat otomatis bila belum ada). */
    public function index(): View
    {
        return view('staf.denah.index', [
            'kegiatan' => KegiatanUjian::with('periode.tahunAjaran', 'denah')->withCount('jadwal')->orderByDesc('aktif')->orderByDesc('periode_id')->orderBy('nama')->get(),
            'terisi' => DenahPenempatan::select('denah_id', DB::raw('count(*) as n'))->groupBy('denah_id')->pluck('n', 'denah_id'),
            'denahLama' => Denah::whereNull('kegiatan_id')->withCount('penempatan')->orderByDesc('id')->get(),
        ]);
    }

    public function bukaKegiatan(Request $request, KegiatanUjian $kegiatan): RedirectResponse
    {
        $denah = $this->layanan->untukKegiatan($kegiatan, $request->user('web'));

        return redirect()->route('staf.denah.show', $denah);
    }

    public function show(Denah $denah): View
    {
        $denah->load('periode.tahunAjaran', 'kegiatan', 'ruang');
        $tahunId = $denah->periode->tahun_ajaran_id;

        $penempatan = DenahPenempatan::where('denah_id', $denah->id)->get(['siswa_id', 'ruang_id']);
        $kelasSiswa = $this->kelasSiswa($penempatan->pluck('siswa_id')->all(), $tahunId);

        $dipakai = $denah->ruang->keyBy('ruang_id');
        $baris = Ruang::orderBy('nama')->get()->map(function (Ruang $r) use ($dipakai, $penempatan, $kelasSiswa) {
            $dr = $dipakai[$r->id] ?? null;
            $siswa = $penempatan->where('ruang_id', $r->id);

            return [
                'ruang' => $r,
                'kapasitas' => $dr?->kapasitas(),
                'terisi' => $siswa->count(),
                'komposisi' => $siswa->map(fn ($p) => $kelasSiswa[$p->siswa_id]->nama ?? '—')->countBy()->sortKeys(),
            ];
        });

        // Kelas peserta bawaan: kelas yang dipakai jadwal pada kegiatan ini.
        $kelasKegiatan = $denah->kegiatan_id
            ? DB::table('jadwal_kelas')->whereIn('jadwal_id', DB::table('jadwal_ujian')->where('kegiatan_id', $denah->kegiatan_id)->select('id'))->pluck('kelas_id')->map(fn ($k) => (int) $k)->unique()->values()->all()
            : [];
        $belumPunyaRuang = $kelasKegiatan === [] ? 0 : DB::table('keanggotaan_kelas as k')->join('siswa as s', 's.id', '=', 'k.siswa_id')
            ->whereIn('k.kelas_id', $kelasKegiatan)->where('s.status', 'aktif')->where('k.status', 'aktif')
            ->whereNotIn('s.id', DenahPenempatan::where('denah_id', $denah->id)->select('siswa_id'))->distinct()->count('s.id');

        return view('staf.denah.show', [
            'd' => $denah,
            'baris' => $baris,
            'jumlah' => $penempatan->count(),
            'daftarKelas' => Kelas::where('tahun_ajaran_id', $tahunId)->orderBy('tingkat')->orderBy('nama')->get(),
            'kelasKegiatan' => $kelasKegiatan,
            'belumPunyaRuang' => $belumPunyaRuang,
            'jadwalPakai' => $denah->jadwal()->orderBy('mulai_pada')->get(['id', 'nama', 'status']),
        ]);
    }

    public function destroy(Request $request, Denah $denah): RedirectResponse
    {
        $denah->delete();
        AuditLog::catat('denah.hapus', "ID {$denah->id}", $request->user('web'));

        return redirect()->route('staf.denah.index')->with('berhasil', 'Denah dihapus.');
    }

    /** Simpan kapasitas semua ruang sekaligus (kosong = ruang tidak dipakai). */
    public function simpanRuang(Request $request, Denah $denah): RedirectResponse
    {
        $data = $request->validate([
            'kapasitas' => ['nullable', 'array'],
            'kapasitas.*' => ['nullable', 'integer', 'between:0,500'],
        ], [], ['kapasitas.*' => 'Kapasitas']);

        $ruangId = Ruang::pluck('id')->all();
        $kapasitas = collect($data['kapasitas'] ?? [])->filter(fn ($v, $id) => in_array((int) $id, $ruangId, true))->mapWithKeys(fn ($v, $id) => [(int) $id => $v === null ? 0 : (int) $v])->all();
        $this->layanan->aturRuang($denah, $kapasitas, $request->user('web'));

        return back()->with('berhasil', 'Ruang disimpan.');
    }

    public function bagi(Request $request, Denah $denah): RedirectResponse
    {
        $data = $request->validate([
            'kelas_ids' => ['required', 'array', 'min:1'],
            'kelas_ids.*' => ['integer', Rule::exists('kelas', 'id')->where('tahun_ajaran_id', $denah->periode()->value('tahun_ajaran_id'))],
            'campur' => ['nullable', 'boolean'],
        ], ['kelas_ids.required' => 'Pilih minimal satu kelas peserta.'], ['kelas_ids' => 'Kelas']);

        $campur = (bool) ($data['campur'] ?? false);
        try {
            $h = $this->layanan->bagi($denah, array_map('intval', $data['kelas_ids']), $request->user('web'), null, $campur);
        } catch (KesalahanJadwal $e) {
            return back()->with('gagal', $e->getMessage())->withInput();
        }
        $this->layanan->ikatJadwalKegiatan($denah);

        $pesan = "{$h['ditempatkan']} siswa ditempatkan.";
        if ($h['tanpa_tempat'] > 0) {
            return back()->with('catatan', "{$pesan} {$h['tanpa_tempat']} siswa belum mendapat ruang: tambah kapasitas atau ruang, lalu bagi ulang.");
        }
        if ($h['meja_sejenis'] > 0) {
            return back()->with('catatan', "{$pesan} {$h['meja_sejenis']} meja terpaksa berisi siswa dari tingkat yang sama.");
        }

        return back()->with('berhasil', $pesan);
    }

    // ── Satu ruang ──────────────────────────────────────────────────────────

    public function ruang(Denah $denah, Ruang $ruang): View
    {
        $dr = $this->ruangDenah($denah, $ruang);
        $denah->load('periode', 'kegiatan');
        $baris = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)->with('siswa:id,nama,nisn,username')->orderBy('meja')->orderBy('posisi')->get();
        $kelasSiswa = $this->kelasSiswa($baris->pluck('siswa_id')->all(), $denah->periode->tahun_ajaran_id);

        return view('staf.denah.ruang', [
            'd' => $denah,
            'ruang' => $ruang,
            'dr' => $dr,
            'baris' => $baris,
            'kelasSiswa' => $kelasSiswa,
            'komposisi' => $baris->map(fn ($p) => $kelasSiswa[$p->siswa_id]->nama ?? '—')->countBy()->sortKeys(),
            'ruangLain' => $denah->ruang()->with('ruang')->where('ruang_id', '!=', $ruang->id)->get()->sortBy(fn ($x) => $x->ruang->nama),
        ]);
    }

    public function urutan(Request $request, Denah $denah, Ruang $ruang): JsonResponse
    {
        $dr = $this->ruangDenah($denah, $ruang);
        $data = $request->validate(['ids' => ['required', 'array', 'max:600'], 'ids.*' => ['integer']]);
        $this->layanan->urutkan($denah, $dr, $data['ids'], $request->user('web'));

        return response()->json(['ok' => true]);
    }

    public function urutkanPerKelas(Request $request, Denah $denah, Ruang $ruang): RedirectResponse
    {
        $this->layanan->urutkanPerKelas($denah, $this->ruangDenah($denah, $ruang), $request->user('web'));

        return back()->with('berhasil', 'Urutan dikembalikan menurut kelas dan nama.');
    }

    /** Pindahkan siswa ke ruang lain, atau keluarkan dari denah (ruang_id = "keluar"). */
    public function pindah(Request $request, Denah $denah, Siswa $siswa): RedirectResponse
    {
        $data = $request->validate(['ruang_id' => ['required', 'regex:/^(\d+|keluar)$/']], [], ['ruang_id' => 'Ruang tujuan']);
        $tujuan = (string) $data['ruang_id'] === 'keluar' ? null : (int) $data['ruang_id'];

        try {
            $this->layanan->pindah($denah, $siswa, $tujuan, $request->user('web'));
        } catch (KesalahanJadwal $e) {
            return back()->with('gagal', $e->getMessage());
        }

        return back()->with('berhasil', $tujuan === null ? "{$siswa->nama} dikeluarkan dari denah." : "{$siswa->nama} dipindahkan.");
    }

    public function lepas(Request $request, Denah $denah, Siswa $siswa): RedirectResponse
    {
        $this->layanan->pindah($denah, $siswa, null, $request->user('web'));

        return back()->with('berhasil', 'Siswa dilepas dari denah.');
    }

    public function cetakMeja(Denah $denah, Ruang $ruang): View
    {
        $this->ruangDenah($denah, $ruang);
        $denah->load('periode', 'kegiatan');
        $baris = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)->with('siswa:id,nama,nisn')->orderBy('meja')->orderBy('posisi')->get();

        return view('staf.denah.cetak-meja', [
            'd' => $denah, 'ruang' => $ruang, 'baris' => $baris,
            'kelasSiswa' => $this->kelasSiswa($baris->pluck('siswa_id')->all(), $denah->periode->tahun_ajaran_id),
        ]);
    }

    public function absen(Denah $denah, Ruang $ruang): StreamedResponse
    {
        $this->ruangDenah($denah, $ruang);
        $denah->load('periode');
        $baris = DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)->with('siswa:id,nama,nisn')->orderBy('meja')->orderBy('posisi')->get();
        $kelasSiswa = $this->kelasSiswa($baris->pluck('siswa_id')->all(), $denah->periode->tahun_ajaran_id);

        $buku = new Spreadsheet;
        $lembar = $buku->getActiveSheet()->setTitle('Absen');
        $isi = [["Daftar hadir — {$denah->nama}"], ["Ruang: {$ruang->nama}"], [], ['No Meja', 'Nama', 'NISN', 'Kelas', 'Tanda tangan']];
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

    private function ruangDenah(Denah $denah, Ruang $ruang): DenahRuang
    {
        return DenahRuang::where('denah_id', $denah->id)->where('ruang_id', $ruang->id)->firstOrFail();
    }

    /** Kelas (pada tahun ajaran denah) tiap siswa. @param list<int> $siswaIds */
    private function kelasSiswa(array $siswaIds, int $tahunId)
    {
        return DB::table('keanggotaan_kelas as k')->join('kelas as kl', 'kl.id', '=', 'k.kelas_id')
            ->whereIn('k.siswa_id', $siswaIds)->where('kl.tahun_ajaran_id', $tahunId)
            ->select('k.siswa_id', 'kl.nama', 'kl.tingkat')->get()->unique('siswa_id')->keyBy('siswa_id');
    }
}
