<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nilai Ujian Sekolah kelas XII per siswa dan mapel. nilai = isian manual (menimpa CBT); nilai_cbt = salinan hasil ujian saat disimpan.
        Schema::create('nilai_us', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $table->foreignId('mapel_id')->constrained('mapel')->restrictOnDelete();
            $table->decimal('nilai', 5, 2)->nullable();
            $table->decimal('nilai_cbt', 5, 2)->nullable();
            $table->string('keterangan', 255)->nullable();
            $table->boolean('masuk_xii2')->default(false); // nilai US mengisi kolom XII-2 transkrip bila semester itu kosong
            $table->foreignId('guru_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['siswa_id', 'mapel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nilai_us');
    }
};
