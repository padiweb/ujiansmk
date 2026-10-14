<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SkalaPredikatSeeder extends Seeder
{
    /**
     * Skala awal mengikuti aplikasi lama. Admin bebas mengubahnya per sekolah.
     * Hanya mengisi bila tabel masih kosong, jadi aman dijalankan ulang.
     */
    public function run(): void
    {
        if (DB::table('skala_predikat')->exists()) {
            return;
        }

        $now = now();
        $baris = [
            [95, 'A+'], [90, 'A'], [85, 'A-'], [80, 'B+'], [75, 'B-'], [70, 'C'], [0, 'D'],
        ];

        DB::table('skala_predikat')->insert(array_map(
            fn ($b) => ['nilai_min' => $b[0], 'predikat' => $b[1], 'created_at' => $now, 'updated_at' => $now],
            $baris,
        ));
    }
}
