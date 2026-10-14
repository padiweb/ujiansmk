<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu baris saja (satu instalasi = satu sekolah).
        Schema::create('pengaturan_sekolah', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150)->default('');
            $table->text('alamat')->nullable();
            $table->string('kota', 60)->nullable();
            $table->string('provinsi', 60)->nullable();
            $table->string('npsn', 20)->nullable();
            $table->string('kepala_sekolah', 100)->nullable();
            $table->string('nip_kepala_sekolah', 50)->nullable();
            $table->string('kurikulum', 60)->default('Kurikulum Merdeka');
            $table->string('logo')->nullable();
            // Bobot rapor (persen). Bawaan mengikuti aplikasi lama.
            $table->unsignedTinyInteger('bobot_ujian')->default(20);
            $table->unsignedTinyInteger('bobot_harian')->default(60);
            $table->unsignedTinyInteger('bobot_tugas_akhir')->default(20);
            $table->unsignedTinyInteger('bobot_praktik')->default(70);
            $table->unsignedTinyInteger('bobot_adab')->default(30);
            $table->boolean('mode_sinkron_eduweb')->default(false);
            $table->timestamps();
        });

        Schema::create('skala_predikat', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('nilai_min'); // batas bawah (>=)
            $table->string('predikat', 10);
            $table->string('keterangan', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skala_predikat');
        Schema::dropIfExists('pengaturan_sekolah');
    }
};
