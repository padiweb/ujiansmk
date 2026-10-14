<?php

namespace App\Services;

use RuntimeException;

/** Galat aturan pelaksanaan ujian; pesan aman ditampilkan kepada siswa. */
class KesalahanUjian extends RuntimeException
{
    public function __construct(string $pesan, public readonly string $kode, public readonly int $status = 409)
    {
        parent::__construct($pesan);
    }
}
