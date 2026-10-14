<?php

return [
    // Nama yang tampil di sidebar dan halaman masuk. Ubah lewat .env untuk sekolah lain.
    'merek' => env('UJIAN_MEREK', 'E-SMK Altan'),
    'sekolah' => env('UJIAN_SEKOLAH', 'SMK Alhikmah Tanon'),
    'sekolah_singkat' => env('UJIAN_SEKOLAH_SINGKAT', 'Alhikmah Tanon'),
    'pengembang' => env('UJIAN_PENGEMBANG', 'Padiweb'),

    // Penyusun soal otomatis: batas permintaan per pengguna per hari dan soal per permintaan.
    'batas_otomatis_harian' => (int) env('UJIAN_BATAS_OTOMATIS_HARIAN', 30),
    'maks_soal_otomatis' => 10,
];
