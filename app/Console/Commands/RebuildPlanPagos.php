<?php

namespace App\Console\Commands;

use App\Models\Prestamo;
use App\Services\Lending\LegacyPlanSync;
use App\Services\Lending\LoanScheduleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

#[Signature('lending:rebuild-planpagos {--dry-run : Show what would change without writing} {--loan= : Only this loan number}')]
#[Description('Write the calculated payment plan of the active loans into the legacy planpagos table')]
class RebuildPlanPagos extends Command
{
    public function handle(LoanScheduleService $schedules, LegacyPlanSync $legacyPlan): int
    {
        if (! Schema::hasTable('planpagos')) {
            $this->error('La tabla planpagos no existe.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $loans = Prestamo::query()
            ->active()
            ->when($this->option('loan'), fn ($query, $number) => $query->where('numero_prestamo', $number))
            ->orderBy('numero_prestamo')
            ->get();

        if ($loans->isEmpty()) {
            $this->warn('No hay sub préstamos activos que procesar.');

            return self::SUCCESS;
        }

        $totals = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];

        if (! $dryRun) {
            $this->backupExistingRows($loans->modelKeys());
        }

        foreach ($loans as $loan) {
            $schedule = $schedules->scheduleFor($loan);
            $result = $dryRun
                ? $legacyPlan->reflect($loan, $schedule, dryRun: true)
                : $this->reflectAndRefresh($schedules, $loan);

            $this->line(sprintf(
                '%s sub préstamo %s: %d cuotas calculadas · %d nuevas · %d actualizadas · %d eliminadas',
                $dryRun ? '[simulación]' : '[reconstruye]',
                $loan->numero_prestamo,
                $schedule->rows->filter->isInstallment()->count(),
                $result['inserted'], $result['updated'], $result['deleted'],
            ));

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }
        }

        $this->info(sprintf(
            '%s: %d nuevas, %d actualizadas y %d eliminadas en planpagos.',
            $dryRun ? 'Se harían' : 'Hecho',
            $totals['inserted'], $totals['updated'], $totals['deleted'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{inserted: int, updated: int, deleted: int}
     */
    protected function reflectAndRefresh(LoanScheduleService $schedules, Prestamo $loan): array
    {
        $before = DB::table('planpagos')->where('prestamo_id', $loan->id)->count();
        $schedules->refreshSnapshot($loan);
        $after = DB::table('planpagos')->where('prestamo_id', $loan->id)->count();

        // refreshSnapshot refleja el plan en sí; los conteos se derivan de los totales de filas.
        return [
            'inserted' => max($after - $before, 0),
            'updated' => min($before, $after),
            'deleted' => max($before - $after, 0),
        ];
    }

    /**
     * Guarda en un archivo JSON las filas actuales de planpagos de los préstamos antes de reescribir nada.
     *
     * @param  array<int, int|string>  $loanIds
     */
    protected function backupExistingRows(array $loanIds): void
    {
        $rows = DB::table('planpagos')->whereIn('prestamo_id', $loanIds)->orderBy('prestamo_id')->orderBy('numero_cuota')->get();

        if ($rows->isEmpty()) {
            return;
        }

        $path = storage_path('app/backups/planpagos-'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $rows->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("Respaldo de {$rows->count()} filas de planpagos en {$path}");
    }
}
