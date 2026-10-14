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

    // Hitungan pelanggaran (detik / jumlah).
    'pelanggaran' => [
        'jeda_sejenis' => 8,   // pelanggaran sejenis dalam jeda ini tidak dihitung dua kali
        'jeda_semua' => 3,     // satu kejadian fisik sering memicu beberapa sensor: maksimal satu hitungan per jeda ini
        'abaikan_awal' => 5,   // peralihan ke layar penuh di awal tidak dihitung
        'maks_catatan_per_menit' => 20, // pelindung dari banjir catatan
    ],

    // Pelaksanaan ujian.
    'toleransi_kirim_detik' => 90, // sinkron terakhir masih diterima sekian detik setelah batas waktu
    'jalur_media' => env('UJIAN_JALUR_MEDIA'),   // folder gambar statis; kosong = public/media/u
    'url_media' => env('UJIAN_URL_MEDIA'),       // alamat dasar gambar statis; kosong = {url}/media/u
];
