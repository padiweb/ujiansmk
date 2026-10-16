<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "berlangsung" (11 huruf) tidak muat di kolom varchar(10) pada MySQL/MariaDB sehingga ujian tidak bisa dimulai.
        Schema::table('sesi_ujian', function (Blueprint $table) {
            $table->string('status', 16)->default('belum')->change();
        });
    }

    public function down(): void
    {
        Schema::table('sesi_ujian', function (Blueprint $table) {
            $table->string('status', 10)->default('belum')->change();
        });
    }
};
