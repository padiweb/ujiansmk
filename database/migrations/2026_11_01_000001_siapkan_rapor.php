<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bobot nilai rapor diatur admin per sekolah. Komponen berbobot 0 tidak dipakai.
        //  Pengetahuan (NP): ujian + tugas (EduWeb) + harian + tugas akhir = 100
        //  Keterampilan (NK): praktik + adab = 100 (atau semuanya 0 bila tidak dipakai)
        //  Nilai akhir (NA): bobot NP + bobot NK = 100
        Schema::table('pengaturan_sekolah', function (Blueprint $table) {
            $table->unsignedTinyInteger('bobot_tugas')->default(0)->after('bobot_ujian');
            $table->unsignedTinyInteger('bobot_np')->default(50);
            $table->unsignedTinyInteger('bobot_nk')->default(50);
        });

        // Rentang tanggal semester: dasar penarikan absensi dan nilai tugas dari EduWeb.
        Schema::table('periode', function (Blueprint $table) {
            $table->date('mulai')->nullable();
            $table->date('selesai')->nullable();
        });

        Schema::create('nilai_rapor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $table->foreignId('mapel_id')->constrained('mapel')->restrictOnDelete();
            $table->foreignId('periode_id')->constrained('periode')->restrictOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->restrictOnDelete();
            $table->foreignId('guru_id')->nullable()->constrained('users')->nullOnDelete();

            // Komponen (0-100). *_sumber: otomatis | manual (diubah guru) | null (diisi guru).
            $table->decimal('ujian', 5, 2)->nullable();
            $table->string('ujian_sumber', 10)->nullable();
            $table->decimal('tugas', 5, 2)->nullable();
            $table->string('tugas_sumber', 10)->nullable();
            $table->decimal('harian', 5, 2)->nullable();
            $table->decimal('tugas_akhir', 5, 2)->nullable();
            $table->decimal('praktik', 5, 2)->nullable();
            $table->decimal('adab', 5, 2)->nullable();
            $table->text('catatan_guru')->nullable();

            // Hasil hitung (dihitung ulang di server setiap simpan).
            $table->unsignedTinyInteger('np')->nullable();
            $table->unsignedTinyInteger('nk')->nullable();
            $table->unsignedTinyInteger('na')->nullable();
            $table->string('predikat', 10)->nullable();

            $table->string('status', 8)->default('draf'); // draf | final
            $table->json('snapshot')->nullable(); // diisi saat final: nama mapel/kelas, KKM, bobot, skala
            $table->timestamps();

            $table->unique(['siswa_id', 'mapel_id', 'periode_id']);
            $table->index(['kelas_id', 'periode_id']);
        });

        Schema::create('rapor_siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $table->foreignId('periode_id')->constrained('periode')->restrictOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->restrictOnDelete();
            $table->string('kelas_nama', 50)->nullable(); // snapshot
            $table->unsignedSmallInteger('sakit')->default(0);
            $table->unsignedSmallInteger('izin')->default(0);
            $table->unsignedSmallInteger('alpa')->default(0);
            $table->string('sumber_absensi', 10)->nullable(); // eduweb | manual
            $table->text('catatan_wali')->nullable();
            $table->string('keputusan', 10)->nullable(); // naik | tinggal | lulus
            $table->string('status', 8)->default('draf'); // draf | final
            $table->foreignId('difinalkan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('difinalkan_pada')->nullable();
            $table->timestamps();

            $table->unique(['siswa_id', 'periode_id']);
        });

        // Skala predikat awal (diubah admin di Pengaturan Rapor) bila belum terisi.
        if (! DB::table('skala_predikat')->exists()) {
            $now = now();
            foreach ([[95, 'A+'], [90, 'A'], [85, 'A-'], [80, 'B+'], [75, 'B-'], [70, 'C'], [0, 'D']] as [$min, $p]) {
                DB::table('skala_predikat')->insert(['nilai_min' => $min, 'predikat' => $p, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rapor_siswa');
        Schema::dropIfExists('nilai_rapor');
        Schema::table('periode', fn (Blueprint $table) => $table->dropColumn(['mulai', 'selesai']));
        Schema::table('pengaturan_sekolah', fn (Blueprint $table) => $table->dropColumn(['bobot_tugas', 'bobot_np', 'bobot_nk']));
    }
};
