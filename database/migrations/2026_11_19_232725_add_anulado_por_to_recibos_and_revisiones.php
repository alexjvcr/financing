<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recibos', function (Blueprint $table) {
            $table->string('anulado_por', 255)->nullable();
        });

        Schema::table('prestamo_revisiones', function (Blueprint $table) {
            $table->string('anulado_por', 255)->nullable();
            $table->string('motivo_anulacion', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recibos', function (Blueprint $table) {
            $table->dropColumn('anulado_por');
        });

        Schema::table('prestamo_revisiones', function (Blueprint $table) {
            $table->dropColumn(['anulado_por', 'motivo_anulacion']);
        });
    }
};
