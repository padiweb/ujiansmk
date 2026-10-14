<?php

return [
    // Alamat dasar API ujian di EduWeb, mis. https://eduweb.smkaltan.sch.id/api/exam/v1 (wajib HTTPS kecuali host lokal).
    'url' => env('EDUWEB_URL'),

    // Kunci rahasia "exu_..." dari menu Integrasi Ujian di EduWeb. Hanya di .env; tidak pernah ditampilkan atau dicatat.
    'kunci' => env('EDUWEB_KUNCI'),

    'timeout' => (int) env('EDUWEB_TIMEOUT', 15),
];
