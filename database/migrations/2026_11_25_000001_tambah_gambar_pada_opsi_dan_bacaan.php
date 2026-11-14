<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gambar pada opsi jawaban (teks opsi boleh kosong bila ada gambar) dan pada bacaan/stimulus.
        Schema::table('opsi_soal', function (Blueprint $table) {
            $table->string('gambar')->nullable()->after('teks');
        });
        Schema::table('bacaan_soal', function (Blueprint $table) {
            $table->string('gambar')->nullable()->after('teks');
        });
    }

    public function down(): void
    {
        Schema::table('opsi_soal', function (Blueprint $table) {
            $table->dropColumn('gambar');
        });
        Schema::table('bacaan_soal', function (Blueprint $table) {
            $table->dropColumn('gambar');
        });
    }
};
