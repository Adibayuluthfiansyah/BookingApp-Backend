<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            // Tambahkan kolom owner_id setelah id
            $table->foreignId('owner_id') // Kolom foreign key
                ->nullable() // Boleh null jika ada super admin atau venue tanpa pemilik
                ->after('id')
                ->constrained('users') // Merujuk ke tabel users
                ->onDelete('set null'); // Jika user dihapus, set owner_id jadi null
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            // Hapus foreign key constraint dulu
            $table->dropForeign(['owner_id']);
            // Hapus kolomnya
            $table->dropColumn('owner_id');
        });
    }
};
