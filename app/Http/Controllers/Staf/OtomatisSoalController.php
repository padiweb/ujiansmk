<?php

namespace App\Http\Controllers\Staf;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BankSoal;
use App\Models\PenyediaOtomatis;
use App\Otomatis\KesalahanOtomatis;
use App\Otomatis\PermintaanSoal;
use App\Services\LayananOtomatis;
use App\Support\GambarAman;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Susun soal otomatis dan pindai soal dari foto. Hasilnya hanya rancangan (disimpan di sesi) sampai
 * guru memeriksa, boleh mengubah, lalu menekan "Tambahkan soal".
 */
class OtomatisSoalController extends Controller
{
    public function __construct(private LayananOtomatis $layanan) {}

    public function form(Request $request, BankSoal $bank): View
    {
        $this->izin($request, $bank);

        return view('staf.bank-soal.otomatis', [
            'bank' => $bank->load('mapel'),
            'penyedia' => PenyediaOtomatis::aktif(),
            'sisa' => $this->layanan->sisaHariIni($request->user('web')),
            'batas' => $this->layanan->batasHarian(),
            'maks' => config('ujian.maks_soal_otomatis'),
            'rancangan' => $this->rancangan($bank, 'otomatis'),
        ]);
    }

    public function formFoto(Request $request, BankSoal $bank): View
    {
        $this->izin($request, $bank);

        return view('staf.bank-soal.foto', [
            'bank' => $bank->load('mapel'),
            'penyedia' => PenyediaOtomatis::aktif(),
            'sisa' => $this->layanan->sisaHariIni($request->user('web')),
            'batas' => $this->layanan->batasHarian(),
            'rancangan' => $this->rancangan($bank, 'foto'),
        ]);
    }

    public function prosesFoto(Request $request, BankSoal $bank): RedirectResponse
    {
        $this->izin($request, $bank);
        $data = $request->validate([
            'foto' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'jumlah_opsi' => ['required', Rule::in([4, 5])],
            'lampirkan' => ['nullable', 'boolean'],
        ], ['foto.required' => 'Pilih foto soal terlebih dahulu.', 'foto.mimes' => 'Foto harus berformat JPG, PNG, atau WEBP.', 'foto.max' => 'Ukuran foto maksimal 6 MB.'],
            ['foto' => 'Foto', 'jumlah_opsi' => 'Jumlah opsi']);

        [$isi, $mime] = GambarAman::bytesUntukAi($request->file('foto'));
        $permintaan = new PermintaanSoal(
            mapel: $bank->mapel->nama, tingkat: $bank->tingkat, topik: 'foto soal', jumlah: 20, tipe: 'campuran',
            jumlahOpsi: (int) $data['jumlah_opsi'], kesulitan: 'sedang', dariFoto: true,
        );

        try {
            $butir = $this->layanan->rancang($request->user('web'), $bank, $permintaan, ['bytes' => $isi, 'mime' => $mime]);
        } catch (KesalahanOtomatis $e) {
            return back()->with('gagal', $e->getMessage());
        }

        $gambar = $request->boolean('lampirkan') ? GambarAman::simpan($request->file('foto'), 'foto', 'soal') : null;
        $this->simpanRancangan($bank, 'foto', $butir, $gambar);
        AuditLog::catat('soal.foto', "bank {$bank->id}: ".count($butir).' rancangan soal', $request->user('web'));

        return redirect()->route('staf.bank-soal.foto', $bank);
    }

