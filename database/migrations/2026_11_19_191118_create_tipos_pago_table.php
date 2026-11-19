<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_pago', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 5)->unique();
            $table->string('nombre', 100);
            $table->string('descripcion', 255)->nullable();
            $table->boolean('afecta_saldo')->default(false);
            $table->boolean('sistema')->default(false);
            $table->timestamps();
        });

        $now = now();

        DB::table('tipos_pago')->insert([
            ['codigo' => 'PP', 'nombre' => 'Pago principal', 'descripcion' => 'Abono al principal de la cuota o del préstamo.', 'afecta_saldo' => true, 'sistema' => true],
            ['codigo' => 'PI', 'nombre' => 'Pago interés', 'descripcion' => 'Pago de los intereses de la cuota.', 'afecta_saldo' => false, 'sistema' => true],
            ['codigo' => 'CT', 'nombre' => 'Cancelación total', 'descripcion' => 'Pago del saldo completo que liquida el préstamo.', 'afecta_saldo' => true, 'sistema' => true],
            ['codigo' => 'PA', 'nombre' => 'Pago anticipado', 'descripcion' => 'Adelanto de cuotas aplicado al principal.', 'afecta_saldo' => true, 'sistema' => true],
            ['codigo' => 'PC', 'nombre' => 'Pago parcial', 'descripcion' => 'Pago parcial de una cuota (solo interés).', 'afecta_saldo' => false, 'sistema' => true],
            ['codigo' => 'OT', 'nombre' => 'Otros rubros', 'descripcion' => 'Comisiones, seguros u otros cargos que no afectan el saldo.', 'afecta_saldo' => false, 'sistema' => true],
        ]);

        DB::table('tipos_pago')->update(['created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_pago');
    }
};
