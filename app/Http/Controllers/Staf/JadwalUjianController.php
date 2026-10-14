<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankSoal;
use App\Models\JadwalUjian;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Periode;
use App\Models\User;
use App\Services\KesalahanJadwal;
use App\Services\LayananJadwal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class JadwalUjianController extends Controller
{
    public function __construct(private LayananJadwal $layanan) {}

    public function index(Request $request): View
    {
        $kueri = JadwalUjian::query()->with('mapel', 'periode.tahunAjaran')->withCount(['kelas', 'sesi'])
            ->orderByRaw("case status when 'aktif' then 0 when 'draf' then 1 else 2 end")
            ->orderByDesc('mulai_pada');

        if (in_array($status = $request->query('status'), ['draf', 'aktif', 'selesai'], true)) {
            $kueri->where('status', $status);
        }
        if (filled($q = trim((string) $request->query('q')))) {
            $kueri->where('nama', 'like', '%'.addcslashes($q, '%_\\').'%');
        }

        return view('staf.jadwal.index', ['jadwal' => $kueri->paginate(20)->withQueryString()]);
    }

    public function create(): View
    {
        return view('staf.jadwal.form', ['j' => null] + $this->pilihan());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validasiLengkap($request);

        $jadwal = DB::transaction(function () use ($data, $request) {
            $jadwal = JadwalUjian::create($this->atributDasar($data) + ['dibuat_oleh' => $request->user('web')->id, 'status' => JadwalUjian::DRAF]);
            $this->simpanRelasi($jadwal, $data);

            return $jadwal;
        });

        AuditLog::catat('jadwal.tambah', "ID {$jadwal->id}", $request->user('web'));

        return redirect()->route('staf.jadwal.show', $jadwal)->with('berhasil', 'Jadwal dibuat sebagai draf. Periksa lalu aktifkan bila sudah siap.');
    }

    public function show(JadwalUjian $jadwal): View
    {
        $jadwal->load('mapel', 'periode.tahunAjaran', 'pembuat', 'kelas.jurusan', 'pengawas', 'bank.bankSoal');

        return view('staf.jadwal.show', [
            'j' => $jadwal,
            'statusSesi' => $jadwal->sesi()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status'),
            'soalPg' => $jadwal->soalBeku()->whereHas('soal', fn ($q) => $q->withTrashed()->where('tipe', 'pg'))->count(),
            'soalEsai' => $jadwal->soalBeku()->whereHas('soal', fn ($q) => $q->withTrashed()->where('tipe', 'esai'))->count(),
            'calonPeserta' => $jadwal->berstatus(JadwalUjian::DRAF) ? $this->layanan->pesertaBerhak($jadwal)->count() : null,
        ]);
    }

    public function edit(JadwalUjian $jadwal): View
    {
        abort_if($jadwal->berstatus(JadwalUjian::SELESAI), 403, 'Jadwal yang sudah selesai tidak dapat diubah.');

        return view('staf.jadwal.form', ['j' => $jadwal->load('kelas', 'bank', 'pengawas')] + $this->pilihan());
    }

    public function update(Request $request, JadwalUjian $jadwal): RedirectResponse
    {
        abort_if($jadwal->berstatus(JadwalUjian::SELESAI), 403, 'Jadwal yang sudah selesai tidak dapat diubah.');

        if ($jadwal->berstatus(JadwalUjian::AKTIF)) {
            $data = $this->validasiAktif($request, $jadwal);
            DB::transaction(function () use ($jadwal, $data) {
                $jadwal->update(collect($data)->only(['tutup_pada', 'batas_pelanggaran', 'tampilkan_hasil'])->all());
                $jadwal->pengawas()->sync($data['pengawas_ids'] ?? []);
            });
        } else {
            $data = $this->validasiLengkap($request);
            DB::transaction(function () use ($jadwal, $data) {
                $jadwal->update($this->atributDasar($data));
                $this->simpanRelasi($jadwal, $data);
            });
        }

        AuditLog::catat('jadwal.ubah', "ID {$jadwal->id}", $request->user('web'));

        return redirect()->route('staf.jadwal.show', $jadwal)->with('berhasil', 'Perubahan disimpan.');
    }

    public function destroy(Request $request, JadwalUjian $jadwal): RedirectResponse
    {
        if (! $jadwal->berstatus(JadwalUjian::DRAF)) {
            return back()->with('gagal', 'Hanya jadwal draf yang bisa dihapus. Jadwal yang sudah dipakai disimpan sebagai arsip.');
        }

        $jadwal->delete();
        AuditLog::catat('jadwal.hapus', "ID {$jadwal->id}", $request->user('web'));

        return redirect()->route('staf.jadwal.index')->with('berhasil', 'Jadwal dihapus.');
    }

    public function aktifkan(Request $request, JadwalUjian $jadwal): RedirectResponse
    {
        return $this->jalankan($request, $jadwal, 'aktifkan', function () use ($jadwal) {
            $ringkas = $this->layanan->aktifkan($jadwal);

            return "Jadwal aktif: {$ringkas['pg']} soal PG, {$ringkas['esai']} soal esai, {$ringkas['peserta']} peserta. Token ujian sudah dibuat.";
        });
    }

    public function batalkan(Request $request, JadwalUjian $jadwal): RedirectResponse
    {
        return $this->jalankan($request, $jadwal, 'batalkan', function () use ($jadwal) {
            $this->layanan->batalkanAktivasi($jadwal);

            return 'Aktivasi dibatalkan. Jadwal kembali menjadi draf dan bisa diubah.';
        });
    }

    public function regenerasiToken(Request $request, JadwalUjian $jadwal): RedirectResponse
    {
        return $this->jalankan($request, $jadwal, 'token', function () use ($jadwal) {
            $this->layanan->regenerasiToken($jadwal);

            return 'Token baru dibuat. Token lama tidak berlaku lagi untuk siswa yang belum masuk.';
        });
    }

    public function selesaikan(Request $request, JadwalUjian $jadwal): RedirectResponse
    {
        return $this->jalankan($request, $jadwal, 'selesaikan', function () use ($jadwal) {
            $this->layanan->selesaikan($jadwal);

            return 'Jadwal ditandai selesai.';
        });
    }

    /** @param  callable():string  $aksi */
    private function jalankan(Request $request, JadwalUjian $jadwal, string $nama, callable $aksi): RedirectResponse
    {
        try {
            $pesan = $aksi();
        } catch (KesalahanJadwal $e) {
            return back()->with('gagal', $e->getMessage());
        }

        AuditLog::catat("jadwal.{$nama}", "ID {$jadwal->id}", $request->user('web'));

        return back()->with('berhasil', $pesan);
    }

    private function pilihan(): array
    {
        return [
            'daftarPeriode' => Periode::with('tahunAjaran')->orderByDesc('tahun_ajaran_id')->orderBy('semester')->get(),
            'daftarMapel' => Mapel::orderBy('nama')->get(),
            'daftarKelas' => Kelas::with('tahunAjaran', 'jurusan')->orderByDesc('tahun_ajaran_id')->orderBy('nama')->get(),
            'daftarBank' => BankSoal::with('mapel', 'pembuat')
                ->withCount(['soal as pg_siap' => fn ($q) => $q->where('tipe', 'pg')->where('status', 'siap'),
                    'soal as esai_siap' => fn ($q) => $q->where('tipe', 'esai')->where('status', 'siap')])
                ->orderBy('nama')->get(),
            'daftarPengawas' => User::where('role', User::PENGAWAS)->where('aktif', true)->orderBy('nama')->get(),
            'periodeAktif' => Periode::where('aktif', true)->value('id'),
        ];
    }

    private function validasiLengkap(Request $request): array
    {
        $aturan = [
            'nama' => ['required', 'string', 'max:150'],
            'periode_id' => ['required', Rule::exists('periode', 'id')],
            'mapel_id' => ['required', Rule::exists('mapel', 'id')],
            'durasi_menit' => ['required', 'integer', 'between:5,480'],
            'mulai_pada' => ['required', 'date'],
            'tutup_pada' => ['required', 'date', 'after:mulai_pada'],
            'kelas_ids' => ['required', 'array', 'min:1'],
            'kelas_ids.*' => ['integer', Rule::exists('kelas', 'id')],
            'bank_ids' => ['required', 'array', 'min:1'],
            'bank_ids.*' => ['integer', Rule::exists('bank_soal', 'id')->whereNull('deleted_at')],
            'jumlah_pg' => ['nullable', 'array'],
            'jumlah_pg.*' => ['nullable', 'integer', 'between:1,500'],
            'jumlah_esai' => ['nullable', 'array'],
            'jumlah_esai.*' => ['nullable', 'integer', 'between:1,500'],
            'acak_soal' => ['nullable', 'boolean'],
            'acak_opsi' => ['nullable', 'boolean'],
            'batas_pelanggaran' => ['required', 'integer', 'between:1,10'],
            'bobot_pg' => ['required', 'integer', 'between:0,100'],
            'tampilkan_hasil' => ['required', Rule::in(['langsung', 'ditahan'])],
            'pengawas_ids' => ['nullable', 'array'],
            'pengawas_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', User::PENGAWAS)],
        ];

        $v = Validator::make($request->all(), $aturan, [
            'tutup_pada.after' => 'Waktu tutup harus setelah waktu mulai.',
            'kelas_ids.required' => 'Pilih minimal satu kelas peserta.',
            'bank_ids.required' => 'Pilih minimal satu bank soal.',
        ], [
            'nama' => 'Nama jadwal', 'periode_id' => 'Semester', 'mapel_id' => 'Mata pelajaran', 'durasi_menit' => 'Durasi',
            'mulai_pada' => 'Waktu mulai', 'tutup_pada' => 'Waktu tutup', 'batas_pelanggaran' => 'Batas pelanggaran',
            'bobot_pg' => 'Bobot PG', 'tampilkan_hasil' => 'Tampilan hasil',
        ]);

        $v->after(function ($v) use ($request) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            // Kelas harus berada pada tahun ajaran dari semester yang dipilih.
            $tahun = Periode::find($request->input('periode_id'))->tahun_ajaran_id;
            if (Kelas::whereIn('id', $request->input('kelas_ids'))->where('tahun_ajaran_id', '!=', $tahun)->exists()) {
                $v->errors()->add('kelas_ids', 'Semua kelas harus berasal dari tahun ajaran semester yang dipilih.');
            }

            // Bank harus untuk mapel yang sama dan tingkatnya ada di antara kelas peserta.
            $tingkat = Kelas::whereIn('id', $request->input('kelas_ids'))->pluck('tingkat')->unique();
            foreach (BankSoal::whereIn('id', $request->input('bank_ids'))->get() as $bank) {
                if ($bank->mapel_id !== (int) $request->input('mapel_id')) {
                    $v->errors()->add('bank_ids', "Bank \"{$bank->nama}\" bukan untuk mata pelajaran yang dipilih.");
                } elseif (! $tingkat->contains($bank->tingkat)) {
                    $v->errors()->add('bank_ids', "Bank \"{$bank->nama}\" untuk kelas {$bank->tingkat}, sedangkan kelas peserta tidak ada yang tingkat itu.");
                }
            }
        });

        return $v->validate();
    }

    private function validasiAktif(Request $request, JadwalUjian $jadwal): array
    {
        return $request->validate([
            'tutup_pada' => ['required', 'date', 'after:now', 'after:'.$jadwal->mulai_pada->toDateTimeString()],
            'batas_pelanggaran' => ['required', 'integer', 'between:1,10'],
            'tampilkan_hasil' => ['required', Rule::in(['langsung', 'ditahan'])],
            'pengawas_ids' => ['nullable', 'array'],
            'pengawas_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', User::PENGAWAS)],
        ], [
            'tutup_pada.after' => 'Waktu tutup harus di masa depan dan setelah waktu mulai.',
        ], ['tutup_pada' => 'Waktu tutup', 'batas_pelanggaran' => 'Batas pelanggaran', 'tampilkan_hasil' => 'Tampilan hasil']);
    }

    private function atributDasar(array $data): array
    {
        return [
            'nama' => $data['nama'],
            'periode_id' => $data['periode_id'],
            'mapel_id' => $data['mapel_id'],
            'durasi_menit' => $data['durasi_menit'],
            'mulai_pada' => $data['mulai_pada'],
            'tutup_pada' => $data['tutup_pada'],
            'acak_soal' => (bool) ($data['acak_soal'] ?? false),
            'acak_opsi' => (bool) ($data['acak_opsi'] ?? false),
            'batas_pelanggaran' => $data['batas_pelanggaran'],
            'bobot_pg' => $data['bobot_pg'],
            'tampilkan_hasil' => $data['tampilkan_hasil'],
        ];
    }

    private function simpanRelasi(JadwalUjian $jadwal, array $data): void
    {
        $jadwal->kelas()->sync($data['kelas_ids']);
        $jadwal->pengawas()->sync($data['pengawas_ids'] ?? []);

        $jadwal->bank()->delete();
        foreach ($data['bank_ids'] as $idBank) {
            $jadwal->bank()->create([
                'bank_soal_id' => $idBank,
                'jumlah_pg' => filled($data['jumlah_pg'][$idBank] ?? null) ? (int) $data['jumlah_pg'][$idBank] : null,
                'jumlah_esai' => filled($data['jumlah_esai'][$idBank] ?? null) ? (int) $data['jumlah_esai'][$idBank] : null,
            ]);
        }
    }
}
