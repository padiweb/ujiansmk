<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\Periode;
use App\Models\RaporSiswa;
use App\Models\Siswa;
use App\Services\LayananNilaiRapor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API baca-saja rapor. Hanya memberi status final dan keputusan wali kelas; tidak pernah memberi nilai draf. */
class RaporApiController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true, 'server_time' => now()->toIso8601String(), 'api_version' => 1]);
    }

    /**
     * Status rapor satu kelas pada semester yang berlaku untuk tahun ajaran kelas itu (yang aktif, atau yang terbaru).
     * Status kelas: "tidak_dipakai" (semester itu tidak diisi di sini), "final" (semua siswa final), atau "belum_final".
     */
    public function statusKelas(Request $request, LayananNilaiRapor $nilai): JsonResponse
    {
        $data = $request->validate(['classroom_id' => ['required', 'integer', 'min:1']]);

        $kelas = Kelas::where('eduweb_id', (int) $data['classroom_id'])->first();
        $periode = $kelas ? $this->periode($kelas) : null;

        if (! $kelas || ! $periode) {
            return response()->json(['classroom_id' => (int) $data['classroom_id'], 'status' => 'tidak_dikenal', 'periode' => null, 'students' => []]);
        }

        $dasar = [
            'classroom_id' => (int) $data['classroom_id'],
            'periode' => ['id' => $periode->id, 'semester' => $periode->semester, 'label' => $periode->label()],
        ];

        if (! $periode->rapor_di_sini) {
            return response()->json($dasar + ['status' => 'tidak_dipakai', 'students' => []]);
        }

        $rapor = RaporSiswa::where('kelas_id', $kelas->id)->where('periode_id', $periode->id)->get()->keyBy('siswa_id');
        $idEdu = Siswa::whereIn('id', $nilai->siswa($kelas)->pluck('id'))->whereNotNull('eduweb_id')->pluck('eduweb_id', 'id');
        $siswa = $idEdu->map(function ($edu, $id) use ($rapor) {
            $r = $rapor[$id] ?? null;
            $final = $r?->status === 'final';

            return ['student_id' => (int) $edu, 'status' => $final ? 'final' : 'belum_final', 'keputusan' => $final ? $r->keputusan : null];
        })->values();

        return response()->json($dasar + [
            'status' => $siswa->isNotEmpty() && $siswa->every(fn ($s) => $s['status'] === 'final') ? 'final' : 'belum_final',
            'students' => $siswa,
        ]);
    }

    /** Semester yang berlaku untuk kelas ini: yang aktif pada tahun ajarannya, bila tidak ada maka yang terbaru. */
    private function periode(Kelas $kelas): ?Periode
    {
        $semua = Periode::where('tahun_ajaran_id', $kelas->tahun_ajaran_id)->where('tipe', 'reguler')->orderByDesc('id')->get();

        return $semua->firstWhere('aktif', true) ?? $semua->first();
    }
}
