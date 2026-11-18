<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankSoal;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Soal;
use App\Models\User;
use App\Services\LayananSoal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BankSoalController extends Controller
{
    public function __construct(private LayananSoal $layanan) {}

    public function index(Request $request): View
    {
        $user = $request->user('web');
        $kueri = BankSoal::query()->dapatDilihat($user)->with('mapel', 'pembuat', 'kegiatan')
            ->withCount([
                'soal as jumlah_pg' => fn ($q) => $q->where('tipe', Soal::PG),
                'soal as jumlah_esai' => fn ($q) => $q->where('tipe', Soal::ESAI),
                'soal as jumlah_draf' => fn ($q) => $q->where('status', 'draf'),
            ])
            ->orderBy('nama');

        if (filled($q = trim((string) $request->query('q')))) {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $kueri->where(fn ($w) => $w->where('nama', 'like', $like)->orWhereHas('mapel', fn ($m) => $m->where('nama', 'like', $like)));
        }

        // Bawaan: hanya bank pada kegiatan ujian yang aktif. Bank semester lama dan bank umum dapat dipilih lewat saringan.
        $daftarKegiatan = \App\Models\KegiatanUjian::with('periode.tahunAjaran')->orderByDesc('aktif')->orderByDesc('periode_id')->orderBy('nama')->get();
        $kegiatan = (string) $request->query('kegiatan', '');
        match (true) {
            $kegiatan === 'semua' => null,
            $kegiatan === 'umum' => $kueri->whereNull('kegiatan_id'),
            ctype_digit($kegiatan) && $daftarKegiatan->contains('id', (int) $kegiatan) => $kueri->where('kegiatan_id', (int) $kegiatan),
            default => $kueri->whereIn('kegiatan_id', $daftarKegiatan->where('aktif', true)->pluck('id')),
        };
        $tersembunyi = (clone $this->kueriDasar($user))->count();

        $banks = $kueri->get();
        [$bankLain, $belumDibuat] = $this->dariGuruLain($user);

        return view('staf.bank-soal.index', [
            'banks' => $banks,
            'bankLain' => $bankLain,
            'belumDibuat' => $belumDibuat,
            'perTingkat' => collect(Kelas::TINGKAT)->mapWithKeys(fn ($t) => [$t => $banks->where('tingkat', $t)->values()]),
            'daftarMapel' => Mapel::orderBy('nama')->pluck('nama', 'id'),
            'daftarKegiatan' => $daftarKegiatan,
            'kegiatanDipilih' => $kegiatan,
            'jumlahSemua' => $tersembunyi,
        ]);
    }

    private function kueriDasar(User $user)
    {
        return BankSoal::query()->dapatDilihat($user);
    }

    /**
     * Pemacu semangat untuk guru: bank soal guru lain pada kegiatan ujian yang AKTIF (hanya ringkasan: mapel, kelas, penyusun,
     * jumlah soal; isi soal tidak dibuka), dan mapel/kelas ampuan guru ini yang belum punya bank berisi soal pada kegiatan itu.
     *
     * @return array{0:\Illuminate\Support\Collection,1:\Illuminate\Support\Collection}
     */
    private function dariGuruLain(User $user): array
    {
        if ($user->role !== User::GURU) {
            return [collect(), collect()];
        }

        $kegiatanAktif = \App\Models\KegiatanUjian::where('aktif', true)->pluck('id');
        if ($kegiatanAktif->isEmpty()) {
            return [collect(), collect()];
        }

        $semua = BankSoal::query()->whereIn('kegiatan_id', $kegiatanAktif)->with('mapel:id,nama', 'pembuat:id,nama', 'kegiatan:id,nama')
            ->withCount([
                'soal as jumlah_pg' => fn ($q) => $q->where('tipe', Soal::PG),
                'soal as jumlah_esai' => fn ($q) => $q->where('tipe', Soal::ESAI),
            ])->get()->filter(fn ($b) => $b->jumlah_pg + $b->jumlah_esai > 0);

        $lain = $semua->where('dibuat_oleh', '!=', $user->id)
            ->sortBy([fn ($a, $b) => strcasecmp($a->mapel->nama, $b->mapel->nama), fn ($a, $b) => $a->tingkat <=> $b->tingkat])->values();

        $punya = $semua->where('dibuat_oleh', $user->id)->map(fn ($b) => $b->mapel_id.'|'.$b->tingkat)->flip();
        $tahun = \App\Models\TahunAjaran::aktif();
        $belum = $tahun
            ? \App\Models\Pengampu::with('mapel:id,nama', 'kelas:id,nama,tingkat,tahun_ajaran_id')
                ->where('guru_id', $user->id)->whereHas('kelas', fn ($k) => $k->where('tahun_ajaran_id', $tahun->id))->whereHas('mapel')->get()
                ->groupBy(fn ($p) => $p->mapel_id.'|'.$p->kelas->tingkat)
                ->reject(fn ($g, $kunci) => $punya->has($kunci))
                ->map(fn ($g) => [
                    'mapel' => $g->first()->mapel->nama, 'tingkat' => $g->first()->kelas->tingkat,
                    'mapel_id' => $g->first()->mapel_id,
                    'oleh' => $lain->filter(fn ($b) => $b->mapel_id === $g->first()->mapel_id && $b->tingkat === $g->first()->kelas->tingkat)->pluck('pembuat.nama')->unique()->sort()->values()->all(),
                ])->sortBy([['mapel', 'asc'], ['tingkat', 'asc']])->values()
            : collect();

        return [$lain, $belum];
    }

    public function create(Request $request): View
    {
        return view('staf.bank-soal.form', ['bank' => null] + $this->pilihan($request->user('web')));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user('web');
        $data = $request->validate($this->aturan(true), [], $this->nama());

        if (! $this->layanan->bolehMembuat($user, (int) $data['mapel_id'], $data['tingkat'])) {
            throw ValidationException::withMessages(['mapel_id' => 'Anda hanya dapat membuat bank soal untuk mapel dan tingkat yang Anda ampu.']);
        }

        $bank = BankSoal::create($data + ['dibuat_oleh' => $user->id]);
        AuditLog::catat('banksoal.tambah', "ID {$bank->id}", $user);

        return redirect()->route('staf.bank-soal.show', $bank)->with('berhasil', 'Bank soal dibuat. Tambahkan soal di bawah ini.');
    }

    public function show(Request $request, BankSoal $bank): View
    {
        $this->izin($request, $bank);


        return view('staf.bank-soal.show', [
            'bank' => $bank->load('mapel', 'pembuat'),
            'daftarBacaan' => $bank->bacaan()->withCount('soal')->get(),
            'otomatisAktif' => \App\Models\PenyediaOtomatis::aktif() !== null,
            'soal' => $bank->soal()->with('opsi', 'bacaan')->get(),
            'ringkasan' => [
                'pg' => $bank->soal()->where('tipe', Soal::PG)->count(),
                'esai' => $bank->soal()->where('tipe', Soal::ESAI)->count(),
                'draf' => $bank->soal()->where('status', 'draf')->count(),
                'kisi_belum' => $bank->soal()->whereNull('indikator')->count(),
            ],
        ]);
    }

    public function edit(Request $request, BankSoal $bank): View
    {
        $this->izin($request, $bank);

        return view('staf.bank-soal.form', ['bank' => $bank] + $this->pilihan($request->user('web')));
    }

    /** Mapel dan tingkat tidak diubah setelah dibuat; gunakan "Salin bank" bila perlu. */
    public function update(Request $request, BankSoal $bank): RedirectResponse
    {
        $this->izin($request, $bank);
        $data = $request->validate($this->aturan(false), [], $this->nama());
        $bank->update($data);
        AuditLog::catat('banksoal.ubah', "ID {$bank->id}", $request->user('web'));

        return redirect()->route('staf.bank-soal.show', $bank)->with('berhasil', 'Perubahan disimpan.');
    }

    public function destroy(Request $request, BankSoal $bank): RedirectResponse
    {
        $this->izin($request, $bank);
        $bank->delete();
        AuditLog::catat('banksoal.hapus', "ID {$bank->id}", $request->user('web'));

        return redirect()->route('staf.bank-soal.index')->with('berhasil', 'Bank soal dihapus.');
    }

    public function salin(Request $request, BankSoal $bank): RedirectResponse
    {
        $this->izin($request, $bank);
        $user = $request->user('web');

        if (! $this->layanan->bolehMembuat($user, $bank->mapel_id, $bank->tingkat)) {
            return back()->with('gagal', 'Anda tidak mengampu mapel dan tingkat ini, sehingga tidak dapat membuat salinannya.');
        }

        $baru = $this->layanan->salinBank($bank, $user, mb_substr($bank->nama, 0, 138).' (salinan)');
        $baru->update(['kegiatan_id' => \App\Models\KegiatanUjian::bawaan()?->id]); // salinan dipakai untuk kegiatan yang sedang berjalan
        AuditLog::catat('banksoal.salin', "dari {$bank->id} ke {$baru->id}", $user);

        return redirect()->route('staf.bank-soal.show', $baru)->with('berhasil', 'Bank disalin. Ubah nama dan soalnya sesuai kebutuhan.');
    }

    public function siapkanSemua(Request $request, BankSoal $bank): RedirectResponse
    {
        $this->izin($request, $bank);
        $jumlah = $bank->soal()->where('status', 'draf')->update(['status' => 'siap']);
        AuditLog::catat('banksoal.siapkan_semua', "ID {$bank->id}: {$jumlah} soal", $request->user('web'));

        return back()->with('berhasil', "{$jumlah} soal ditandai siap dipakai.");
    }

    /** Kisi-kisi dari soal bank ini, siap cetak atau diunduh sebagai Word. */
    public function kisi(Request $request, BankSoal $bank, \App\Services\LayananKisiKisi $kisi): \Illuminate\Http\Response
    {
        $this->izin($request, $bank);
        $word = $request->query('format') === 'word';
        $bank->load('mapel', 'pembuat');

        $isi = view('staf.bank-soal.kisi', ['bank' => $bank, 'kisi' => $kisi->susun($bank), 'sekolah' => \App\Models\PengaturanSekolah::ambil(), 'word' => $word])->render();
        AuditLog::catat('bank_soal.kisi', "bank {$bank->id}".($word ? ' (Word)' : ''), $request->user('web'));

        $respons = response($isi);
        if ($word) {
            $respons->header('Content-Type', 'application/msword; charset=UTF-8')
                ->header('Content-Disposition', 'attachment; filename="Kisi_kisi_'.\Illuminate\Support\Str::slug($bank->nama, '_').'.doc"');
        }

        return $respons;
    }

    /** Susun materi dan indikator kisi-kisi secara otomatis untuk satu rombongan soal (dipanggil berulang oleh halaman). */
    public function susunKisi(Request $request, BankSoal $bank, \App\Services\LayananOtomatis $otomatis): \Illuminate\Http\JsonResponse
    {
        $this->izin($request, $bank);

        try {
            $hasil = $otomatis->susunKisi($request->user('web'), $bank);
        } catch (\App\Otomatis\KesalahanOtomatis $e) {
            $tunggu = $e->getMessage() === \App\Services\LayananOtomatis::PESAN_TUNGGU;

            return response()->json(['kode' => $tunggu ? 'tunggu' : 'otomatis', 'pesan' => $e->getMessage()], $tunggu ? 429 : 422);
        }

        return response()->json(['ok' => true] + $hasil);
    }

    private function izin(Request $request, BankSoal $bank): void
    {
        abort_unless($bank->dapatDikelola($request->user('web')), 403, 'Anda tidak memiliki akses ke bank soal ini.');
    }

    private function aturan(bool $baru): array
    {
        $aturan = [
            'nama' => ['required', 'string', 'max:150'],
            'jumlah_opsi_bawaan' => ['required', Rule::in([4, 5])],
        ];

        $aturan['kegiatan_id'] = ['nullable', Rule::exists('kegiatan_ujian', 'id')];

        return $baru
            ? $aturan + ['mapel_id' => ['required', Rule::exists('mapel', 'id')], 'tingkat' => ['required', Rule::in(Kelas::TINGKAT)]]
            : $aturan;
    }

    private function nama(): array
    {
        return ['nama' => 'Nama bank', 'jumlah_opsi_bawaan' => 'Jumlah opsi', 'mapel_id' => 'Mata pelajaran', 'tingkat' => 'Tingkat', 'kegiatan_id' => 'Kegiatan ujian'];
    }

    /** Guru hanya melihat mapel yang diampunya di tahun ajaran aktif. */
    private function pilihan(User $user): array
    {
        $mapel = Mapel::orderBy('nama');
        if ($user->role !== User::ADMIN) {
            $mapel->whereIn('id', collect($this->layanan->pasanganDiampu($user))->pluck(0)->unique());
        }

        return [
            'daftarMapel' => $mapel->pluck('nama', 'id'),
            'daftarKegiatan' => \App\Models\KegiatanUjian::with('periode.tahunAjaran')->orderByDesc('id')->get(),
            'kegiatanBawaan' => \App\Models\KegiatanUjian::bawaan()?->id,
        ];
    }
}
