<?php

namespace App\Providers;

use App\Support\DateFormat;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Registra los servicios de la aplicación.
     */
    public function register(): void
    {
        //
    }

    /**
     * Inicializa los servicios de la aplicación.
     */
    public function boot(): void
    {
        // @fecha($date) imprime una fecha en el formato definido por DATE_FORMAT; @fechahora($date) agrega la hora.
        Blade::directive('fecha', fn (string $expression): string => '<?php echo e('.DateFormat::class.'::format('.$expression.')); ?>');
        Blade::directive('fechahora', fn (string $expression): string => '<?php echo e('.DateFormat::class.'::formatDateTime('.$expression.')); ?>');
    }
}
