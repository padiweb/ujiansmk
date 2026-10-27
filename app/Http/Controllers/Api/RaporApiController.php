<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\Periode;
use App\Models\RaporSiswa;
use App\Models\Siswa;
use App\Models\AuditLog;
use App\Services\LayananCetakRapor;
use App\Services\LayananNilaiRapor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API baca-saja rapor. Hanya memberi data yang sudah final (status, keputusan, nilai, ekskul, absensi); tidak pernah memberi data draf. */
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

    /**
     * Isi rapor siswa yang SUDAH FINAL pada satu kelas (opsional satu siswa), untuk ditampilkan di aplikasi lain.
     * Semester: parameter "periode" (id) bila ada, selain itu semester yang berlaku untuk kelas itu. Setiap pemanggilan dicatat.
     */
    public function rapor(Request $request, LayananCetakRapor $cetak): JsonResponse
    {
        $data = $request->validate([
            'classroom_id' => ['required', 'integer', 'min:1'],
            'student_id' => ['nullable', 'integer', 'min:1'],
            'periode' => ['nullable', 'integer', 'min:1'],
        ]);

        $kelas = Kelas::where('eduweb_id', (int) $data['classroom_id'])->first();
        $periode = $kelas ? (isset($data['periode']) ? Periode::where('tahun_ajaran_id', $kelas->tahun_ajaran_id)->find($data['periode']) : $this->periode($kelas)) : null;

        if (! $kelas || ! $periode) {
            return response()->json(['classroom_id' => (int) $data['classroom_id'], 'status' => 'tidak_dikenal', 'periode' => null, 'students' => []]);
        }

        $dasar = [
            'classroom_id' => (int) $data['classroom_id'],
            'periode' => ['id' => $periode->id, 'semester' => $periode->semester, 'label' => $periode->label(), 'tahun_ajaran' => $periode->tahunAjaran?->nama],
        ];
        if (! $periode->rapor_di_sini) {
            return response()->json($dasar + ['status' => 'tidak_dipakai', 'students' => []]);
        }

        $siswaId = isset($data['student_id']) ? Siswa::where('eduweb_id', (int) $data['student_id'])->value('id') : null;
        $baris = isset($data['student_id']) && ! $siswaId ? [] : $cetak->data($kelas, $periode, $siswaId);

        $siswa = collect($baris)->filter(fn ($l) => $l['siswa']->eduweb_id !== null)->map(fn ($l) => [
            'student_id' => (int) $l['siswa']->eduweb_id,
            'nama' => $l['siswa']->nama,
            'nisn' => $l['siswa']->nisn,
            'status' => 'final',
            'mapel' => collect($l['umum'])->map(fn ($m) => $m + ['kelompok' => 'umum'])->concat(collect($l['kejuruan'])->map(fn ($m) => $m + ['kelompok' => 'kejuruan']))
                ->map(fn ($m) => ['nama' => $m['nama'], 'kelompok' => $m['kelompok'], 'np' => $m['np'], 'nk' => $m['nk'], 'na' => $m['na'], 'predikat' => $m['predikat']])->values(),
            'ekskul' => collect($l['ekskul'])->map(fn ($e) => ['nama' => $e['nama'], 'nilai' => $e['nilai'] ?? null, 'predikat' => $e['predikat'] ?? null])->values(),
            'absensi' => ['sakit' => (int) $l['sakit'], 'izin' => (int) $l['izin'], 'alpa' => (int) $l['alpa']],
            'catatan' => $l['catatan'],
            'keputusan' => $l['bagian_keputusan'] ? $l['keputusan'] : null,
        ])->values();

        AuditLog::catat('rapor.api', "kelas {$kelas->id} periode {$periode->id}: {$siswa->count()} siswa (klien ".$request->attributes->get('klien_api')?->awalan.')');

        return response()->json($dasar + ['status' => 'ok', 'students' => $siswa]);
    }

    /** Semester yang berlaku untuk kelas ini: yang aktif pada tahun ajarannya, bila tidak ada maka yang terbaru. */
    private function periode(Kelas $kelas): ?Periode
    {
        $semua = Periode::where('tahun_ajaran_id', $kelas->tahun_ajaran_id)->where('tipe', 'reguler')->orderByDesc('id')->get();

        return $semua->firstWhere('aktif', true) ?? $semua->first();
    }
}
