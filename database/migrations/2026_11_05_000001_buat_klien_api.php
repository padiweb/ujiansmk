<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kunci akses aplikasi lain (mis. EduWeb) ke API baca-saja rapor. Yang tersimpan hanya hash-nya.
        Schema::create('klien_api', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 80);
            $table->string('awalan', 12);
            $table->char('hash', 64)->unique();
            $table->dateTime('terakhir_dipakai')->nullable();
            $table->string('ip_terakhir', 45)->nullable();
            $table->dateTime('dicabut_pada')->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('klien_api');
    }
};
