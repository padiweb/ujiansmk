<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Sengaja kosong: akun admin pertama dibuat lewat `php artisan pengguna:buat`
     * supaya tidak ada kata sandi bawaan yang tertulis di kode.
     */
    public function run(): void
    {
        $this->call(SkalaPredikatSeeder::class);
    }
}
