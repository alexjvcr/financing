<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Describe las tablas centrales de préstamos que ya existen en la base de datos
 * de producción. Cada tabla se protege con hasTable(), así que ejecutar esta
 * migración contra esa base no cambia nada, mientras que un entorno nuevo (o la
 * suite de pruebas) obtiene el esquema completo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('empresas')) {
            Schema::create('empresas', function (Blueprint $table) {
                $table->id();
                $table->string('nombre_empresa', 255);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('lineas_tipo_tasas')) {
            Schema::create('lineas_tipo_tasas', function (Blueprint $table) {
                $table->id();
                $table->string('nombre_tipo_tasa', 255);
                $table->timestamps();
                $table->decimal('base', 8, 5)->nullable();
            });
        }

        if (! Schema::hasTable('lineas_prestamos')) {
            Schema::create('lineas_prestamos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('empresa_id')->constrained('empresas');
                $table->string('nombre', 255);
                $table->string('tipo_linea', 50)->nullable();
                $table->decimal('monto_maximo', 15, 2)->nullable();
                $table->integer('plazo_maximo_meses')->nullable();
                $table->decimal('tasa_interes_fija', 8, 4)->nullable();
                $table->decimal('tasa_interes_variable', 8, 4)->nullable();
                $table->string('modalidad_pago', 20)->nullable();
                $table->string('estado', 50)->nullable()->default('ACTIVO');
                $table->date('fecha_vencmiento');
                $table->decimal('capital_trabajo', 15, 2)->default(0);
                $table->string('moneda', 50);
                $table->string('forma_pago', 50);
                $table->string('calendario', 10);
                $table->string('tipo_cuota', 10);
                $table->integer('periodicidad_pago');
                $table->string('tipo_interes', 15);
                $table->string('modalidad_cuota', 20);
                $table->decimal('variacion_porcentual', 8, 4);
                $table->foreignId('tipotasa_id')->constrained('lineas_tipo_tasas');
                $table->decimal('tasa_interes', 8, 4);
                $table->decimal('tasa_spread', 8, 4);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('prestamos')) {
            Schema::create('prestamos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('id_lineas_prestamos')->constrained('lineas_prestamos');
                $table->string('numero_prestamo', 100);
                $table->decimal('monto_prestamo', 15, 2);
                $table->date('formalizacion');
                $table->integer('plazo_meses');
                $table->decimal('tasa_interes', 8, 4);
                $table->decimal('tasa_spread', 8, 4);
                $table->decimal('cuota_calculada', 15, 2);
                $table->date('vencimiento');
                $table->date('proximo_pago');
                $table->decimal('saldo_prestamo', 15, 2);
                $table->string('estado', 255)->default('ACTIVO');
                $table->string('observacion', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Estas tablas son anteriores a las migraciones, por lo que aquí nunca se eliminan.
     */
    public function down(): void
    {
        //
    }
};
