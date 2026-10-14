<?php

namespace App\Impor;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as TanggalExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Impor Excel semua-atau-tidak-sama-sekali. Setiap baris divalidasi dan diproses
 * di dalam transaksi; bila ada satu galat, atau hanya pratinjau, semuanya dibatalkan.
 *
 * Format $kolom: ['header' => 'NISN', 'key' => 'nisn', 'wajib' => true, 'contoh' => '0012345678'].
 */
abstract class Pengimpor
{
    public const MAKS_BARIS = 1000;

    /** true bila hasil benar-benar disimpan (bukan pratinjau). */
    protected bool $simpan = false;

    protected HasilImpor $hasil;

    abstract public function slug(): string;

    abstract public function judul(): string;

    /** @return list<array{header:string,key:string,wajib:bool,contoh:string}> */
    abstract public function kolom(): array;

    /** Aturan validasi baris yang sudah dinormalisasi (kunci = key kolom). */
    abstract protected function aturan(array $b): array;

    /** Simpan satu baris; kembalikan 'baru' atau 'diperbarui'. Lempar GalatBaris bila gagal. */
    abstract protected function terapkan(array $b): string;

    /** Kunci unik baris di dalam berkas, untuk mendeteksi duplikat; null = tanpa pengecekan. */
    abstract protected function kunciBaris(array $b): ?string;

    /** Panduan singkat di lembar "Petunjuk" templat. */
    public function petunjuk(): array
    {
        return [];
    }

    /** Dipanggil sekali sebelum baris pertama (muat data acuan). */
    protected function siapkan(): void {}

    /** @return array<string, class-string<Pengimpor>> */
    public static function semua(): array
    {
        $hasil = [];
        foreach ([SiswaPengimpor::class, GuruPengimpor::class, JurusanPengimpor::class, KelasPengimpor::class,
            MapelPengimpor::class, PengampuPengimpor::class, RuangPengimpor::class] as $kelas) {
            $hasil[(new $kelas)->slug()] = $kelas;
        }

        return $hasil;
    }

    public static function temukan(string $slug): ?self
    {
        $kelas = static::semua()[$slug] ?? null;

        return $kelas ? new $kelas : null;
    }

    public function jalankan(string $jalurBerkas, bool $simpan): HasilImpor
    {
        $this->simpan = $simpan;
        $this->hasil = new HasilImpor;
        set_time_limit(600);

        try {
            $baris = $this->bacaBaris($jalurBerkas);
        } catch (GalatBaris $e) {
            $this->hasil->galat(0, $e->getMessage());

            return $this->hasil;
        }

        DB::beginTransaction();
        try {
            $this->siapkan();
            $terlihat = [];

            foreach ($baris as $no => $b) {
                try {
                    $v = Validator::make($b, $this->aturan($b), [], collect($this->kolom())->pluck('header', 'key')->all());
                    if ($v->fails()) {
                        throw new GalatBaris($v->errors()->first());
                    }

                    if (($kunci = $this->kunciBaris($b)) !== null) {
                        if (isset($terlihat[$kunci])) {
                            throw new GalatBaris("Duplikat dengan baris {$terlihat[$kunci]} (\"{$kunci}\").");
                        }
                        $terlihat[$kunci] = $no;
                    }

                    $this->terapkan($b) === 'baru' ? $this->hasil->baru++ : $this->hasil->diperbarui++;
                } catch (GalatBaris $e) {
                    $this->hasil->galat($no, $e->getMessage());
                }
            }
        } finally {
            ($this->simpan && $this->hasil->berhasil()) ? DB::commit() : DB::rollBack();
        }

        if (! $this->hasil->berhasil()) {
            $this->hasil->baru = $this->hasil->diperbarui = 0;
            $this->hasil->sandi = [];
        }

        return $this->hasil;
    }

    /** @return array<int, array<string, ?string>> nomor baris Excel => nilai per key */
    protected function bacaBaris(string $jalur): array
    {
        try {
            $pembaca = IOFactory::createReader('Xlsx');
            $pembaca->setReadDataOnly(true);
            $lembar = $pembaca->load($jalur)->getSheet(0);
        } catch (\Throwable) {
            throw new GalatBaris('Berkas tidak dapat dibaca. Gunakan templat .xlsx dari halaman impor.');
        }

        $tertinggi = $lembar->getHighestDataRow();
        if ($tertinggi - 1 > self::MAKS_BARIS) {
            throw new GalatBaris('Terlalu banyak baris (maksimal '.self::MAKS_BARIS.' per berkas). Pecah menjadi beberapa berkas.');
        }

        $petaHeader = $this->petaHeader($lembar);
        $hilang = collect($this->kolom())
            ->filter(fn ($k) => $k['wajib'] && ! isset($petaHeader[$k['key']]))
            ->pluck('header');
        if ($hilang->isNotEmpty()) {
            throw new GalatBaris('Kolom wajib tidak ditemukan di baris pertama: '.$hilang->implode(', ').'.');
        }

        $hasil = [];
        for ($r = 2; $r <= $tertinggi; $r++) {
            $b = [];
            $kosong = true;
            foreach ($petaHeader as $key => $kolomHuruf) {
                $sel = $lembar->getCell($kolomHuruf.$r);
                $nilai = $this->nilaiSel($sel->getValue(), $sel->getFormattedValue(), $sel->getStyle()->getNumberFormat()->getFormatCode());
                $b[$key] = $nilai;
                $kosong = $kosong && $nilai === null;
            }
            if (! $kosong) {
                $hasil[$r] = $b;
            }
        }

        if ($hasil === []) {
            throw new GalatBaris('Berkas tidak berisi data. Isi mulai baris kedua.');
        }

        return $hasil;
    }

