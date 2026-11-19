<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lineas_tipo_tasa_historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipotasa_id')->constrained('lineas_tipo_tasas');
            $table->decimal('base_anterior', 8, 5)->nullable();
            $table->decimal('base_nueva', 8, 5)->nullable();
            $table->string('usuario', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lineas_tipo_tasa_historial');
    }
};
