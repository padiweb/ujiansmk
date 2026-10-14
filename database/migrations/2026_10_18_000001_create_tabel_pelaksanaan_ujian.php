<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Folder gambar statis jadwal ini (nama acak); dibuat saat diaktifkan, dihapus saat selesai/dibatalkan.
        Schema::table('jadwal_ujian', function (Blueprint $table) {
            $table->string('media_kode', 40)->nullable()->after('token');
        });

        Schema::create('jawaban', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesi_id')->constrained('sesi_ujian')->cascadeOnDelete();
            $table->foreignId('soal_id')->constrained('soal')->restrictOnDelete();
            $table->string('opsi_label', 1)->nullable();   // jawaban PG: label ASLI opsi (bukan posisi tampil)
            $table->text('teks_esai')->nullable();
            $table->dateTime('dijawab_klien')->nullable();  // waktu menurut peramban (tidak dipercaya)
            $table->dateTime('diterima_pada');              // waktu menurut server
            $table->boolean('terlambat')->default(false);   // diterima setelah batas waktu (masa toleransi)
            $table->decimal('nilai_esai', 5, 2)->nullable();
            $table->foreignId('dikoreksi_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('dikoreksi_pada')->nullable();

            $table->unique(['sesi_id', 'soal_id']);
        });

        Schema::create('hasil_ujian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesi_id')->unique()->constrained('sesi_ujian')->cascadeOnDelete();
            $table->unsignedSmallInteger('benar')->default(0);
            $table->unsignedSmallInteger('salah')->default(0);
            $table->unsignedSmallInteger('kosong')->default(0);
            $table->decimal('skor_pg', 5, 2)->nullable();
            $table->decimal('skor_esai', 5, 2)->nullable();
            $table->decimal('skor_akhir', 5, 2)->nullable();
            $table->string('status_koreksi', 16)->default('tanpa_esai'); // tanpa_esai | belum_dikoreksi | selesai
            // Snapshot agar arsip tidak berubah bila master data berganti nama.
            $table->string('mapel_nama', 100);
            $table->string('kelas_nama', 30)->nullable();
            $table->string('jadwal_nama', 150);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hasil_ujian');
        Schema::dropIfExists('jawaban');
        Schema::table('jadwal_ujian', fn (Blueprint $t) => $t->dropColumn('media_kode'));
    }
};
