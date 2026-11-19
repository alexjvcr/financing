<?php

namespace App\Providers;

use App\Support\DateFormat;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // @fecha($date) prints a date in the format set by DATE_FORMAT; @fechahora($date) adds the time.
        Blade::directive('fecha', fn (string $expression): string => '<?php echo e('.DateFormat::class.'::format('.$expression.')); ?>');
        Blade::directive('fechahora', fn (string $expression): string => '<?php echo e('.DateFormat::class.'::formatDateTime('.$expression.')); ?>');
    }
}
