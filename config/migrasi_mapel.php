<?php

/*
| Pemetaan nama mapel aplikasi lama ke nama mapel di EduWeb, khusus untuk perintah
| migrasi:lama. Dipakai bila penulisannya berbeda padahal mapelnya sama.
|
|   'nama di aplikasi lama' => 'nama di EduWeb',
|
| Huruf besar/kecil dan spasi di pinggir diabaikan. Nama mapel di EduWeb tidak
| perlu diubah. Mapel yang tidak ada sama sekali di EduWeb harus ditambahkan
| di EduWeb lebih dulu (lalu eduweb:sinkron).
*/
return [
    'pemetaan' => [
        // Penulisan berbeda, mapelnya sama:
        'Pendidikan Jasmani Olahraga dan Kesehatan' => 'Pendidikan Jasmani, Olahraga, dan Kesehatan',
        'Projek Kreatif dan Kewirausahaan' => 'Kreativitas, Inovasi, dan Kewirausahaan',
        // Penulisan di tabel soal lama (tulisan bebas, termasuk salah ketik):
        'Pendidikan Agama Islam' => 'Pendidikan Agama Islam dan Budi Pekerti',
        'Keahlian Desian Produksi Busana' => 'Keahlian Desain dan Produksi Busana',
    ],
];
