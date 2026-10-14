<?php

namespace App\Otomatis;

class PermintaanSoal
{
    public const TIPE = ['pg', 'esai', 'campuran'];

    public const KESULITAN = ['mudah', 'sedang', 'sulit'];

    public function __construct(
        public readonly string $mapel,
        public readonly string $tingkat,
        public readonly string $topik,
        public readonly int $jumlah,
        public readonly string $tipe,
        public readonly int $jumlahOpsi,
        public readonly string $kesulitan,
        public readonly ?string $materi = null,
    ) {}

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
