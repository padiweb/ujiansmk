<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hanya kegiatan bertanda "untuk rapor" yang nilainya menjadi sumber nilai Ujian di rapor (mis. bukan try out).
        Schema::table('kegiatan_ujian', function (Blueprint $table) {
            $table->boolean('untuk_rapor')->default(true)->after('aktif');
        });
    }

    public function down(): void
    {
        Schema::table('kegiatan_ujian', fn (Blueprint $table) => $table->dropColumn('untuk_rapor'));
    }
};
