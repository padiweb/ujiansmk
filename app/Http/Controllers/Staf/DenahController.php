<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Denah;
use App\Models\DenahPenempatan;
use App\Models\DenahRuang;
use App\Models\Kelas;
use App\Models\Periode;
use App\Models\Ruang;
use App\Models\Siswa;
use App\Services\KesalahanJadwal;
use App\Services\LayananDenah;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DenahController extends Controller
{
    public function __construct(private LayananDenah $layanan) {}

    public function index(): View
    {
        return view('staf.denah.index', [
            'denah' => Denah::with('periode.tahunAjaran')->withCount(['ruang', 'penempatan', 'jadwal'])->orderByDesc('id')->get(),
        ]);
    }

    public function create(): View
    {
        return view('staf.denah.form', [
            'daftarPeriode' => Periode::with('tahunAjaran')->orderByDesc('tahun_ajaran_id')->orderBy('semester')->get(),
            'periodeAktif' => Periode::where('aktif', true)->value('id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'periode_id' => ['required', Rule::exists('periode', 'id')],
        ], [], ['nama' => 'Nama denah', 'periode_id' => 'Semester']);

        $denah = Denah::create($data + ['dibuat_oleh' => $request->user('web')->id]);
        AuditLog::catat('denah.tambah', "ID {$denah->id}", $request->user('web'));

        return redirect()->route('staf.denah.show', $denah)->with('berhasil', 'Denah dibuat. Tambahkan ruang, lalu bagi siswa.');
    }

    public function show(Denah $denah): View
    {
        $denah->load('periode.tahunAjaran', 'ruang.ruang');

        $penempatan = DenahPenempatan::where('denah_id', $denah->id)->with('siswa:id,nama,nisn')->orderBy('ruang_id')->orderBy('meja')->orderBy('posisi')->get();

        // Kelas siswa pada tahun ajaran denah (untuk label tingkat/kelas di tiap meja).
        $kelasSiswa = DB::table('keanggotaan_kelas as k')->join('kelas as kl', 'kl.id', '=', 'k.kelas_id')
            ->whereIn('k.siswa_id', $penempatan->pluck('siswa_id'))->where('kl.tahun_ajaran_id', $denah->periode->tahun_ajaran_id)
            ->select('k.siswa_id', 'kl.nama', 'kl.tingkat')->get()->unique('siswa_id')->keyBy('siswa_id');

        $ruang = $denah->ruang->map(function (DenahRuang $r) use ($penempatan, $kelasSiswa) {
            $isi = $penempatan->where('ruang_id', $r->ruang_id)->groupBy('meja');
            $meja = [];
            for ($m = 1; $m <= $r->jumlah_meja; $m++) {
                $orang = ($isi[$m] ?? collect())->map(fn ($p) => [
                    'siswa_id' => $p->siswa_id, 'nama' => $p->siswa->nama, 'nisn' => $p->siswa->nisn, 'posisi' => $p->posisi,
                    'kelas' => $kelasSiswa[$p->siswa_id]->nama ?? '—', 'tingkat' => $kelasSiswa[$p->siswa_id]->tingkat ?? null,
                ])->sortBy('posisi')->values();
                $meja[] = ['no' => $m, 'orang' => $orang, 'sejenis' => $orang->count() > 1 && $orang->pluck('tingkat')->unique()->count() < $orang->count()];
            }

            return ['r' => $r, 'meja' => $meja, 'terisi' => $penempatan->where('ruang_id', $r->ruang_id)->count()];
        });

        return view('staf.denah.show', [
            'd' => $denah,
            'ruangDenah' => $ruang,
            'jumlah' => $penempatan->count(),
            'daftarRuang' => Ruang::whereNotIn('id', $denah->ruang->pluck('ruang_id'))->orderBy('nama')->get(),
            'daftarKelas' => Kelas::where('tahun_ajaran_id', $denah->periode->tahun_ajaran_id)->orderBy('tingkat')->orderBy('nama')->get(),
            'jadwalPakai' => $denah->jadwal()->orderBy('mulai_pada')->get(['id', 'nama', 'status']),
        ]);
    }

    public function destroy(Request $request, Denah $denah): RedirectResponse
    {
        $denah->delete();
        AuditLog::catat('denah.hapus', "ID {$denah->id}", $request->user('web'));

        return redirect()->route('staf.denah.index')->with('berhasil', 'Denah dihapus.');
    }

    public function tambahRuang(Request $request, Denah $denah): RedirectResponse
    {
        $data = $request->validate([
            'ruang_id' => ['required', Rule::exists('ruang', 'id')],
            'jumlah_meja' => ['required', 'integer', 'between:1,200'],
            'siswa_per_meja' => ['required', 'integer', 'between:1,3'],
        ], [], ['ruang_id' => 'Ruang', 'jumlah_meja' => 'Jumlah meja', 'siswa_per_meja' => 'Siswa per meja']);

        DB::transaction(function () use ($denah, $data) {
            $r = DenahRuang::updateOrCreate(['denah_id' => $denah->id, 'ruang_id' => $data['ruang_id']], $data);
            // Mengecilkan ruang: penempatan di luar kapasitas baru dilepas.
            DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $r->ruang_id)
                ->where(fn ($q) => $q->where('meja', '>', $r->jumlah_meja)->orWhere('posisi', '>', $r->siswa_per_meja))->delete();
        });
        $this->layanan->perbaruiJadwal($denah);

        return back()->with('berhasil', 'Ruang disimpan di denah.');
    }

    public function hapusRuang(Request $request, Denah $denah, DenahRuang $denahRuang): RedirectResponse
    {
        abort_unless($denahRuang->denah_id === $denah->id, 404);

        DB::transaction(function () use ($denah, $denahRuang) {
            DenahPenempatan::where('denah_id', $denah->id)->where('ruang_id', $denahRuang->ruang_id)->delete();
            $denahRuang->delete();
        });
        $this->layanan->perbaruiJadwal($denah);
        AuditLog::catat('denah.hapus_ruang', "denah {$denah->id} ruang {$denahRuang->ruang_id}", $request->user('web'));

        return back()->with('berhasil', 'Ruang dilepas dari denah beserta penempatannya.');
    }

    public function bagi(Request $request, Denah $denah): RedirectResponse
    {
        $data = $request->validate([
            'kelas_ids' => ['required', 'array', 'min:1'],
            'kelas_ids.*' => ['integer', Rule::exists('kelas', 'id')->where('tahun_ajaran_id', $denah->periode()->value('tahun_ajaran_id'))],
        ], ['kelas_ids.required' => 'Pilih minimal satu kelas peserta.'], ['kelas_ids' => 'Kelas']);

        try {
            $h = $this->layanan->bagi($denah, array_map('intval', $data['kelas_ids']), $request->user('web'));
        } catch (KesalahanJadwal $e) {
            return back()->with('gagal', $e->getMessage())->withInput();
        }

        $pesan = "{$h['ditempatkan']} siswa ditempatkan (kapasitas {$h['kapasitas']}).";
        if ($h['tanpa_tempat'] > 0) {
            return back()->with('catatan', "{$pesan} {$h['tanpa_tempat']} siswa belum mendapat tempat: tambah ruang atau meja lalu bagi ulang.");
        }
        if ($h['meja_sejenis'] > 0) {
            return back()->with('catatan', "{$pesan} {$h['meja_sejenis']} meja terpaksa berisi siswa dari tingkat yang sama (jumlah tingkat tidak seimbang).");
        }

        return back()->with('berhasil', $pesan.' Semua meja berisi tingkat yang berbeda.');
    }

    public function tempatkan(Request $request, Denah $denah): RedirectResponse
    {
        $data = $request->validate([
            'nisn' => ['required', 'string', 'max:20'],
            'ruang_id' => ['required', 'integer'],
            'meja' => ['required', 'integer', 'min:1'],
            'posisi' => ['required', 'integer', 'min:1'],
        ], ['nisn.required' => 'NISN siswa wajib diisi.'], ['ruang_id' => 'Ruang', 'meja' => 'Meja', 'posisi' => 'Posisi']);

        $siswa = Siswa::where('nisn', trim($data['nisn']))->where('status', 'aktif')->first();
        if (! $siswa) {
            return back()->with('gagal', 'Siswa dengan NISN itu tidak ditemukan atau tidak aktif.')->withInput();
        }

        try {
            $this->layanan->tempatkan($denah, $siswa, (int) $data['ruang_id'], (int) $data['meja'], (int) $data['posisi'], $request->user('web'));
        } catch (KesalahanJadwal $e) {
            return back()->with('gagal', $e->getMessage())->withInput();
        }

        return back()->with('berhasil', "{$siswa->nama} ditempatkan.");
    }

    public function lepas(Request $request, Denah $denah, Siswa $siswa): RedirectResponse
    {
        $this->layanan->lepas($denah, $siswa, $request->user('web'));

        return back()->with('berhasil', 'Siswa dilepas dari denah.');
    }
}
