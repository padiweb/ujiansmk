<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rapor final baru terlihat siswa setelah admin menyalakan semester ini (mis. pada hari penyerahan rapor).
        Schema::table('periode', function (Blueprint $table) {
            $table->boolean('tampil_siswa')->default(false)->after('rapor_di_sini');
        });
    }

    public function down(): void
    {
        Schema::table('periode', fn (Blueprint $table) => $table->dropColumn('tampil_siswa'));
    }
};
