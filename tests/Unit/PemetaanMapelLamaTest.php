<?php

namespace Tests\Unit;

use App\Migrasi\PemindahDataLama;
use Tests\TestCase;

class PemetaanMapelLamaTest extends TestCase
{
    public function test_nama_mapel_lama_diterjemahkan_lewat_pemetaan(): void
    {
        config(['migrasi_mapel.pemetaan' => ['Pendidikan Agama Islam' => 'Pendidikan Agama Islam dan Budi Pekerti']]);

        $this->assertSame('Pendidikan Agama Islam dan Budi Pekerti', PemindahDataLama::namaMapelBaru('  pendidikan agama islam '));
        $this->assertSame('Matematika', PemindahDataLama::namaMapelBaru('Matematika'));
    }

    public function test_tanpa_pemetaan_nama_tidak_berubah(): void
    {
        config(['migrasi_mapel.pemetaan' => []]);

        $this->assertSame('Sejarah', PemindahDataLama::namaMapelBaru(' Sejarah '));
    }
}
