<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Informasi untuk pengawas: sistem operasi, peramban, dan cara membuka ujian (aplikasi, PWA terpasang, peramban).
        Schema::table('sesi_ujian', function (Blueprint $table) {
            $table->string('sistem_operasi', 30)->nullable()->after('platform');
            $table->string('nama_peramban', 40)->nullable()->after('sistem_operasi');
            $table->string('mode_tampil', 12)->nullable()->after('nama_peramban');
        });
    }

    public function down(): void
    {
        Schema::table('sesi_ujian', function (Blueprint $table) {
            $table->dropColumn(['sistem_operasi', 'nama_peramban', 'mode_tampil']);
        });
    }
};
