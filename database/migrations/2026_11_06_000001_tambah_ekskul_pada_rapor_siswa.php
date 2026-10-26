<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nama dan nilai ekskul dari EduWeb, disimpan per siswa per semester (dibekukan bersama rapor saat final).
        Schema::table('rapor_siswa', function (Blueprint $table) {
            $table->json('ekskul')->nullable()->after('catatan_wali');
        });
    }

    public function down(): void
    {
        Schema::table('rapor_siswa', fn (Blueprint $table) => $table->dropColumn('ekskul'));
    }
};
