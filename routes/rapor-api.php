<?php

use App\Http\Controllers\Api\RaporApiController;
use Illuminate\Support\Facades\Route;

/*
| API baca-saja rapor untuk aplikasi lain (EduWeb). Dimuat TANPA grup middleware web (tanpa sesi, cookie, CSRF).
| Keamanan: kunci Bearer berhash yang dapat dicabut; dibatasi laju per IP dan per kunci.
*/
Route::middleware(['throttle:120,1', 'api.rapor'])->group(function () {
    Route::get('/ping', [RaporApiController::class, 'ping']);
    Route::get('/status-kelas', [RaporApiController::class, 'statusKelas'])->middleware('throttle:60,1');
});
