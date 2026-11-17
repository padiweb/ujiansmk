<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Penanda nilai esai yang diberikan otomatis (hilang bila guru mengubah nilainya sendiri).
        Schema::table('jawaban', function (Blueprint $table) {
            $table->boolean('dinilai_otomatis')->default(false)->after('saran_catatan');
        });

        // Bahan kisi-kisi per soal: materi dan indikator (rumusan capaian, bukan salinan pertanyaan).
        Schema::table('soal', function (Blueprint $table) {
            $table->string('materi', 150)->nullable()->after('level');
            $table->string('indikator', 400)->nullable()->after('materi');
        });
    }

    public function down(): void
    {
        Schema::table('jawaban', fn (Blueprint $table) => $table->dropColumn('dinilai_otomatis'));
        Schema::table('soal', fn (Blueprint $table) => $table->dropColumn(['materi', 'indikator']));
    }
};
