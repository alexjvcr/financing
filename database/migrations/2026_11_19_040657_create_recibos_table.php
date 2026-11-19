<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recibos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prestamo_id')->constrained('prestamos');
            $table->string('tipo', 30);
            $table->unsignedInteger('numero_cuota')->nullable();
            $table->date('fecha_pago');
            $table->decimal('monto_principal', 18, 2)->default(0);
            $table->decimal('monto_interes', 18, 2)->default(0);
            $table->string('observaciones', 255)->nullable();
            $table->string('usuario', 255);
            $table->string('estado', 15)->default('ACTIVO');
            $table->dateTime('anulado_at')->nullable();
            $table->string('motivo_anulacion', 255)->nullable();
            $table->timestamps();

            $table->index(['prestamo_id', 'estado', 'fecha_pago']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recibos');
    }
};
