<?php

namespace App\Eduweb;

use RuntimeException;

/** Galat penghubung EduWeb; pesan aman ditampilkan (tidak memuat kunci atau isi respons mentah). */
class KesalahanEduweb extends RuntimeException
{
    public function __construct(string $pesan, public readonly string $kode = 'galat', public readonly ?int $status = null)
    {
        parent::__construct($pesan);
    }
}
