<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guru (dari EduWeb) yang juga bertugas sebagai pengawas. Akun berperan "pengawas" selalu bisa mengawasi.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('bisa_mengawasi')->default(false)->after('aktif');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('bisa_mengawasi');
        });
    }
};
