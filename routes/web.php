<?php

use App\Http\Controllers\Auth\MasukSiswaController;
use App\Http\Controllers\Auth\MasukStafController;
use App\Http\Controllers\BerandaController;
use App\Http\Controllers\Staf\ImporController;
use App\Http\Controllers\Staf\MasterDataController;
use App\Http\Controllers\Staf\PengaturanSekolahController;
use Illuminate\Support\Facades\Route;

// ── Siswa (login: NISN + kata sandi) ─────────────────────────────────────────
Route::middleware('guest:siswa')->group(function () {
    Route::get('/', [MasukSiswaController::class, 'tampil'])->name('siswa.masuk');
    Route::post('/masuk', [MasukSiswaController::class, 'masuk'])->name('siswa.masuk.kirim');
});

Route::middleware('auth:siswa')->group(function () {
    Route::get('/beranda', [BerandaController::class, 'siswa'])->name('siswa.beranda');
    Route::post('/keluar', [MasukSiswaController::class, 'keluar'])->name('siswa.keluar');
});

// ── Staf: admin, guru, pengawas ──────────────────────────────────────────────
Route::prefix('staf')->group(function () {
    Route::middleware('guest:web')->group(function () {
        Route::get('/masuk', [MasukStafController::class, 'tampil'])->name('staf.masuk');
        Route::post('/masuk', [MasukStafController::class, 'masuk'])->name('staf.masuk.kirim');
    });

    Route::middleware(['auth:web', 'peran:admin,guru,pengawas'])->group(function () {
        Route::get('/', [BerandaController::class, 'staf'])->name('staf.beranda');
        Route::post('/keluar', [MasukStafController::class, 'keluar'])->name('staf.keluar');
    });

    // Hanya admin: master data, impor, pengaturan.
    Route::middleware(['auth:web', 'peran:admin'])->group(function () {
        Route::get('/pengaturan', [PengaturanSekolahController::class, 'edit'])->name('staf.pengaturan');
        Route::put('/pengaturan', [PengaturanSekolahController::class, 'update'])->name('staf.pengaturan.simpan');

        Route::get('/impor/sandi/{token}', [ImporController::class, 'unduhSandi'])->name('staf.impor.sandi');

        Route::prefix('master/{entitas}')->where(['entitas' => '[a-z-]+'])->group(function () {
            Route::get('/', [MasterDataController::class, 'index'])->name('staf.master.index');

            // Perubahan data dikunci bila mode sinkron EduWeb aktif.
            Route::middleware('sinkron.kunci')->group(function () {
                Route::get('/tambah', [MasterDataController::class, 'create'])->name('staf.master.create');
                Route::post('/', [MasterDataController::class, 'store'])->name('staf.master.store');
                Route::get('/{id}/ubah', [MasterDataController::class, 'edit'])->whereNumber('id')->name('staf.master.edit');
                Route::put('/{id}', [MasterDataController::class, 'update'])->whereNumber('id')->name('staf.master.update');
                Route::delete('/{id}', [MasterDataController::class, 'destroy'])->whereNumber('id')->name('staf.master.destroy');

                Route::get('/impor', [ImporController::class, 'form'])->name('staf.impor.form');
                Route::get('/impor/templat', [ImporController::class, 'templat'])->name('staf.impor.templat');
                Route::post('/impor', [ImporController::class, 'pratinjau'])->name('staf.impor.pratinjau');
                Route::post('/impor/{token}', [ImporController::class, 'simpan'])->name('staf.impor.simpan');
            });
        });
    });
});