    public function proses(Request $request, BankSoal $bank): RedirectResponse
    {
        $this->izin($request, $bank);
        $maks = (int) config('ujian.maks_soal_otomatis');

        $data = $request->validate([
            'topik' => ['required', 'string', 'max:300'],
            'jumlah' => ['required', 'integer', "between:1,{$maks}"],
            'tipe' => ['required', Rule::in(PermintaanSoal::TIPE)],
            'jumlah_opsi' => ['required', Rule::in([4, 5])],
            'kesulitan' => ['required', Rule::in(PermintaanSoal::KESULITAN)],
            'materi' => ['nullable', 'string', 'max:4000'],
            'soal_per_bacaan' => ['nullable', 'integer', 'between:0,10'],
            'level' => ['nullable', Rule::in(PermintaanSoal::LEVEL)],
        ], [], ['level' => 'Level kognitif', 'soal_per_bacaan' => 'Soal per bacaan', 'topik' => 'Topik', 'jumlah' => 'Jumlah soal', 'tipe' => 'Jenis soal', 'jumlah_opsi' => 'Jumlah opsi',
            'kesulitan' => 'Tingkat kesulitan', 'materi' => 'Materi acuan']);

        $permintaan = new PermintaanSoal(
            mapel: $bank->mapel->nama,
            tingkat: $bank->tingkat,
            topik: $data['topik'],
            jumlah: (int) $data['jumlah'],
            tipe: $data['tipe'],
            jumlahOpsi: (int) $data['jumlah_opsi'],
            kesulitan: $data['kesulitan'],
            materi: $data['materi'] ?? null,
            soalPerBacaan: (int) ($data['soal_per_bacaan'] ?? 0),
            level: $data['level'] ?? 'campuran',
        );

        try {
            $butir = $this->layanan->rancang($request->user('web'), $bank, $permintaan);
        } catch (KesalahanOtomatis $e) {
            return back()->withInput()->with('gagal', $e->getMessage());
        }

        $this->simpanRancangan($bank, 'otomatis', $butir, null);
        AuditLog::catat('soal.otomatis', "bank {$bank->id}: ".count($butir).' rancangan soal', $request->user('web'));

        return redirect()->route('staf.bank-soal.otomatis', $bank);
    }

    /** Tambahkan butir rancangan (yang sudah diperiksa/diubah guru) ke bank sebagai soal draf. */
    public function tambah(Request $request, BankSoal $bank): RedirectResponse
    {
        $this->izin($request, $bank);
        $rancangan = $request->session()->get(self::kunci($bank));
        if (! $rancangan) {
            return redirect()->route('staf.bank-soal.show', $bank)->with('gagal', 'Tidak ada soal yang menunggu ditambahkan.');
        }
        $tujuan = $rancangan['jenis'] === 'foto' ? 'staf.bank-soal.foto' : 'staf.bank-soal.otomatis';

        $terpilih = $request->input('tambah') === 'pilih'
            ? array_map('intval', (array) $request->input('pilih', []))
            : [(int) $request->input('tambah')];
        $terpilih = array_values(array_unique(array_filter($terpilih, fn ($i) => isset($rancangan['butir'][$i]))));
        if ($terpilih === []) {
            return redirect()->route($tujuan, $bank)->with('gagal', 'Pilih soal yang akan ditambahkan.');
        }

        $diubah = (array) $request->input('butir', []);
        $bacaanKiriman = (array) $request->input('bacaan', []);
        $butir = [];
        $bacaan = [];
        $galat = [];
        foreach ($terpilih as $i) {
            $b = $rancangan['butir'][$i];
            $k = is_array($diubah[$i] ?? null) ? $diubah[$i] : [];
            $no = $i + 1;
            $b['pertanyaan'] = $this->bersih($k['pertanyaan'] ?? $b['pertanyaan'], 5000);
            $b['pembahasan'] = $this->bersih($k['pembahasan'] ?? ($b['pembahasan'] ?? ''), 3000) ?: null;
            $level = $k['level'] ?? ($b['level'] ?? '');
            $b['level'] = in_array($level, ['L1', 'L2', 'L3'], true) ? $level : null;
            if ($b['pertanyaan'] === '') {
                $galat[] = "Soal {$no}: pertanyaan tidak boleh kosong.";
            }
            if ($b['tipe'] === 'pg') {
                $kiriman = is_array($k['opsi'] ?? null) ? $k['opsi'] : [];
                foreach (array_keys($b['opsi']) as $label) {
                    $b['opsi'][$label] = $this->bersih($kiriman[$label] ?? $b['opsi'][$label], 1000);
                    if ($b['opsi'][$label] === '') {
                        $galat[] = "Soal {$no}: pilihan {$label} tidak boleh kosong.";
                    }
                }
                $kunci = (string) ($k['kunci'] ?? $b['kunci']);
                if (! isset($b['opsi'][$kunci])) {
                    $galat[] = "Soal {$no}: pilih kunci jawaban.";
                }
                $b['kunci'] = $kunci;
            } else {
                $b['rubrik'] = $this->bersih($k['rubrik'] ?? ($b['rubrik'] ?? ''), 5000) ?: null;
            }
            $kode = $b['bacaan_kode'] ?? null;
            if ($kode && isset($rancangan['bacaan'][$kode]) && ! isset($bacaan[$kode])) {
                $kir = is_array($bacaanKiriman[$kode] ?? null) ? $bacaanKiriman[$kode] : [];
                $teks = $this->bersih($kir['teks'] ?? $rancangan['bacaan'][$kode]['teks'], 8000);
                if ($teks === '') {
                    $galat[] = "Soal {$no}: teks bacaan tidak boleh kosong.";
                }
                $bacaan[$kode] = ['judul' => $this->bersih($kir['judul'] ?? ($rancangan['bacaan'][$kode]['judul'] ?? ''), 150) ?: null, 'teks' => $teks];
            }
            $butir[] = $b;
        }
        if ($galat !== []) {
            return redirect()->route($tujuan, $bank)->with('gagal', implode(' ', array_unique($galat)));
        }

        $jumlah = $this->layanan->simpanRancangan($bank, $butir, $bacaan, $rancangan['gambar'] ?? null);

        foreach ($terpilih as $i) {
            unset($rancangan['butir'][$i]);
        }
        $rancangan['butir'] = array_values($rancangan['butir']);
        $this->perbaruiRancangan($request, $bank, $rancangan);
        AuditLog::catat('soal.rancangan', "bank {$bank->id}: {$jumlah} soal ditambahkan", $request->user('web'));

        $sisa = $rancangan['butir'] === [] ? '' : ' Soal lainnya masih menunggu di bawah.';

        return redirect()->route($tujuan, $bank)->with('berhasil', "{$jumlah} soal ditambahkan ke bank sebagai draf.{$sisa}");
    }

