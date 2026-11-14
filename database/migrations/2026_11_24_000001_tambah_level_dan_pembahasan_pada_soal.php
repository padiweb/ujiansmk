<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // level: L1 pengetahuan/pemahaman, L2 penerapan, L3 penalaran (HOTS). pembahasan: catatan guru, tidak tampil ke siswa.
        Schema::table('soal', function (Blueprint $table) {
            $table->string('level', 2)->nullable()->after('tipe');
            $table->text('pembahasan')->nullable()->after('rubrik_esai');
        });
    }

    public function down(): void
    {
        Schema::table('soal', function (Blueprint $table) {
            $table->dropColumn(['level', 'pembahasan']);
        });
    }
};
