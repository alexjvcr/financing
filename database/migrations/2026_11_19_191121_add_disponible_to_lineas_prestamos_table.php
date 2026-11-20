<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lineas_prestamos', function (Blueprint $table) {
            $table->decimal('disponible', 15, 2)->nullable();
        });

        // Punto de partida: límite de crédito menos el saldo de los préstamos activos de la línea.
        $used = DB::table('prestamos')
            ->where('estado', 'ACTIVO')
            ->groupBy('id_lineas_prestamos')
            ->selectRaw('id_lineas_prestamos, SUM(saldo_prestamo) as used')
            ->pluck('used', 'id_lineas_prestamos');

        foreach (DB::table('lineas_prestamos')->get(['id', 'monto_maximo']) as $line) {
            DB::table('lineas_prestamos')
                ->where('id', $line->id)
                ->update(['disponible' => round((float) $line->monto_maximo - (float) ($used[$line->id] ?? 0), 2)]);
        }
    }

    public function down(): void
    {
        Schema::table('lineas_prestamos', function (Blueprint $table) {
            $table->dropColumn('disponible');
        });
    }
};
