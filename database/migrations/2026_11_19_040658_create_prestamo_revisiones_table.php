<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestamo_revisiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prestamo_id')->constrained('prestamos');
            $table->date('fecha_efectiva');
            $table->decimal('tasa_interes', 8, 4)->nullable();
            $table->decimal('tasa_spread', 8, 4)->nullable();
            $table->unsignedInteger('plazo_restante_meses')->nullable();
            $table->string('observacion', 255)->nullable();
            $table->string('usuario', 255);
            $table->dateTime('anulado_at')->nullable();
            $table->timestamps();

            $table->index(['prestamo_id', 'fecha_efectiva']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestamo_revisiones');
    }
};