    /** Buang satu butir rancangan, atau semuanya. */
    public function buang(Request $request, BankSoal $bank): RedirectResponse
    {
        $this->izin($request, $bank);
        $rancangan = $request->session()->get(self::kunci($bank));
        if (! $rancangan) {
            return redirect()->route('staf.bank-soal.show', $bank);
        }
        $tujuan = $rancangan['jenis'] === 'foto' ? 'staf.bank-soal.foto' : 'staf.bank-soal.otomatis';

        if ($request->input('buang') === 'semua') {
            $rancangan['butir'] = [];
        } else {
            unset($rancangan['butir'][(int) $request->input('buang')]);
            $rancangan['butir'] = array_values($rancangan['butir']);
        }
        $this->perbaruiRancangan($request, $bank, $rancangan);

        return redirect()->route($tujuan, $bank);
    }

    private static function kunci(BankSoal $bank): string
    {
        return "rancangan_soal.{$bank->id}";
    }

    /** Rancangan milik halaman $jenis (bila ada). */
    private function rancangan(BankSoal $bank, string $jenis): ?array
    {
        $r = session(self::kunci($bank));

        return $r && $r['jenis'] === $jenis && $r['butir'] !== [] ? $r : null;
    }

    private function simpanRancangan(BankSoal $bank, string $jenis, array $butir, ?string $gambar): void
    {
        $lama = session(self::kunci($bank));
        if ($lama) {
            GambarAman::hapus($lama['gambar'] ?? null);
        }

        $bacaan = [];
        foreach ($butir as $b) {
            if (! empty($b['bacaan_kode']) && isset($b['bacaan'])) {
                $bacaan[$b['bacaan_kode']] = $b['bacaan'];
            }
        }
        $butir = array_map(function ($b) {
            unset($b['bacaan']);
            if (($b['pembahasan_foto'] ?? null) !== null) {
                $b['pembahasan'] = $b['pembahasan_foto'] ?: null;
            }
            unset($b['pembahasan_foto']);

            return $b;
        }, $butir);

        session([self::kunci($bank) => ['jenis' => $jenis, 'butir' => $butir, 'bacaan' => $bacaan, 'gambar' => $gambar]]);
    }

    private function perbaruiRancangan(Request $request, BankSoal $bank, array $rancangan): void
    {
        if ($rancangan['butir'] === []) {
            GambarAman::hapus($rancangan['gambar'] ?? null);
            $request->session()->forget(self::kunci($bank));

            return;
        }
        $request->session()->put(self::kunci($bank), $rancangan);
    }

    private function bersih(mixed $teks, int $maks): string
    {
        $teks = is_string($teks) ? preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $teks) : '';

        return mb_substr(trim((string) $teks), 0, $maks);
    }

    private function izin(Request $request, BankSoal $bank): void
    {
        abort_unless($bank->dapatDikelola($request->user('web')), 403, 'Anda tidak memiliki akses ke bank soal ini.');
    }
}
