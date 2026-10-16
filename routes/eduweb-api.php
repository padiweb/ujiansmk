<?php

use App\Http\Controllers\Api\WebhookEduwebController;
use Illuminate\Support\Facades\Route;

/*
| Dimuat TANPA grup middleware web (tanpa sesi, cookie, CSRF). Keamanan ada pada tanda tangan HMAC di header.
| Dibatasi laju per IP agar tidak bisa dipakai membebani server.
*/
Route::post('/webhook', WebhookEduwebController::class)->middleware('throttle:60,1')->name('eduweb.webhook');
