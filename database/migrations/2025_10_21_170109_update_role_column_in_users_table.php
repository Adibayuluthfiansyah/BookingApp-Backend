<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Ubah kolom role dari ENUM atau VARCHAR pendek menjadi VARCHAR(20)
            $table->string('role', 20)->default('customer')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Kembalikan ke ukuran semula jika rollback
            $table->string('role', 10)->default('customer')->change();
        });
    }
};
