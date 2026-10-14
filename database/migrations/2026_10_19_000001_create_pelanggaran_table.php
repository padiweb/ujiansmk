<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pelanggaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesi_id')->constrained('sesi_ujian')->cascadeOnDelete();
            $table->string('jenis', 30);
            $table->string('keterangan', 255)->nullable();
            $table->boolean('dihitung')->default(true); // false = hanya dicatat (informasi/duplikat), tidak menambah hitungan
            $table->json('info')->nullable();           // ukuran layar, durasi, dst. (disaring ketat)
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sesi_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pelanggaran');
    }
};
