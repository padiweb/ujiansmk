<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Arsip hasil ujian CBT dari aplikasi lama (hanya dibaca). */
    public function up(): void
    {
        Schema::create('hasil_ujian_lama', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('id_lama')->unique();
            $t->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $t->foreignId('periode_id')->constrained('periode')->cascadeOnDelete();
            $t->string('mapel_nama', 150);
            $t->string('kelas_nama', 50)->nullable();
            $t->unsignedSmallInteger('benar')->default(0);
            $t->unsignedSmallInteger('salah')->default(0);
            $t->decimal('skor_pg', 6, 2)->nullable();
            $t->decimal('skor_esai', 6, 2)->nullable();
            $t->decimal('skor_akhir', 6, 2)->nullable();
            $t->boolean('sudah_dikoreksi')->default(true);
            $t->dateTime('selesai_pada')->nullable();
            $t->timestamps();
            $t->index(['periode_id', 'mapel_nama']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hasil_ujian_lama');
    }
};
