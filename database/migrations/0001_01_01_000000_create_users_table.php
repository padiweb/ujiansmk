<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Akun staf: admin, guru, pengawas. Siswa ada di tabel `siswa`.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('eduweb_id')->nullable()->unique();
            $table->string('nama');
            $table->string('username', 50)->unique();
            $table->string('password');
            $table->string('role', 20)->index(); // admin | guru | pengawas
            $table->boolean('aktif')->default(true);
            $table->timestamp('login_terakhir')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