    /** @return array<string,string> key => huruf kolom */
    private function petaHeader(Worksheet $lembar): array
    {
        $peta = [];
        $cocok = collect($this->kolom())->mapWithKeys(fn ($k) => [$this->rapikan($k['header']) => $k['key']]);
        $hurufTertinggi = $lembar->getHighestDataColumn(1);
        foreach ($lembar->getColumnIterator('A', $hurufTertinggi) as $kolom) {
            $huruf = $kolom->getColumnIndex();
            $judul = $this->rapikan((string) $lembar->getCell($huruf.'1')->getValue());
            if (isset($cocok[$judul])) {
                $peta[$cocok[$judul]] = $huruf;
            }
        }

        return $peta;
    }

    private function rapikan(string $teks): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($teks));
    }

    /** Tanggal Excel menjadi Y-m-d; angka panjang tetap teks utuh (NISN tidak boleh jadi 1E+9). */
    private function nilaiSel(mixed $mentah, mixed $terformat, string $kodeFormat): ?string
    {
        if ($mentah === null || trim((string) $mentah) === '') {
            return null;
        }

        if (is_numeric($mentah) && TanggalExcel::isDateTimeFormatCode($kodeFormat)) {
            return TanggalExcel::excelToDateTimeObject((float) $mentah)->format('Y-m-d');
        }

        if (is_float($mentah) || is_int($mentah)) {
            $teks = rtrim(rtrim(number_format((float) $mentah, 6, '.', ''), '0'), '.');

            return $teks === '' ? '0' : $teks;
        }

        return trim((string) $mentah);
    }

    /** Terima tanggal Y-m-d atau d/m/Y; selain itu galat. */
    protected function tanggal(?string $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y'] as $format) {
            $t = \DateTime::createFromFormat($format, $nilai);
            if ($t && $t->format(ltrim($format, '!')) === $nilai) {
                return $t->format('Y-m-d');
            }
        }

        throw new GalatBaris("Tanggal \"{$nilai}\" tidak dikenali. Gunakan format 31/12/2008.");
    }

    public function buatTemplat(): Spreadsheet
    {
        $buku = new Spreadsheet;
        $lembar = $buku->getActiveSheet()->setTitle('Data');

        foreach ($this->kolom() as $i => $k) {
            $huruf = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $lembar->setCellValue($huruf.'1', $k['header'].($k['wajib'] ? ' *' : ''));
            $lembar->getStyle($huruf)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $lembar->setCellValueExplicit($huruf.'2', $k['contoh'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $lembar->getColumnDimension($huruf)->setWidth(max(14, mb_strlen($k['header']) + 6));
        }
        $lembar->getStyle('1:1')->getFont()->setBold(true);
        $lembar->freezePane('A2');

        $petunjuk = $buku->createSheet()->setTitle('Petunjuk');
        $baris = array_merge(
            ['Petunjuk pengisian: '.$this->judul(), '', 'Kolom bertanda * wajib diisi.', 'Baris 2 adalah contoh; ganti atau hapus sebelum mengunggah.', 'Jangan mengubah judul kolom di baris pertama.', ''],
            $this->petunjuk(),
        );
        foreach ($baris as $i => $teks) {
            $petunjuk->setCellValue('A'.($i + 1), $teks);
        }
        $petunjuk->getColumnDimension('A')->setWidth(100);
        $petunjuk->getStyle('A1')->getFont()->setBold(true);

        return $buku;
    }

    /**
     * Nilai untuk kolom password. Saat pratinjau dipakai hash bcrypt palsu (bentuknya valid,
     * jadi cast `hashed` tidak menghitung ulang) agar ratusan baris tidak memakan waktu.
     */
    protected function sandiUntukDisimpan(string $polos): string
    {
        return $this->simpan ? $polos : '$2y$04$'.str_repeat('.', 53);
    }

    protected function tambahSandi(string $identitas, string $nama, string $sandi): void
    {
        if ($this->simpan) {
            $this->hasil->sandi[] = [$identitas, $nama, $sandi];
        }
    }
}
