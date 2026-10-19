<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Seperti aplikasi lama: ruang bersifat tetap, punya kapasitas, dan siswa ditugaskan langsung ke ruang.
        Schema::table('ruang', function (Blueprint $table) {
            $table->unsignedSmallInteger('kapasitas')->default(36)->after('nama');
        });

        // Satu denah utama menampung penempatan tetap yang dipakai semua jadwal.
        Schema::table('denah_ujian', function (Blueprint $table) {
            $table->boolean('utama')->default(false)->after('kegiatan_id');
        });

        // Kapasitas ruang mengikuti kapasitas yang pernah diatur pada denah.
        foreach (DB::table('denah_ruang')->select('ruang_id', DB::raw('max(kapasitas) as k'))->groupBy('ruang_id')->get() as $r) {
            if ($r->k) {
                DB::table('ruang')->where('id', $r->ruang_id)->update(['kapasitas' => $r->k]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('denah_ujian', fn (Blueprint $table) => $table->dropColumn('utama'));
        Schema::table('ruang', fn (Blueprint $table) => $table->dropColumn('kapasitas'));
    }
};
