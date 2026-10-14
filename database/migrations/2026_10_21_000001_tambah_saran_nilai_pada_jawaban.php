<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saran nilai "Otomatis" disimpan terpisah dari nilai resmi; hanya guru yang menjadikannya nilai.
        Schema::table('jawaban', function (Blueprint $table) {
            $table->decimal('saran_nilai', 5, 2)->nullable()->after('nilai_esai');
            $table->string('saran_catatan', 300)->nullable()->after('saran_nilai');
        });
    }

    public function down(): void
    {
        Schema::table('jawaban', fn (Blueprint $table) => $table->dropColumn(['saran_nilai', 'saran_catatan']));
    }
};
