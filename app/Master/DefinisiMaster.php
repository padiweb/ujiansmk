<?php

namespace App\Master;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu kelas per entitas master data. Mengatur kolom tabel, isian formulir,
 * aturan validasi, dan pencarian, sehingga satu pengendali melayani semuanya.
 *
 * Format $bidang: ['name', 'label', 'type' => text|number|date|select|password,
 *                  'options' => [nilai => label], 'rules' => [...], 'bantuan' => '...']
 */
abstract class DefinisiMaster
{
    abstract public function slug(): string;

    abstract public function judul(): string;

    /** @return class-string<Model> */
    abstract public function model(): string;

    /** @return list<array{0:string,1:string}> [atribut/relasi.titik, label] */
    abstract public function kolom(): array;

    /** @return list<array<string,mixed>> */
    abstract public function bidang(?Model $m): array;

    /** Kolom yang dicari lewat kotak pencarian. */
    public function cari(): array
    {
        return [];
    }

    /** Relasi yang dimuat sekaligus. */
    public function dengan(): array
    {
        return [];
    }

    public function urut(): string
    {
        return 'id';
    }

    public function perHalaman(): int
    {
        return 20;
    }

    /** Nama tampilan Blade untuk tabel khusus entitas ini; null memakai tabel umum dari kolom(). */
    public function tabelSendiri(): ?string
    {
        return null;
    }

    /** Data master dari EduWeb dikunci saat mode sinkron aktif. */
    public function dikunciSinkron(): bool
    {
        return true;
    }

    /** @return list<array{name:string,label:string,options:array<string,string>,terapkan:callable}> */
    public function filter(): array
    {
        return [];
    }

    public function kueri(): Builder
    {
        $kelas = $this->model();

        return $kelas::query()->with($this->dengan())->orderBy($this->urut());
    }

    /** Ubah data tervalidasi sebelum disimpan. Boleh menolak lewat pengecualian validasi. */
    public function siapkan(array $data, ?Model $m): array
    {
        return $data;
    }

    public function sesudahSimpan(Model $m, array $data, bool $baru): ?string
    {
        return null; // boleh mengembalikan catatan yang ditampilkan sekali
    }

    /** Pesan bila tidak boleh dihapus, atau null bila boleh. */
    public function tolakHapus(Model $m): ?string
    {
        return null;
    }

    public function aturan(?Model $m): array
    {
        $aturan = [];
        foreach ($this->bidang($m) as $b) {
            $aturan[$b['name']] = $b['rules'] ?? ['nullable'];
        }

        return $aturan;
    }

    public function namaBidang(?Model $m): array
    {
        return collect($this->bidang($m))->pluck('label', 'name')->all();
    }

    /** @return array<string, class-string<DefinisiMaster>> */
    public static function semua(): array
    {
        $daftar = [
            SiswaMaster::class, StafMaster::class, TahunAjaranMaster::class, PeriodeMaster::class,
            JurusanMaster::class, KelasMaster::class, MapelMaster::class, PengampuMaster::class, RuangMaster::class,
        ];

        $hasil = [];
        foreach ($daftar as $kelas) {
            $hasil[(new $kelas)->slug()] = $kelas;
        }

        return $hasil;
    }

    public static function temukan(string $slug): ?self
    {
        $kelas = static::semua()[$slug] ?? null;

        return $kelas ? new $kelas : null;
    }
}
