<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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
        ]);
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
        ], [], ['periode.*.mulai' => 'Tanggal mulai', 'periode.*.selesai' => 'Tanggal selesai']);

        $ada = Periode::pluck('id')->all();
        foreach ($data['periode'] ?? [] as $id => $t) {
            if (! in_array((int) $id, $ada, true)) {
                continue;
            }
            if (filled($t['mulai'] ?? null) && filled($t['selesai'] ?? null) && $t['selesai'] < $t['mulai']) {
                throw ValidationException::withMessages(['periode' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);
            }
        }
        foreach ($data['periode'] ?? [] as $id => $t) {
            if (in_array((int) $id, $ada, true)) {
                Periode::whereKey($id)->update(['mulai' => $t['mulai'] ?: null, 'selesai' => $t['selesai'] ?: null]);
            }
        }
        AuditLog::catat('rapor.periode', 'rentang semester', $request->user('web'));

        return back()->with('berhasil', 'Rentang semester disimpan.');
    }
}
