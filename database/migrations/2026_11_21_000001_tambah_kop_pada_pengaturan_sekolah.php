<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Baris tambahan kop surat: nama lembaga induk (mis. pondok pesantren), telepon, website, dan email.
        Schema::table('pengaturan_sekolah', function (Blueprint $table) {
            $table->string('nama_induk', 150)->nullable()->after('nama');
            $table->string('telepon', 40)->nullable()->after('provinsi');
            $table->string('website', 120)->nullable()->after('telepon');
            $table->string('email', 120)->nullable()->after('website');
        });
    }

    public function down(): void
    {
        Schema::table('pengaturan_sekolah', function (Blueprint $table) {
            $table->dropColumn(['nama_induk', 'telepon', 'website', 'email']);
        });
    }
};
