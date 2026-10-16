<?php

return [
    // Alamat dasar API ujian di EduWeb, mis. https://eduweb.smkaltan.sch.id/api/exam/v1 (wajib HTTPS kecuali host lokal).
    'url' => env('EDUWEB_URL'),

    // Kunci rahasia "exu_..." dari menu Integrasi Ujian di EduWeb. Hanya di .env; tidak pernah ditampilkan atau dicatat.
    'kunci' => env('EDUWEB_KUNCI'),

    'timeout' => (int) env('EDUWEB_TIMEOUT', 15),

    // Rahasia bersama untuk memeriksa tanda tangan webhook dari EduWeb (dibuat di EduWeb, disalin sekali ke .env).
    'webhook_rahasia' => env('EDUWEB_WEBHOOK_RAHASIA'),

    // Sinkron kejadian otomatis (webhook + pengecekan cadangan saat halaman dibuka; tanpa cron).
    'otomatis' => (bool) env('EDUWEB_OTOMATIS', true),
    'cek_menit' => (int) env('EDUWEB_CEK_MENIT', 5),
];
