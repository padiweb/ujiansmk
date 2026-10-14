<?php

use App\Http\Controllers\Auth\MasukSiswaController;
use App\Http\Controllers\Auth\MasukStafController;
use App\Http\Controllers\BerandaController;
use App\Http\Controllers\Staf\BankSoalController;
use App\Http\Controllers\Staf\ImporController;
use App\Http\Controllers\Staf\JadwalUjianController;
use App\Http\Controllers\Staf\ImporSoalController;
use App\Http\Controllers\Staf\MasterDataController;
use App\Http\Controllers\Staf\OtomatisSoalController;
use App\Http\Controllers\Staf\PenyediaOtomatisController;
use App\Http\Controllers\Staf\PengaturanSekolahController;
use App\Http\Controllers\Staf\SoalController;
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

    // Admin dan guru: bank soal (guru hanya bank miliknya sendiri; dicek di pengendali).
    Route::middleware(['auth:web', 'peran:admin,guru'])->group(function () {
        Route::get('/gambar/soal/{soal}', [SoalController::class, 'gambar'])->whereNumber('soal')->name('staf.soal.gambar');

        Route::prefix('bank-soal')->group(function () {
            Route::get('/', [BankSoalController::class, 'index'])->name('staf.bank-soal.index');
            Route::get('/tambah', [BankSoalController::class, 'create'])->name('staf.bank-soal.create');
            Route::post('/', [BankSoalController::class, 'store'])->name('staf.bank-soal.store');

            Route::prefix('{bank}')->whereNumber('bank')->group(function () {
                Route::get('/', [BankSoalController::class, 'show'])->name('staf.bank-soal.show');
                Route::get('/ubah', [BankSoalController::class, 'edit'])->name('staf.bank-soal.edit');
                Route::put('/', [BankSoalController::class, 'update'])->name('staf.bank-soal.update');
                Route::delete('/', [BankSoalController::class, 'destroy'])->name('staf.bank-soal.destroy');
                Route::post('/salin', [BankSoalController::class, 'salin'])->name('staf.bank-soal.salin');
                Route::post('/siapkan-semua', [BankSoalController::class, 'siapkanSemua'])->name('staf.bank-soal.siapkan-semua');

                Route::get('/impor', [ImporSoalController::class, 'form'])->name('staf.bank-soal.impor.form');
                Route::get('/impor/templat', [ImporSoalController::class, 'templat'])->name('staf.bank-soal.impor.templat');
                Route::post('/impor', [ImporSoalController::class, 'pratinjau'])->name('staf.bank-soal.impor.pratinjau');
                Route::post('/impor/{token}', [ImporSoalController::class, 'simpan'])->name('staf.bank-soal.impor.simpan');

                Route::get('/otomatis', [OtomatisSoalController::class, 'form'])->name('staf.bank-soal.otomatis');
                Route::post('/otomatis', [OtomatisSoalController::class, 'proses'])->name('staf.bank-soal.otomatis.proses');

                Route::get('/soal/tambah', [SoalController::class, 'create'])->name('staf.soal.create');
                Route::post('/soal', [SoalController::class, 'store'])->name('staf.soal.store');
                Route::get('/soal/{soal}/ubah', [SoalController::class, 'edit'])->whereNumber('soal')->name('staf.soal.edit');
                Route::put('/soal/{soal}', [SoalController::class, 'update'])->whereNumber('soal')->name('staf.soal.update');
                Route::delete('/soal/{soal}', [SoalController::class, 'destroy'])->whereNumber('soal')->name('staf.soal.destroy');
                Route::post('/soal/{soal}/status', [SoalController::class, 'status'])->whereNumber('soal')->name('staf.soal.status');
            });
        });
    });

    // Hanya admin: master data, impor, pengaturan.
    Route::middleware(['auth:web', 'peran:admin'])->group(function () {
        Route::get('/pengaturan', [PengaturanSekolahController::class, 'edit'])->name('staf.pengaturan');
        Route::put('/pengaturan', [PengaturanSekolahController::class, 'update'])->name('staf.pengaturan.simpan');

        Route::get('/impor/sandi/{token}', [ImporController::class, 'unduhSandi'])->name('staf.impor.sandi');

        Route::prefix('jadwal-ujian')->name('staf.jadwal.')->group(function () {
            Route::get('/', [JadwalUjianController::class, 'index'])->name('index');
            Route::get('/tambah', [JadwalUjianController::class, 'create'])->name('create');
            Route::post('/', [JadwalUjianController::class, 'store'])->name('store');
            Route::prefix('{jadwal}')->whereNumber('jadwal')->group(function () {
                Route::get('/', [JadwalUjianController::class, 'show'])->name('show');
                Route::get('/ubah', [JadwalUjianController::class, 'edit'])->name('edit');
                Route::put('/', [JadwalUjianController::class, 'update'])->name('update');
                Route::delete('/', [JadwalUjianController::class, 'destroy'])->name('destroy');
                Route::post('/aktifkan', [JadwalUjianController::class, 'aktifkan'])->name('aktifkan');
                Route::post('/batalkan', [JadwalUjianController::class, 'batalkan'])->name('batalkan');
                Route::post('/token', [JadwalUjianController::class, 'regenerasiToken'])->name('token');
                Route::post('/selesaikan', [JadwalUjianController::class, 'selesaikan'])->name('selesaikan');
            });
        });

        Route::prefix('penyedia-otomatis')->name('staf.penyedia.')->group(function () {
            Route::get('/', [PenyediaOtomatisController::class, 'index'])->name('index');
            Route::get('/tambah', [PenyediaOtomatisController::class, 'create'])->name('create');
            Route::post('/', [PenyediaOtomatisController::class, 'store'])->name('store');
            Route::get('/{penyedia}/ubah', [PenyediaOtomatisController::class, 'edit'])->whereNumber('penyedia')->name('edit');
            Route::put('/{penyedia}', [PenyediaOtomatisController::class, 'update'])->whereNumber('penyedia')->name('update');
            Route::delete('/{penyedia}', [PenyediaOtomatisController::class, 'destroy'])->whereNumber('penyedia')->name('destroy');
            Route::post('/{penyedia}/aktifkan', [PenyediaOtomatisController::class, 'aktifkan'])->whereNumber('penyedia')->name('aktifkan');
            Route::post('/{penyedia}/nonaktifkan', [PenyediaOtomatisController::class, 'nonaktifkan'])->whereNumber('penyedia')->name('nonaktifkan');
        });

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
