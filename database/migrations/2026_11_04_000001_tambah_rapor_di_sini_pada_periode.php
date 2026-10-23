<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Semester yang rapornya diisi di aplikasi ini. Bila dimatikan (mis. ujian kembali ke kertas), tidak ada menu rapor
        // untuk semester itu dan EduWeb tidak menahan kenaikan kelas karena rapor.
        Schema::table('periode', function (Blueprint $table) {
            $table->boolean('rapor_di_sini')->default(true)->after('selesai');
        });
    }

    public function down(): void
    {
        Schema::table('periode', fn (Blueprint $table) => $table->dropColumn('rapor_di_sini'));
    }
};
