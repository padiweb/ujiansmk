<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\KegiatanUjian;
use App\Models\Periode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Admin mengisi nama kegiatan sekali; jadwal per mata pelajaran memilihnya. */
class KegiatanUjianController extends Controller
{
    public function index(): View
    {
        return view('staf.kegiatan.index', [
            'kegiatan' => KegiatanUjian::with('periode.tahunAjaran')->withCount('jadwal')->orderByDesc('aktif')->orderByDesc('periode_id')->orderBy('nama')->get(),
            'daftarPeriode' => Periode::with('tahunAjaran')->orderByDesc('tahun_ajaran_id')->orderBy('semester')->get(),
            'periodeAktif' => Periode::where('aktif', true)->value('id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'periode_id' => ['required', Rule::exists('periode', 'id')],
            'nama' => ['required', 'string', 'max:120', Rule::unique('kegiatan_ujian', 'nama')->where('periode_id', $request->input('periode_id'))],
        ], ['nama.unique' => 'Kegiatan dengan nama itu sudah ada pada semester ini.'], ['periode_id' => 'Semester', 'nama' => 'Nama kegiatan']);

        $k = KegiatanUjian::create(['periode_id' => $data['periode_id'], 'nama' => trim($data['nama']), 'aktif' => true]);
        AuditLog::catat('kegiatan.tambah', "kegiatan {$k->id}", $request->user('web'));

        $pesan = "Kegiatan \"{$k->nama}\" ditambahkan.";

        return ($w = $k->peringatanUjianSekolah()) ? back()->with('catatan', $pesan.' '.$w) : back()->with('berhasil', $pesan);
    }

    /** Ganti nama (nama semua jadwalnya ikut diperbarui) atau aktif/nonaktifkan. */
    public function update(Request $request, KegiatanUjian $kegiatan): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('kegiatan_ujian', 'nama')->where('periode_id', $kegiatan->periode_id)->ignore($kegiatan->id)],
            'aktif' => ['sometimes', 'boolean'],
            'untuk_rapor' => ['sometimes', 'boolean'],
        ], ['nama.unique' => 'Kegiatan dengan nama itu sudah ada pada semester ini.'], ['nama' => 'Nama kegiatan']);

        if (isset($data['nama'])) {
            $kegiatan->update(['nama' => trim($data['nama'])]);
            $kegiatan->jadwal()->with('mapel')->get()->each(fn ($j) => $j->update(['nama' => $kegiatan->namaJadwal($j->mapel)]));
        }
        if (array_key_exists('aktif', $data)) {
            $kegiatan->update(['aktif' => (bool) $data['aktif']]);
        }
        if (array_key_exists('untuk_rapor', $data)) {
            $kegiatan->update(['untuk_rapor' => (bool) $data['untuk_rapor']]);
        }
        AuditLog::catat('kegiatan.ubah', "kegiatan {$kegiatan->id}", $request->user('web'));

        return ($w = $kegiatan->fresh()->peringatanUjianSekolah()) ? back()->with('catatan', 'Kegiatan diperbarui. '.$w) : back()->with('berhasil', 'Kegiatan diperbarui.');
    }

    public function destroy(Request $request, KegiatanUjian $kegiatan): RedirectResponse
    {
        if ($kegiatan->jadwal()->exists()) {
            return back()->with('gagal', 'Kegiatan ini sudah dipakai jadwal. Nonaktifkan saja agar tidak muncul di daftar.');
        }

        $kegiatan->delete();
        AuditLog::catat('kegiatan.hapus', "kegiatan {$kegiatan->id}", $request->user('web'));

        return back()->with('berhasil', 'Kegiatan dihapus.');
    }
}
