<?php

use App\Http\Middleware\KunciSinkronEduweb;
use App\Http\Middleware\ModePengembang;
use App\Http\Middleware\PastikanPerangkatUjian;
use App\Http\Middleware\PeriksaSinkronEduweb;
use App\Http\Middleware\PastikanPeran;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::prefix('api/ujian')->group(base_path('routes/ujian-api.php'));
            Route::prefix('api/eduweb')->group(base_path('routes/eduweb-api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'peran' => PastikanPeran::class,
            'sinkron.kunci' => KunciSinkronEduweb::class,
            'mode.pengembang' => ModePengembang::class,
            'perangkat.ujian' => PastikanPerangkatUjian::class,
        ]);

        // Cadangan sinkron EduWeb tanpa cron (berjalan setelah respons terkirim).
        $middleware->appendToGroup('web', PeriksaSinkronEduweb::class);

        // Tamu yang membuka halaman terlindungi diarahkan ke login yang sesuai.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('staf', 'staf/*')
                ? route('staf.masuk')
                : route('siswa.masuk')
        );

        // Yang sudah login diarahkan ke berandanya sendiri.
        $middleware->redirectUsersTo(
            fn (Request $request) => $request->is('staf', 'staf/*')
                ? route('staf.beranda')
                : route('siswa.beranda')
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
