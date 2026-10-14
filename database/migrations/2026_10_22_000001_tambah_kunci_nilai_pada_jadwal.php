<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nilai yang dikunci tidak bisa diubah lagi dan baru boleh dipakai rapor.
        Schema::table('jadwal_ujian', function (Blueprint $table) {
            $table->dateTime('nilai_dikunci_pada')->nullable();
            $table->foreignId('nilai_dikunci_oleh')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_ujian', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nilai_dikunci_oleh');
            $table->dropColumn('nilai_dikunci_pada');
        });
    }
};
