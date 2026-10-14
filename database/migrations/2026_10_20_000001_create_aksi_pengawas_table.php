<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aksi_pengawas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesi_id')->constrained('sesi_ujian')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('aksi', 20); // aktifkan_kembali | reset_perangkat | tambah_waktu | paksa_selesai
            $table->string('detail', 255)->nullable();
            $table->dateTime('created_at');

            $table->index(['sesi_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aksi_pengawas');
    }
};
