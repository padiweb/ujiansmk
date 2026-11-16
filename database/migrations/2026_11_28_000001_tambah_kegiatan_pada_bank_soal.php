<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bank soal boleh ditautkan ke satu kegiatan ujian (untuk pemantauan kelengkapan per kegiatan).
        // Kosong = bank umum/lama (mis. hasil pemindahan dari aplikasi lama); tidak dihitung pada pemantauan.
        Schema::table('bank_soal', function (Blueprint $table) {
            $table->foreignId('kegiatan_id')->nullable()->after('tingkat')->constrained('kegiatan_ujian')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_soal', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kegiatan_id');
        });
    }
};
