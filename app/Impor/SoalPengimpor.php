<?php

namespace App\Impor;

use App\Models\BankSoal;
use App\Models\Soal;
use App\Services\LayananSoal;
use Illuminate\Validation\Rule;

/**
 * Impor soal ke satu bank. Tidak terdaftar di Pengimpor::semua() karena butuh konteks bank.
 */
class SoalPengimpor extends Pengimpor
{
    private int $urutan = 0;

    /** @var array<string,int> kode bacaan di berkas => id bacaan yang dibuat */
    private array $petaBacaan = [];

    public function __construct(private BankSoal $bank, private LayananSoal $layanan) {}

    public function slug(): string
    {
        return 'soal';
    }

    public function judul(): string
    {
        return 'Soal ke "'.$this->bank->nama.'"';
    }

    public function kolom(): array
    {
        return [
            ['header' => 'Tipe', 'key' => 'tipe', 'wajib' => true, 'contoh' => 'PG'],
            ['header' => 'Pertanyaan', 'key' => 'pertanyaan', 'wajib' => true, 'contoh' => 'Ibu kota Indonesia adalah ...'],
            ['header' => 'Opsi A', 'key' => 'a', 'wajib' => false, 'contoh' => 'Bandung'],
            ['header' => 'Opsi B', 'key' => 'b', 'wajib' => false, 'contoh' => 'Jakarta'],
            ['header' => 'Opsi C', 'key' => 'c', 'wajib' => false, 'contoh' => 'Surabaya'],
            ['header' => 'Opsi D', 'key' => 'd', 'wajib' => false, 'contoh' => 'Medan'],
            ['header' => 'Opsi E', 'key' => 'e', 'wajib' => false, 'contoh' => ''],
            ['header' => 'Kunci', 'key' => 'kunci', 'wajib' => false, 'contoh' => 'B'],
            ['header' => 'Bobot', 'key' => 'bobot', 'wajib' => false, 'contoh' => '1'],
            ['header' => 'Jawaban Acuan', 'key' => 'rubrik', 'wajib' => false, 'contoh' => ''],
            ['header' => 'Level', 'key' => 'level', 'wajib' => false, 'contoh' => 'L1'],
            ['header' => 'Pembahasan', 'key' => 'pembahasan', 'wajib' => false, 'contoh' => ''],
            ['header' => 'Kode Bacaan', 'key' => 'kode_bacaan', 'wajib' => false, 'contoh' => 'B1'],
            ['header' => 'Teks Bacaan', 'key' => 'teks_bacaan', 'wajib' => false, 'contoh' => ''],
        ];
    }

    public function petunjuk(): array
    {
        return [
            'Tipe: PG (pilihan ganda) atau Esai.',
            'PG: isi Opsi A–D (wajib) dan Opsi E (boleh kosong, berarti 4 opsi), serta Kunci (huruf A–E).',
            'Esai: Opsi dan Kunci dikosongkan; Jawaban Acuan boleh diisi untuk membantu koreksi.',
            'Bobot kosong = 1. Soal yang diimpor langsung berstatus siap dipakai.',
            'Gambar tidak bisa diimpor; tambahkan lewat halaman ubah soal.',
            'Soal berbasis bacaan: beri Kode Bacaan yang sama (mis. B1) pada beberapa soal dan isi Teks Bacaan sekali, pada soal pertama kelompok itu.',
        ];
    }

    protected function siapkan(): void
    {
        $this->urutan = (int) ($this->bank->soal()->withTrashed()->max('urutan') ?? 0);
        $this->petaBacaan = [];
    }

    protected function aturan(array $b): array
    {
        return [
            'tipe' => ['required', Rule::in(['PG', 'Esai', 'pg', 'esai', 'ESAI'])],
            'pertanyaan' => ['required', 'string', 'max:5000'],
            'a' => ['nullable', 'string', 'max:1000'],
            'b' => ['nullable', 'string', 'max:1000'],
            'c' => ['nullable', 'string', 'max:1000'],
            'd' => ['nullable', 'string', 'max:1000'],
            'e' => ['nullable', 'string', 'max:1000'],
            'kunci' => ['nullable', Rule::in(['A', 'B', 'C', 'D', 'E', 'a', 'b', 'c', 'd', 'e'])],
            'bobot' => ['nullable', 'numeric', 'between:0.01,100'],
            'rubrik' => ['nullable', 'string', 'max:5000'],
            'level' => ['nullable', Rule::in(['L1', 'L2', 'L3', 'l1', 'l2', 'l3'])],
            'pembahasan' => ['nullable', 'string', 'max:3000'],
            'kode_bacaan' => ['nullable', 'string', 'max:30'],
            'teks_bacaan' => ['nullable', 'string', 'max:8000'],
        ];
    }

    protected function kunciBaris(array $b): ?string
    {
        return null;
    }

    protected function terapkan(array $b): string
    {
        $tipe = strtolower($b['tipe']);
        $opsi = [];

        if ($tipe === Soal::PG) {
            foreach (['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd', 'E' => 'e'] as $label => $kunciKolom) {
                if (filled($b[$kunciKolom] ?? null)) {
                    $opsi[$label] = $b[$kunciKolom];
                }
            }

            foreach (['A', 'B', 'C', 'D'] as $wajib) {
                if (! isset($opsi[$wajib])) {
                    throw new GalatBaris("Soal PG wajib mengisi Opsi A sampai D (Opsi {$wajib} kosong).");
                }
            }

            $kunci = strtoupper((string) ($b['kunci'] ?? ''));
            if ($kunci === '') {
                throw new GalatBaris('Soal PG wajib mengisi Kunci.');
            }
            if (! isset($opsi[$kunci])) {
                throw new GalatBaris("Kunci {$kunci} menunjuk opsi yang kosong.");
            }
        }

        $bacaanId = null;
        $kodeBacaan = filled($b['kode_bacaan'] ?? null) ? trim($b['kode_bacaan']) : null;
        if ($kodeBacaan !== null) {
            if (! isset($this->petaBacaan[$kodeBacaan])) {
                if (! filled($b['teks_bacaan'] ?? null)) {
                    throw new GalatBaris("Kode Bacaan \"{$kodeBacaan}\" belum punya Teks Bacaan. Isi Teks Bacaan pada soal pertama yang memakai kode itu.");
                }
                $this->petaBacaan[$kodeBacaan] = $this->bank->bacaan()->create(['teks' => trim($b['teks_bacaan']), 'sumber' => 'impor'])->id;
            }
            $bacaanId = $this->petaBacaan[$kodeBacaan];
        }

        $soal = $this->bank->soal()->create([
            'bacaan_id' => $bacaanId,
            'level' => filled($b['level'] ?? null) ? strtoupper($b['level']) : null,
            'pembahasan' => $b['pembahasan'] ?? null,
            'tipe' => $tipe,
            'pertanyaan' => $b['pertanyaan'],
            'bobot' => $b['bobot'] ?? 1,
            'rubrik_esai' => $tipe === Soal::ESAI ? ($b['rubrik'] ?? null) : null,
            'status' => 'siap',
            'sumber' => 'impor',
            'urutan' => ++$this->urutan,
        ]);

        if ($tipe === Soal::PG) {
            $this->layanan->setOpsi($soal, $opsi, $kunci);
        }

        return 'baru';
    }
}
