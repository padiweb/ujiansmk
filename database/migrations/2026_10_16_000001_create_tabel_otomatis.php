<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Layanan penyusun soal otomatis. Kunci disimpan terenkripsi (cast `encrypted` di model).
        Schema::create('penyedia_otomatis', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 80);
            $table->string('driver', 15); // gemini | openai | anthropic
            $table->string('model', 80);
            $table->text('kunci');
            $table->boolean('aktif')->default(false);
            $table->timestamps();
        });

        // Satu baris per permintaan, untuk batas pemakaian harian dan audit biaya.
        Schema::create('log_otomatis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('penyedia_id')->nullable()->constrained('penyedia_otomatis')->nullOnDelete();
            $table->foreignId('bank_soal_id')->nullable()->constrained('bank_soal')->nullOnDelete();
            $table->unsignedTinyInteger('jumlah_diminta');
            $table->unsignedTinyInteger('jumlah_dihasilkan')->default(0);
            $table->string('status', 10); // berhasil | gagal
            $table->string('pesan', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_otomatis');
        Schema::dropIfExists('penyedia_otomatis');
    }
};
