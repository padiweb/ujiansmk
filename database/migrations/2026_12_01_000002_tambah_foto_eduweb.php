<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lokasi foto di EduWeb (jalur di dalam folder storage EduWeb), bukan alamat penuh: alamat dibentuk dari EDUWEB_URL.
        Schema::table('users', fn (Blueprint $table) => $table->string('foto_eduweb', 150)->nullable()->after('foto'));
        Schema::table('siswa', fn (Blueprint $table) => $table->string('foto_eduweb', 150)->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('foto_eduweb'));
        Schema::table('siswa', fn (Blueprint $table) => $table->dropColumn('foto_eduweb'));
    }
};
