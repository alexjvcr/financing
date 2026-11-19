<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lineas_prestamos', function (Blueprint $table) {
            $table->foreignId('banco_id')->nullable()->constrained('lineas_bancos');
        });
    }

    public function down(): void
    {
        Schema::table('lineas_prestamos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('banco_id');
        });
    }
};
