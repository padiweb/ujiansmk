<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jadwal_ujian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periode_id')->constrained('periode')->restrictOnDelete();
            $table->foreignId('mapel_id')->constrained('mapel')->restrictOnDelete();
            $table->string('nama', 150);
            $table->unsignedSmallInteger('durasi_menit');
            $table->dateTime('mulai_pada'); // jadwal mulai dibuka
            $table->dateTime('tutup_pada'); // batas terakhir siswa boleh mulai
            $table->string('token', 8)->nullable()->unique(); // dibuat saat diaktifkan
            $table->string('status', 10)->default('draf')->index(); // draf | aktif | selesai
            $table->boolean('acak_soal')->default(true);
            $table->boolean('acak_opsi')->default(true);
            $table->unsignedTinyInteger('batas_pelanggaran')->default(3);
            $table->unsignedTinyInteger('bobot_pg')->default(100); // bobot esai = 100 - bobot_pg
            $table->string('tampilkan_hasil', 10)->default('langsung'); // langsung | ditahan
            $table->foreignId('dibuat_oleh')->constrained('users')->restrictOnDelete();
            $table->timestamp('diaktifkan_pada')->nullable();
            $table->timestamps();

            $table->index(['status', 'mulai_pada', 'tutup_pada']);
        });

        Schema::create('jadwal_kelas', function (Blueprint $table) {
            $table->foreignId('jadwal_id')->constrained('jadwal_ujian')->cascadeOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->restrictOnDelete();
            $table->primary(['jadwal_id', 'kelas_id']);
        });

        // Bank soal sumber dan jumlah yang diambil (kosong = semua soal siap).
        Schema::create('jadwal_bank', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jadwal_id')->constrained('jadwal_ujian')->cascadeOnDelete();
            $table->foreignId('bank_soal_id')->constrained('bank_soal')->restrictOnDelete();
            $table->unsignedSmallInteger('jumlah_pg')->nullable();
            $table->unsignedSmallInteger('jumlah_esai')->nullable();

            $table->unique(['jadwal_id', 'bank_soal_id']);
        });

        // Soal yang dibekukan saat jadwal diaktifkan.
        Schema::create('jadwal_soal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jadwal_id')->constrained('jadwal_ujian')->cascadeOnDelete();
            $table->foreignId('soal_id')->constrained('soal')->restrictOnDelete();
            $table->unsignedInteger('urutan');
            $table->decimal('bobot', 5, 2)->default(1);

            $table->unique(['jadwal_id', 'soal_id']);
            $table->index(['jadwal_id', 'urutan']);
        });

        Schema::create('jadwal_pengawas', function (Blueprint $table) {
            $table->foreignId('jadwal_id')->constrained('jadwal_ujian')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ruang_id')->nullable()->constrained('ruang')->nullOnDelete();
            $table->primary(['jadwal_id', 'user_id']);
        });

        Schema::create('sesi_ujian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jadwal_id')->constrained('jadwal_ujian')->cascadeOnDelete();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $table->foreignId('ruang_id')->nullable()->constrained('ruang')->nullOnDelete();
            $table->string('status', 10)->default('belum'); // belum | berlangsung | selesai | diblokir
            $table->dateTime('mulai_pada')->nullable();
            $table->dateTime('batas_selesai')->nullable();
            $table->dateTime('selesai_pada')->nullable();
            $table->string('alasan_selesai', 12)->nullable(); // kirim | waktu_habis | dipaksa
            $table->unsignedTinyInteger('jumlah_pelanggaran')->default(0);
            $table->string('token_perangkat', 64)->nullable(); // hash token perangkat aktif
            $table->string('platform', 20)->nullable();
            $table->string('info_peramban', 300)->nullable();
            $table->string('ip', 45)->nullable();
            $table->dateTime('terakhir_aktif')->nullable();
            $table->json('susunan')->nullable(); // urutan soal dan opsi per siswa
            $table->unsignedSmallInteger('tambahan_menit')->default(0);
            $table->timestamps();

            $table->unique(['jadwal_id', 'siswa_id']);
            $table->index(['jadwal_id', 'status']);
            $table->index(['status', 'batas_selesai']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesi_ujian');
        Schema::dropIfExists('jadwal_pengawas');
        Schema::dropIfExists('jadwal_soal');
        Schema::dropIfExists('jadwal_bank');
        Schema::dropIfExists('jadwal_kelas');
        Schema::dropIfExists('jadwal_ujian');
    }
};
