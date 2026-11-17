<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankSoal;
use App\Models\Soal;
use App\Services\LayananSoal;
use App\Support\GambarAman;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SoalController extends Controller
{
    public function __construct(private LayananSoal $layanan) {}

    public function create(Request $request, BankSoal $bank): View
    {
        $this->izin($request, $bank);
        $tipe = $request->query('tipe') === Soal::ESAI ? Soal::ESAI : Soal::PG;

        return view('staf.soal.form', ['bank' => $bank, 'soal' => null, 'tipe' => $tipe, 'daftarBacaan' => $bank->bacaan()->get(), 'bacaanAwal' => (int) $request->query('bacaan')]);
    }

    public function store(Request $request, BankSoal $bank): RedirectResponse
    {
        $this->izin($request, $bank);
        $data = $this->validasi($request, null, $bank, null);

        DB::transaction(function () use ($request, $bank, $data) {
            $soal = $bank->soal()->create([
                'bacaan_id' => $data['bacaan_id'] ?? null,
                'level' => $data['level'] ?? null,
                'materi' => $data['materi'] ?? null,
                'indikator' => $data['indikator'] ?? null,
                'pembahasan' => $data['pembahasan'] ?? null,
                'tipe' => $data['tipe'],
                'pertanyaan' => $data['pertanyaan'],
                'bobot' => $data['bobot'],
                'rubrik_esai' => $data['tipe'] === Soal::ESAI ? ($data['rubrik_esai'] ?? null) : null,
                'status' => $data['status'],
                'sumber' => 'manual',
                'urutan' => ($bank->soal()->max('urutan') ?? 0) + 1,
                'gambar' => $request->hasFile('gambar') ? GambarAman::simpan($request->file('gambar')) : null,
            ]);
            $this->simpanOpsi($request, $soal, $data);
        });

        AuditLog::catat('soal.tambah', "bank {$bank->id}", $request->user('web'));

        return redirect()->route('staf.bank-soal.show', $bank)->with('berhasil', 'Soal ditambahkan.');
    }

    public function edit(Request $request, BankSoal $bank, Soal $soal): View
    {
        $this->izin($request, $bank, $soal);

        return view('staf.soal.form', ['bank' => $bank, 'soal' => $soal->load('opsi'), 'tipe' => $soal->tipe, 'daftarBacaan' => $bank->bacaan()->get(), 'bacaanAwal' => $soal->bacaan_id]);
    }

    public function update(Request $request, BankSoal $bank, Soal $soal): RedirectResponse
    {
        $this->izin($request, $bank, $soal);
        $data = $this->validasi($request, $soal->tipe, $bank, $soal);

        DB::transaction(function () use ($request, $soal, $data) {
            $gambar = $soal->gambar;
            if ($request->hasFile('gambar')) {
                $gambar = GambarAman::simpan($request->file('gambar'));
                GambarAman::hapus($soal->gambar);
            } elseif ($request->boolean('hapus_gambar')) {
                GambarAman::hapus($soal->gambar);
                $gambar = null;
            }

            $soal->update([
                'bacaan_id' => $data['bacaan_id'] ?? null,
                'level' => $data['level'] ?? null,
                'materi' => $data['materi'] ?? null,
                'indikator' => $data['indikator'] ?? null,
                'pembahasan' => $data['pembahasan'] ?? null,
                'pertanyaan' => $data['pertanyaan'],
                'bobot' => $data['bobot'],
                'rubrik_esai' => $soal->tipe === Soal::ESAI ? ($data['rubrik_esai'] ?? null) : null,
                'status' => $data['status'],
                'gambar' => $gambar,
            ]);
            $this->simpanOpsi($request, $soal, $data);
        });

        AuditLog::catat('soal.ubah', "ID {$soal->id}", $request->user('web'));

        return redirect()->route('staf.bank-soal.show', $bank)->with('berhasil', 'Soal diperbarui.');
    }

    /** Hapus lunak: soal tetap tersimpan agar hasil ujian lama dapat dilacak. */
    public function destroy(Request $request, BankSoal $bank, Soal $soal): RedirectResponse
    {
        $this->izin($request, $bank, $soal);
        $soal->delete();
        AuditLog::catat('soal.hapus', "ID {$soal->id}", $request->user('web'));

        return back()->with('berhasil', 'Soal dihapus.');
    }

    public function status(Request $request, BankSoal $bank, Soal $soal): RedirectResponse
    {
        $this->izin($request, $bank, $soal);
        $data = $request->validate(['status' => ['required', Rule::in(['draf', 'siap'])]]);
        $soal->update($data);

        return back()->with('berhasil', $data['status'] === 'siap' ? 'Soal ditandai siap dipakai.' : 'Soal dikembalikan ke draf.');
    }

    /** Gambar soal disajikan lewat rute berizin, bukan URL publik. */
    public function gambar(Request $request, Soal $soal)
    {
        $soal->loadMissing('bank');
        abort_unless($soal->bank && $soal->bank->dapatDikelola($request->user('web')) && $soal->gambar, 404);
        abort_unless(Storage::disk('local')->exists($soal->gambar), 404);

        return Storage::disk('local')->response($soal->gambar, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Pastikan bank boleh dikelola, dan (bila ada) soal memang milik bank itu. */
    private function izin(Request $request, BankSoal $bank, ?Soal $soal = null): void
    {
        abort_unless($bank->dapatDikelola($request->user('web')), 403, 'Anda tidak memiliki akses ke bank soal ini.');
        abort_if($soal && $soal->bank_soal_id !== $bank->id, 404);
    }

    private function validasi(Request $request, ?string $tipeTetap, \App\Models\BankSoal $bank, ?Soal $soal): array
    {
        $tipe = $tipeTetap ?? $request->input('tipe');
        $jumlahOpsi = (int) $request->input('jumlah_opsi', 4);
        $huruf = array_slice(LayananSoal::LABEL, 0, $jumlahOpsi === 5 ? 5 : 4);

        $aturan = [
            'tipe' => ['required', Rule::in([Soal::PG, Soal::ESAI])],
            'bacaan_id' => ['nullable', 'integer', Rule::exists('bacaan_soal', 'id')->where('bank_soal_id', $bank->id)],
            'level' => ['nullable', Rule::in(array_keys(Soal::LEVEL))],
            'materi' => ['nullable', 'string', 'max:150'],
            'indikator' => ['nullable', 'string', 'max:400'],
            'pembahasan' => ['nullable', 'string', 'max:3000'],
            'pertanyaan' => ['required', 'string', 'max:5000'],
            'bobot' => ['required', 'numeric', 'between:0.01,100'],
            'status' => ['required', Rule::in(['draf', 'siap'])],
            'gambar' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];

        if ($tipe === Soal::PG) {
            $aturan['jumlah_opsi'] = ['required', Rule::in([4, 5])];
            foreach ($huruf as $h) {
                $aturan["opsi.{$h}"] = ['nullable', 'string', 'max:1000'];
                $aturan["gambar_opsi.{$h}"] = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'];
            }
            $aturan['kunci'] = ['required', Rule::in($huruf)];
        } else {
            $aturan['rubrik_esai'] = ['nullable', 'string', 'max:5000'];
        }

        $nama = [
            'level' => 'Level', 'materi' => 'Materi', 'indikator' => 'Indikator', 'pembahasan' => 'Pembahasan', 'bacaan_id' => 'Bacaan', 'pertanyaan' => 'Pertanyaan', 'bobot' => 'Bobot', 'status' => 'Status', 'gambar' => 'Gambar',
            'kunci' => 'Kunci jawaban', 'rubrik_esai' => 'Jawaban acuan', 'jumlah_opsi' => 'Jumlah opsi',
        ] + collect(LayananSoal::LABEL)->mapWithKeys(fn ($h) => ["opsi.{$h}" => "Opsi {$h}"])->all();

        $pesan = ['gambar.mimes' => 'Gambar harus berformat JPG, PNG, atau WEBP.', 'gambar.max' => 'Ukuran gambar maksimal 2 MB.'];

        $validator = Validator::make($request->merge(['tipe' => $tipe])->all(), $aturan, $pesan, $nama);

        // Setiap opsi PG harus berisi teks atau gambar (gambar lama yang tidak dihapus ikut dihitung).
        if ($tipe === Soal::PG) {
            $validator->after(function ($v) use ($request, $huruf, $soal) {
                $lama = $soal ? $soal->opsi()->pluck('gambar', 'label')->all() : [];
                foreach ($huruf as $h) {
                    $adaTeks = trim((string) $request->input("opsi.{$h}")) !== '';
                    $adaGambar = $request->hasFile("gambar_opsi.{$h}") || (! empty($lama[$h]) && ! $request->boolean("hapus_gambar_opsi.{$h}"));
                    if (! $adaTeks && ! $adaGambar && ! $v->errors()->has("opsi.{$h}")) {
                        $v->errors()->add("opsi.{$h}", "Opsi {$h} harus berisi teks atau gambar.");
                    }
                }
            });
        }

        $data = $validator->validate();
        $data['tipe'] = $tipe;

        return $data;
    }

    private function simpanOpsi(Request $request, Soal $soal, array $data): void
    {
        if ($soal->tipe !== Soal::PG) {
            return;
        }

        $lama = $soal->opsi()->pluck('gambar', 'label')->all();
        $gambar = [];
        foreach (array_keys($data['opsi']) as $label) {
            $jalur = $lama[$label] ?? null;
            if ($berkas = $request->file("gambar_opsi.{$label}")) {
                $jalur = GambarAman::simpan($berkas, "gambar_opsi.{$label}", 'opsi');
            } elseif ($request->boolean("hapus_gambar_opsi.{$label}")) {
                $jalur = null;
            }
            $gambar[$label] = $jalur;
        }

        $opsi = array_map(fn ($t) => trim((string) $t), $data['opsi']);
        $this->layanan->setOpsi($soal, $opsi, $data['kunci'], $gambar);
    }

    /** Gambar opsi disajikan lewat rute berizin. */
    public function gambarOpsi(Request $request, \App\Models\OpsiSoal $opsi)
    {
        $soal = $opsi->soal()->withTrashed()->with('bank')->first();
        abort_unless($soal && $soal->bank && $soal->bank->dapatDikelola($request->user('web')) && $opsi->gambar, 404);
        abort_unless(Storage::disk('local')->exists($opsi->gambar), 404);

        return Storage::disk('local')->response($opsi->gambar, null, ['Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function gambarBacaan(Request $request, \App\Models\BacaanSoal $bacaan)
    {
        $bacaan->loadMissing('bank');
        abort_unless($bacaan->bank && $bacaan->bank->dapatDikelola($request->user('web')) && $bacaan->gambar, 404);
        abort_unless(Storage::disk('local')->exists($bacaan->gambar), 404);

        return Storage::disk('local')->response($bacaan->gambar, null, ['Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }
}
