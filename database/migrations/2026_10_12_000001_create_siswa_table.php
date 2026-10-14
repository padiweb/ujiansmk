<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('siswa', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('eduweb_id')->nullable()->unique();
            $table->string('nisn', 20)->unique(); // username login
            $table->string('nis', 30)->nullable();
            $table->string('nama', 100);
            $table->string('jenis_kelamin', 1)->nullable(); // L | P
            $table->string('tempat_lahir', 60)->nullable();
            $table->date('tgl_lahir')->nullable();
            $table->string('nama_wali', 100)->nullable();
            $table->string('no_peserta_us', 50)->nullable();
            $table->string('status', 10)->default('aktif')->index(); // aktif | lulus | keluar
            $table->string('password'); // hash saja, tidak pernah teks asli
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siswa');
    }
};
