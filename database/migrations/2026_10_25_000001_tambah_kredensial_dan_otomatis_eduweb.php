<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('siswa', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('nisn'); // nama pengguna di EduWeb
            $table->string('eduweb_hash_sandi', 255)->nullable(); // hash EduWeb terakhir yang terlihat
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('eduweb_hash_sandi', 255)->nullable();
        });

        Schema::table('pengaturan_sekolah', function (Blueprint $table) {
            $table->dateTime('eduweb_cek_terakhir')->nullable(); // pengecekan kejadian otomatis terakhir
            $table->dateTime('eduweb_webhook_terakhir')->nullable(); // sinyal webhook terakhir yang sah
            $table->boolean('eduweb_kejadian_tertahan')->default(false); // ada kejadian menunggu ujian selesai
            $table->string('eduweb_kredensial', 12)->nullable(); // aktif | belum_aktif (status akses kredensial)
        });
    }

    public function down(): void
    {
        Schema::table('pengaturan_sekolah', fn (Blueprint $table) => $table->dropColumn(['eduweb_cek_terakhir', 'eduweb_webhook_terakhir', 'eduweb_kejadian_tertahan', 'eduweb_kredensial']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('eduweb_hash_sandi'));
        Schema::table('siswa', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'eduweb_hash_sandi']);
        });
    }
};
