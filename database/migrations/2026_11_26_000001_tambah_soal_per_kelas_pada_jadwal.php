<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bank soal milik guru pengampu yang berbeda hanya dipakai untuk kelas yang diajar gurunya (null = semua kelas jadwal).
        Schema::table('jadwal_bank', function (Blueprint $table) {
            $table->json('kelas_ids')->nullable()->after('jumlah_esai');
        });
        Schema::table('jadwal_soal', function (Blueprint $table) {
            $table->json('untuk_kelas')->nullable()->after('bobot');
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_bank', function (Blueprint $table) {
            $table->dropColumn('kelas_ids');
        });
        Schema::table('jadwal_soal', function (Blueprint $table) {
            $table->dropColumn('untuk_kelas');
        });
    }
};
