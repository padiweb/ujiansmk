<?php

namespace App\Eduweb;

/** Ringkasan satu proses sinkron: hitungan per entitas, peringatan untuk admin, dan akun guru baru (sandi sekali pakai). */
class HasilSinkron
{
    private const MAKS_PERINGATAN = 60;

    /** @var array<string,array{baru:int,diperbarui:int,dilewati:int}> */
    public array $entitas = [];

    /** @var list<string> */
    public array $peringatan = [];

    public int $peringatanTerpotong = 0;

    /** @var list<array{username:string,nama:string,sandi:string}> */
    public array $akunBaru = [];

    /** @var array<string,int> */
    public array $lain = [];

    public function baru(string $e): void
    {
        $this->hitung($e, 'baru');
    }

    public function diperbarui(string $e): void
    {
        $this->hitung($e, 'diperbarui');
    }

    public function dilewati(string $e, string $alasan): void
    {
        $this->hitung($e, 'dilewati');
        $this->peringatan($alasan);
    }

    public function peringatan(string $pesan): void
    {
        if (count($this->peringatan) < self::MAKS_PERINGATAN) {
            $this->peringatan[] = $pesan;
        } else {
            $this->peringatanTerpotong++;
        }
    }

    public function tambah(string $kunci, int $n = 1): void
    {
        $this->lain[$kunci] = ($this->lain[$kunci] ?? 0) + $n;
    }

    /** Untuk disimpan di sinkron_log (tanpa sandi). @return array<string,mixed> */
    public function ringkasan(): array
    {
        return ['entitas' => $this->entitas, 'lain' => $this->lain, 'peringatan' => $this->peringatan, 'peringatan_terpotong' => $this->peringatanTerpotong, 'akun_baru' => count($this->akunBaru)];
    }

    private function hitung(string $e, string $k): void
    {
        $this->entitas[$e] ??= ['baru' => 0, 'diperbarui' => 0, 'dilewati' => 0];
        $this->entitas[$e][$k]++;
    }
}
