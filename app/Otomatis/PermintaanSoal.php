<?php

namespace App\Otomatis;

class PermintaanSoal
{
    public const TIPE = ['pg', 'esai', 'campuran'];

    public const KESULITAN = ['mudah', 'sedang', 'sulit'];

    /** Level kognitif yang diminta: campuran (tersebar) atau satu level. */
    public const LEVEL = ['campuran', 'L1', 'L2', 'L3'];

    public function __construct(
        public readonly string $mapel,
        public readonly string $tingkat,
        public readonly string $topik,
        public readonly int $jumlah,
        public readonly string $tipe,
        public readonly int $jumlahOpsi,
        public readonly string $kesulitan,
        public readonly ?string $materi = null,
        public readonly int $soalPerBacaan = 0, // 0 = tanpa bacaan; >0 = tiap bacaan dipakai sekian soal
        public readonly string $level = 'campuran',
    ) {}

    /** Jumlah bacaan yang diminta (0 bila tanpa bacaan). */
    public function jumlahBacaan(): int
    {
        return $this->soalPerBacaan > 0 ? (int) ceil($this->jumlah / $this->soalPerBacaan) : 0;
    }

    /** Jumlah soal PG dan esai yang diminta. */
    public function pembagian(): array
    {
        return match ($this->tipe) {
            'pg' => [$this->jumlah, 0],
            'esai' => [0, $this->jumlah],
            default => [(int) ceil($this->jumlah / 2), (int) floor($this->jumlah / 2)],
        };
    }
}
