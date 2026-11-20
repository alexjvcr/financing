<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lineas_prestamos', function (Blueprint $table) {
            $table->unsignedInteger('meses_gracia')->default(0);
            $table->decimal('porcentaje_global', 8, 4)->default(0);
        });

        // Sobrescrituras opcionales de las condiciones de la línea; null significa "heredar de la línea".
        Schema::table('prestamos', function (Blueprint $table) {
            $table->string('modalidad_pago', 20)->nullable();
            $table->string('modalidad_cuota', 20)->nullable();
            $table->string('calendario', 10)->nullable();
            $table->string('tipo_cuota', 10)->nullable();
            $table->string('forma_pago', 50)->nullable();
            $table->unsignedInteger('meses_gracia')->nullable();
            $table->decimal('porcentaje_global', 8, 4)->nullable();
            $table->decimal('variacion_porcentual', 8, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lineas_prestamos', function (Blueprint $table) {
            $table->dropColumn(['meses_gracia', 'porcentaje_global']);
        });

        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropColumn([
                'modalidad_pago', 'modalidad_cuota', 'calendario', 'tipo_cuota', 'forma_pago',
                'meses_gracia', 'porcentaje_global', 'variacion_porcentual',
            ]);
        });
    }
};
