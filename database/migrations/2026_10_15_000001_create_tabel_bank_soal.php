<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kumpulan soal satu mapel dan tingkat; dipakai ulang lintas semester.
        Schema::create('bank_soal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mapel_id')->constrained('mapel')->restrictOnDelete();
            $table->string('tingkat', 3); // X | XI | XII
            $table->string('nama', 150);
            $table->unsignedTinyInteger('jumlah_opsi_bawaan')->default(4); // 4 (A-D) atau 5 (A-E)
            $table->foreignId('dibuat_oleh')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['mapel_id', 'tingkat']);
        });

        Schema::create('soal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_soal_id')->constrained('bank_soal')->cascadeOnDelete();
            $table->string('tipe', 4); // pg | esai
            $table->text('pertanyaan');
            $table->string('gambar')->nullable(); // jalur di disk privat
            $table->decimal('bobot', 5, 2)->default(1);
            $table->text('rubrik_esai')->nullable(); // jawaban acuan untuk koreksi
            $table->string('status', 5)->default('siap'); // draf | siap
            $table->string('sumber', 10)->default('manual'); // manual | impor | otomatis
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['bank_soal_id', 'tipe', 'status']);
        });

        Schema::create('opsi_soal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('soal_id')->constrained('soal')->cascadeOnDelete();
            $table->string('label', 1); // A-E
            $table->text('teks');
            $table->boolean('benar')->default(false);

            $table->unique(['soal_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opsi_soal');
        Schema::dropIfExists('soal');
        Schema::dropIfExists('bank_soal');
    }
};
