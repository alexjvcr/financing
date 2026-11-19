<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_pago', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recibo_id')->constrained('recibos');
            $table->foreignId('prestamo_id')->constrained('prestamos');
            $table->foreignId('tipo_pago_id')->constrained('tipos_pago');
            $table->unsignedInteger('numero_cuota')->nullable();
            $table->date('fecha_cuota')->nullable();
            $table->date('fecha_pago');
            $table->decimal('monto', 18, 2);
            $table->string('descripcion', 255)->nullable();
            $table->timestamps();

            $table->index(['prestamo_id', 'fecha_pago']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_pago');
    }
};
