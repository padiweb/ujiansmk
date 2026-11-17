<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Foto profil akun staf (disimpan di disk privat; tidak disinkron dari EduWeb sehingga tidak tertimpa).
        Schema::table('users', function (Blueprint $table) {
            $table->string('foto', 100)->nullable()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('foto'));
    }
};
