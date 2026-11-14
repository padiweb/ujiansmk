<?php

namespace Tests\Concerns;

use App\Models\BankSoal;

trait KonfirmasiRancangan
{
    /** Menekan "Tambahkan yang dipilih" untuk semua rancangan soal yang menunggu di sesi. */
    protected function konfirmasiRancangan(BankSoal $bank, array $ubah = [])
    {
        $r = session("rancangan_soal.{$bank->id}");

        return $this->post("/staf/bank-soal/{$bank->id}/rancangan/tambah", array_merge([
            'tambah' => 'pilih',
            'pilih' => array_keys($r['butir'] ?? []),
        ], $ubah));
    }

    /** Bila POST sebelumnya menghasilkan rancangan, langsung dikonfirmasi; bila gagal, kembalikan respons aslinya. */
    protected function lanjutkanBilaBerhasil($respons, BankSoal $bank)
    {
        return session()->has("rancangan_soal.{$bank->id}") ? $this->konfirmasiRancangan($bank) : $respons;
    }
}
