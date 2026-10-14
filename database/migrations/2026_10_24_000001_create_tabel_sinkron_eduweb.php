<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dua data EduWeb yang digabung ke satu data lokal (mis. baris tahun ajaran semester 2) disimpan di sini,
        // karena kolom eduweb_id pada tabel utama hanya muat satu id.
        Schema::create('eduweb_alias', function (Blueprint $table) {
            $table->id();
            $table->string('entitas', 20); // tahun_ajaran | kelas
            $table->unsignedBigInteger('eduweb_id');
            $table->unsignedBigInteger('lokal_id');

            $table->unique(['entitas', 'eduweb_id']);
        });

        Schema::create('sinkron_log', function (Blueprint $table) {
            $table->id();
            $table->string('jenis', 10); // penuh | kejadian
            $table->string('status', 10); // berhasil | gagal
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('ringkasan')->nullable();
            $table->string('galat', 300)->nullable();
            $table->unsignedInteger('durasi_ms')->default(0);
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::table('pengaturan_sekolah', function (Blueprint $table) {
            $table->unsignedBigInteger('eduweb_kejadian_terakhir')->default(0); // last_id kejadian yang sudah diterapkan
            $table->dateTime('eduweb_sinkron_penuh_pada')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pengaturan_sekolah', fn (Blueprint $table) => $table->dropColumn(['eduweb_kejadian_terakhir', 'eduweb_sinkron_penuh_pada']));
        Schema::dropIfExists('sinkron_log');
        Schema::dropIfExists('eduweb_alias');
    }
};
