<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nama kegiatan diisi sekali (mis. "Asesmen Semester Gasal"), lalu dipilih saat membuat jadwal.
        // Kegiatan yang tidak aktif disembunyikan dari daftar koreksi dan rekap guru.
        Schema::create('kegiatan_ujian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periode_id')->constrained('periode')->restrictOnDelete();
            $table->string('nama', 150);
            $table->boolean('aktif')->default(true)->index();
            $table->timestamps();

            $table->unique(['periode_id', 'nama']);
        });

        Schema::table('jadwal_ujian', function (Blueprint $table) {
            $table->foreignId('kegiatan_id')->nullable()->after('periode_id')->constrained('kegiatan_ujian')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_ujian', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kegiatan_id');
        });
        Schema::dropIfExists('kegiatan_ujian');
    }
};
