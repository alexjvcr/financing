<?php

namespace App\Services\Lending;

use App\Models\Prestamo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Carga los recibos y revisiones de un préstamo y reconstruye su plan de pagos.
 */
class LoanScheduleService
{
    public function __construct(
        protected LoanScheduleBuilder $builder,
        protected LegacyPlanSync $legacyPlan,
    ) {}

    public function scheduleFor(Prestamo $loan, ?Carbon $today = null): LoanSchedule
    {
        $loan->loadMissing('linea');

        return $this->builder->build(
            $loan,
            $loan->linea,
            $loan->recibos()->active()->get(),
            $loan->revisiones()->active()->get(),
            $today,
        );
    }

    /**
     * Planes reconstruidos de todos los préstamos activos.
     *
     * @return Collection<int, array{loan: Prestamo, schedule: LoanSchedule}>
     */
    public function activeSchedules(?Carbon $today = null): Collection
    {
        return Prestamo::query()
            ->active()
            ->with([
                'linea.banco',
                'recibos' => fn ($query) => $query->active(),
                'revisiones' => fn ($query) => $query->active(),
            ])
            ->orderBy('numero_prestamo')
            ->get()
            ->map(fn (Prestamo $loan): array => [
                'loan' => $loan,
                'schedule' => $this->builder->build($loan, $loan->linea, $loan->recibos, $loan->revisiones, $today),
            ]);
    }

    /**
     * Cotiza lo necesario para cancelar el préstamo en una fecha dada.
     *
     * @return array{principal: float, interest: float}
     */
    public function payoffQuote(Prestamo $loan, Carbon $date): array
    {
        $loan->loadMissing('linea');

        return $this->builder->payoffQuote(
            $loan,
            $loan->linea,
            $loan->recibos()->active()->get(),
            $loan->revisiones()->active()->get(),
            $date,
        );
    }

    /**
     * Guarda en la fila del préstamo las cifras derivadas del plan reconstruido, actualiza
     * el crédito disponible de su línea y refleja las cuotas en planpagos.
     *
     * La cuota contractual (cuota_calculada) se deja intacta a propósito
     * porque el plan la usa como su cuota inicial.
     */
    public function refreshSnapshot(Prestamo $loan): LoanSchedule
    {
        $schedule = $this->scheduleFor($loan);

        // Se escribe mediante el query builder para que la fila se actualice incluso cuando este
        // modelo en memoria ya tiene los mismos valores que una copia desactualizada de la fila.
        $loan->newQuery()->whereKey($loan->getKey())->update([
            'saldo_prestamo' => $schedule->outstandingPrincipal(),
            'estado' => $schedule->isSettled() ? Prestamo::STATUS_SETTLED : Prestamo::STATUS_ACTIVE,
            'proximo_pago' => $schedule->nextOpenInstallment()?->date ?? $loan->proximo_pago,
            'vencimiento' => $schedule->maturityDate() ?? $loan->vencimiento,
            'updated_at' => now(),
        ]);
        $loan->refresh();

        $loan->linea->refreshAvailable();
        $this->legacyPlan->reflect($loan, $schedule);

        return $schedule;
    }
}
