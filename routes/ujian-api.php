<?php

use App\Http\Controllers\Api\UjianApiController;
use Illuminate\Support\Facades\Route;

/*
| Titik akhir pelaksanaan ujian. Dimuat TANPA grup middleware web (tanpa sesi, cookie, atau CSRF) supaya
| ringan saat ratusan siswa menyinkronkan jawaban. Keamanan: header X-Perangkat (token acak per sesi ujian)
| diperiksa PastikanPerangkatUjian; permintaan lintas situs tidak bisa menyertakan header khusus itu.
*/
Route::middleware('perangkat.ujian')->prefix('{sesi}')->whereNumber('sesi')->group(function () {
    Route::get('/paket', [UjianApiController::class, 'paket']);
    Route::post('/sinkron', [UjianApiController::class, 'sinkron']);
    Route::post('/kirim', [UjianApiController::class, 'kirim']);
});
