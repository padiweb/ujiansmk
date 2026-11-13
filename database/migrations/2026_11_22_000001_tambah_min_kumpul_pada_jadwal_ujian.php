<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Menit sejak siswa mulai sebelum jawaban boleh dikumpulkan (0 = langsung boleh). Mencegah siswa terburu-buru mengumpulkan.
        Schema::table('jadwal_ujian', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_kumpul_menit')->default(0)->after('durasi_menit');
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_ujian', function (Blueprint $table) {
            $table->dropColumn('min_kumpul_menit');
        });
    }
};
