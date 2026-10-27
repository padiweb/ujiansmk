<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\KlienApi;
use App\Models\PengaturanSekolah;
use App\Models\Periode;
use App\Models\SkalaPredikat;
use App\Services\LayananRapor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Admin mengatur bobot nilai rapor, skala predikat, dan rentang tanggal semester. */
class PengaturanRaporController extends Controller
{
    public function __construct(private LayananRapor $rapor) {}

    public function edit(): View
    {
        return view('staf.pengaturan-rapor', [
            'bobot' => $this->rapor->bobot(),
            'skala' => $this->rapor->skala(),
            'periode' => Periode::with('tahunAjaran')->orderByDesc('tahun_ajaran_id')->orderByDesc('semester')->get(),
            'klienApi' => KlienApi::orderByDesc('id')->get(),
            'sinkron' => PengaturanSekolah::sinkronAktif(),
        ]);
    }

    /** Terbitkan kunci akses API rapor (untuk EduWeb). Kunci asli hanya ditampilkan sekali. */
    public function klienBaru(Request $request): RedirectResponse
    {
        $data = $request->validate(['nama' => ['required', 'string', 'max:80']], [], ['nama' => 'Nama']);
        [$klien, $kunci] = KlienApi::terbitkan(trim($data['nama']), $request->user('web')->id);
        AuditLog::catat('rapor.api_kunci_baru', "klien {$klien->id}", $request->user('web'));

        return back()->with('kunci_api_baru', $kunci)->with('berhasil', 'Kunci dibuat. Salin sekarang; tidak akan ditampilkan lagi.');
    }

    public function klienCabut(Request $request, KlienApi $klien): RedirectResponse
    {
        if ($klien->aktif()) {
            $klien->update(['dicabut_pada' => now()]);
            AuditLog::catat('rapor.api_kunci_cabut', "klien {$klien->id}", $request->user('web'));
        }

        return back()->with('berhasil', 'Kunci dicabut. Aplikasi yang memakainya langsung kehilangan akses.');
    }

    public function bobot(Request $request): RedirectResponse
    {
        $data = $request->validate(
            collect(array_keys(LayananRapor::BAWAAN))->mapWithKeys(fn ($k) => [$k => ['required', 'integer', 'between:0,100']])->all(),
            ['*.required' => 'Semua bobot wajib diisi.', '*.integer' => 'Bobot harus berupa bilangan bulat.', '*.between' => 'Bobot harus antara 0 dan 100.'],
        );

        $galat = $this->rapor->periksaBobot($data);
        if ($galat !== []) {
            throw ValidationException::withMessages(['bobot' => $galat]);
        }

        PengaturanSekolah::ambil()->update(collect($data)->mapWithKeys(fn ($v, $k) => ['bobot_'.$k => (int) $v])->all());
        AuditLog::catat('rapor.bobot', json_encode($data), $request->user('web'));

        return back()->with('berhasil', 'Bobot nilai disimpan.');
    }

    public function skala(Request $request): RedirectResponse
    {
        $baris = collect($request->input('skala', []))
            ->map(fn ($b) => ['nilai_min' => $b['nilai_min'] ?? null, 'predikat' => trim((string) ($b['predikat'] ?? '')), 'keterangan' => trim((string) ($b['keterangan'] ?? ''))])
            ->filter(fn ($b) => $b['predikat'] !== '' || filled($b['nilai_min']))
            ->values();

        $galat = [];
        foreach ($baris as $b) {
            if ($b['predikat'] === '' || mb_strlen($b['predikat']) > 10) {
                $galat[] = 'Setiap baris harus punya predikat (maksimal 10 karakter).';
            }
            if (! is_numeric($b['nilai_min']) || (int) $b['nilai_min'] != $b['nilai_min'] || $b['nilai_min'] < 0 || $b['nilai_min'] > 100) {
                $galat[] = 'Batas bawah harus bilangan bulat 0 sampai 100.';
            }
            if (mb_strlen($b['keterangan']) > 100) {
                $galat[] = 'Keterangan maksimal 100 karakter.';
            }
        }
        if ($galat === []) {
            $min = $baris->pluck('nilai_min')->map(fn ($v) => (int) $v);
            if ($min->isEmpty() || ! $min->contains(0)) {
                $galat[] = 'Harus ada predikat dengan batas bawah 0 agar semua nilai punya predikat.';
            }
            if ($min->unique()->count() !== $min->count()) {
                $galat[] = 'Batas bawah tidak boleh sama pada dua baris.';
            }
        }
        if ($galat !== []) {
            throw ValidationException::withMessages(['skala' => array_values(array_unique($galat))]);
        }

        DB::transaction(function () use ($baris) {
            SkalaPredikat::query()->delete();
            foreach ($baris as $b) {
                SkalaPredikat::create(['nilai_min' => (int) $b['nilai_min'], 'predikat' => $b['predikat'], 'keterangan' => $b['keterangan'] ?: null]);
            }
        });
        AuditLog::catat('rapor.skala', $baris->count().' baris', $request->user('web'));

        return back()->with('berhasil', 'Skala predikat disimpan.');
    }

    public function periode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'periode' => ['nullable', 'array'],
            'periode.*.mulai' => ['nullable', 'date'],
            'periode.*.selesai' => ['nullable', 'date'],
            'periode.*.rapor_di_sini' => ['nullable', 'boolean'],
        ], [], ['periode.*.mulai' => 'Tanggal mulai', 'periode.*.selesai' => 'Tanggal selesai']);

        // Dengan sinkron EduWeb menyala, tanggal semester reguler mengikuti EduWeb dan tidak diubah di sini.
        $dariEduweb = PengaturanSekolah::sinkronAktif() ? Periode::where('tipe', 'reguler')->pluck('id')->all() : [];
        $ada = Periode::pluck('id')->all();
        foreach ($data['periode'] ?? [] as $id => $t) {
            if (! in_array((int) $id, $ada, true) || in_array((int) $id, $dariEduweb, true)) {
                continue;
            }
            if (filled($t['mulai'] ?? null) && filled($t['selesai'] ?? null) && $t['selesai'] < $t['mulai']) {
                throw ValidationException::withMessages(['periode' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);
            }
        }
        foreach ($data['periode'] ?? [] as $id => $t) {
            if (in_array((int) $id, $ada, true)) {
                $simpan = array_key_exists('rapor_di_sini', $t) ? ['rapor_di_sini' => (bool) $t['rapor_di_sini']] : [];
                if (! in_array((int) $id, $dariEduweb, true)) {
                    $simpan += ['mulai' => $t['mulai'] ?: null, 'selesai' => $t['selesai'] ?: null];
                }
                if ($simpan) {
                    Periode::whereKey($id)->update($simpan);
                }
            }
        }
        AuditLog::catat('rapor.periode', 'rentang semester', $request->user('web'));

        return back()->with('berhasil', 'Pengaturan semester disimpan.');
    }
}
