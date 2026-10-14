<?php

namespace App\Impor;

class HasilImpor
{
    public int $baru = 0;

    public int $diperbarui = 0;

    /** @var list<array{baris:int,pesan:string}> */
    public array $galat = [];

    /** Kata sandi sementara untuk akun baru: [identitas, nama, sandi]. */
    public array $sandi = [];

    public function galat(int $baris, string $pesan): void
    {
        $this->galat[] = ['baris' => $baris, 'pesan' => $pesan];
    }

    public function berhasil(): bool
    {
        return $this->galat === [];
    }

    public function total(): int
    {
        return $this->baru + $this->diperbarui;
    }
}
