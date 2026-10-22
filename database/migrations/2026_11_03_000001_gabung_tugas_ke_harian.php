<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nilai tugas EduWeb adalah komponen Harian, bukan komponen tersendiri.
        DB::table('pengaturan_sekolah')->update(['bobot_harian' => DB::raw('bobot_harian + bobot_tugas')]);
        DB::table('nilai_rapor')->whereNotNull('tugas')->whereNull('harian')->update(['harian' => DB::raw('tugas')]);

        Schema::table('pengaturan_sekolah', fn (Blueprint $table) => $table->dropColumn('bobot_tugas'));
        Schema::table('nilai_rapor', function (Blueprint $table) {
            $table->dropColumn(['tugas', 'tugas_sumber']);
            $table->string('harian_sumber', 10)->nullable()->after('harian');
        });
    }

    public function down(): void
    {
        Schema::table('nilai_rapor', function (Blueprint $table) {
            $table->dropColumn('harian_sumber');
            $table->decimal('tugas', 5, 2)->nullable();
            $table->string('tugas_sumber', 10)->nullable();
        });
        Schema::table('pengaturan_sekolah', function (Blueprint $table) {
            $table->unsignedTinyInteger('bobot_tugas')->default(0)->after('bobot_ujian');
        });
    }
};
