<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Denah kini dikelola per Kegiatan Ujian (satu denah per kegiatan); denah lama tanpa kegiatan tetap berfungsi.
        Schema::table('denah_ujian', function (Blueprint $table) {
            $table->foreignId('kegiatan_id')->nullable()->after('periode_id')->unique()->constrained('kegiatan_ujian')->nullOnDelete();
        });

        // Kapasitas ruang dalam jumlah siswa (seperti aplikasi lama: satu angka per ruang).
        Schema::table('denah_ruang', function (Blueprint $table) {
            $table->unsignedSmallInteger('kapasitas')->nullable()->after('siswa_per_meja');
        });
        DB::table('denah_ruang')->update(['kapasitas' => DB::raw('jumlah_meja * siswa_per_meja')]);
    }

    public function down(): void
    {
        Schema::table('denah_ruang', fn (Blueprint $table) => $table->dropColumn('kapasitas'));
        Schema::table('denah_ujian', fn (Blueprint $table) => $table->dropConstrainedForeignId('kegiatan_id'));
    }
};
