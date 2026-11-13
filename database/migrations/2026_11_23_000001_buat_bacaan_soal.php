<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bacaan/stimulus (cerita, pernyataan, data) yang dipakai bersama oleh beberapa soal dalam satu bank.
        Schema::create('bacaan_soal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_soal_id')->constrained('bank_soal')->cascadeOnDelete();
            $table->string('judul', 150)->nullable();
            $table->text('teks');
            $table->string('sumber', 12)->default('manual'); // manual | otomatis | impor
            $table->timestamps();
            $table->index('bank_soal_id');
        });

        Schema::table('soal', function (Blueprint $table) {
            $table->foreignId('bacaan_id')->nullable()->after('bank_soal_id')->constrained('bacaan_soal')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('soal', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bacaan_id');
        });
        Schema::dropIfExists('bacaan_soal');
    }
};
