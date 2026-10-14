<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tahun_ajaran', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('eduweb_id')->nullable()->unique();
            $table->string('nama', 20)->unique(); // 2025/2026
            $table->boolean('aktif')->default(false);
            $table->timestamps();
        });

        Schema::create('periode', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->restrictOnDelete();
            $table->string('semester', 10); // ganjil | genap
            $table->string('tipe', 15)->default('reguler'); // reguler | ujian_sekolah
            $table->boolean('aktif')->default(false);
            $table->timestamps();

            $table->unique(['tahun_ajaran_id', 'semester', 'tipe']);
        });

        Schema::create('jurusan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('eduweb_id')->nullable()->unique();
            $table->string('kode', 20)->unique();
            $table->string('nama', 100);
            $table->string('program_keahlian', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('eduweb_id')->nullable()->unique();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->restrictOnDelete();
            $table->string('tingkat', 3); // X | XI | XII
            $table->foreignId('jurusan_id')->nullable()->constrained('jurusan')->restrictOnDelete();
            $table->string('nama', 30);
            $table->foreignId('wali_kelas_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tahun_ajaran_id', 'nama']);
        });

        // Siswa di kelas tertentu pada tahun ajaran kelas itu. Menggantikan tbl_riwayat_kelas.
        Schema::create('keanggotaan_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->restrictOnDelete();
            $table->string('status', 10)->default('aktif'); // aktif | naik | tinggal | lulus
            $table->timestamps();

            $table->unique(['siswa_id', 'kelas_id']);
        });

        Schema::create('mapel', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('eduweb_id')->nullable()->unique();
            $table->string('kode', 20)->unique();
            $table->string('nama', 100);
            $table->string('nama_skl', 200)->nullable();
            $table->string('kelompok', 3)->default('A'); // A B C1 C2 C3 ML
            $table->unsignedTinyInteger('kkm')->default(75);
            $table->timestamps();
        });

        Schema::create('pengampu', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guru_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('mapel_id')->constrained('mapel')->restrictOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['guru_id', 'mapel_id', 'kelas_id']);
        });

        Schema::create('ruang', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 50)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ruang');
        Schema::dropIfExists('pengampu');
        Schema::dropIfExists('mapel');
        Schema::dropIfExists('keanggotaan_kelas');
        Schema::dropIfExists('kelas');
        Schema::dropIfExists('jurusan');
        Schema::dropIfExists('periode');
        Schema::dropIfExists('tahun_ajaran');
    }
};
