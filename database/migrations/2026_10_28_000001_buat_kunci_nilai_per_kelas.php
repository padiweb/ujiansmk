<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kunci nilai per jadwal DAN kelas: guru tiap kelas mengunci kelasnya sendiri. Ada baris = terkunci.
        Schema::create('kunci_nilai', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jadwal_id')->constrained('jadwal_ujian')->cascadeOnDelete();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->dateTime('dikunci_pada');
            $table->foreignId('dikunci_oleh')->nullable()->constrained('users')->nullOnDelete();

            $table->unique(['jadwal_id', 'kelas_id']);
        });

        // Jadwal yang sudah terkunci: kunci semua kelasnya.
        $terkunci = DB::table('jadwal_ujian')->whereNotNull('nilai_dikunci_pada')->get(['id', 'nilai_dikunci_pada', 'nilai_dikunci_oleh']);
        foreach ($terkunci as $j) {
            foreach (DB::table('jadwal_kelas')->where('jadwal_id', $j->id)->pluck('kelas_id') as $kelasId) {
                DB::table('kunci_nilai')->insert([
                    'jadwal_id' => $j->id, 'kelas_id' => $kelasId, 'dikunci_pada' => $j->nilai_dikunci_pada, 'dikunci_oleh' => $j->nilai_dikunci_oleh,
                ]);
            }
        }

        Schema::table('jadwal_ujian', function (Blueprint $table) {
            $table->dropConstrainedForeignId('nilai_dikunci_oleh');
            $table->dropColumn('nilai_dikunci_pada');
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_ujian', function (Blueprint $table) {
            $table->dateTime('nilai_dikunci_pada')->nullable();
            $table->foreignId('nilai_dikunci_oleh')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::dropIfExists('kunci_nilai');
    }
};
