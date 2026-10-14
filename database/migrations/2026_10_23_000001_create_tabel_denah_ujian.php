<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Denah berdiri sendiri (tidak melekat pada satu jadwal): satu ruang berisi siswa dari beberapa tingkat,
        // dan satu meja bisa diisi 2-3 siswa dari tingkat berbeda. Jadwal mana pun bisa dipasang ke sebuah denah.
        Schema::create('denah_ujian', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100);
            $table->foreignId('periode_id')->constrained('periode')->restrictOnDelete();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('denah_ruang', function (Blueprint $table) {
            $table->id();
            $table->foreignId('denah_id')->constrained('denah_ujian')->cascadeOnDelete();
            $table->foreignId('ruang_id')->constrained('ruang')->restrictOnDelete();
            $table->unsignedSmallInteger('jumlah_meja');
            $table->unsignedTinyInteger('siswa_per_meja')->default(2);
            $table->timestamps();

            $table->unique(['denah_id', 'ruang_id']);
        });

        Schema::create('denah_penempatan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('denah_id')->constrained('denah_ujian')->cascadeOnDelete();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $table->foreignId('ruang_id')->constrained('ruang')->restrictOnDelete();
            $table->unsignedSmallInteger('meja');
            $table->unsignedTinyInteger('posisi')->default(1);

            $table->unique(['denah_id', 'siswa_id']);
            $table->unique(['denah_id', 'ruang_id', 'meja', 'posisi']);
        });

        Schema::table('jadwal_ujian', function (Blueprint $table) {
            $table->foreignId('denah_id')->nullable()->constrained('denah_ujian')->nullOnDelete();
        });

        Schema::table('sesi_ujian', function (Blueprint $table) {
            $table->unsignedSmallInteger('no_meja')->nullable()->after('ruang_id');
        });
    }

    public function down(): void
    {
        Schema::table('sesi_ujian', fn (Blueprint $table) => $table->dropColumn('no_meja'));
        Schema::table('jadwal_ujian', fn (Blueprint $table) => $table->dropConstrainedForeignId('denah_id'));
        Schema::dropIfExists('denah_penempatan');
        Schema::dropIfExists('denah_ruang');
        Schema::dropIfExists('denah_ujian');
    }
};
