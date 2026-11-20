<?php

namespace App\Console\Commands;

use App\Enums\TipoRecibo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Services\Lending\LoanScheduleService;
use App\Services\Lending\ReceiptDetailWriter;
use App\Support\DateFormat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Signature('lending:import-paid-installments {--dry-run : Show what would be imported without writing}')]
#[Description('Import the installments marked as paid (C) in planpagos as full-installment receipts')]
class ImportPaidInstallments extends Command
{
    public function handle(LoanScheduleService $schedules, ReceiptDetailWriter $details): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $paid = DB::table('planpagos')
            ->where('estado', 'C')
            ->orderBy('prestamo_id')
            ->orderBy('numero_cuota')
            ->get();

        $imported = 0;
        $skipped = 0;
        $loanIds = [];
        $previousDates = [];

        foreach ($paid as $row) {
            $alreadyImported = Recibo::query()
                ->where('prestamo_id', $row->prestamo_id)
                ->where('numero_cuota', (int) $row->numero_cuota)
                ->active()
                ->exists();

            if ($alreadyImported) {
                $skipped++;

                continue;
            }

            $paymentDate = substr((string) ($row->fecha_pago_real ?: $row->fecha_pago), 0, 10);
            $note = 'Importado de planpagos';

            // Payments follow the installment order: never earlier than the previous receipt of the loan.
            $previousDate = $previousDates[$row->prestamo_id] ??= substr((string) Recibo::query()->where('prestamo_id', $row->prestamo_id)->active()->max('fecha_pago'), 0, 10) ?: null;
            if ($previousDate !== null && $paymentDate < $previousDate) {
                $note .= ' (fecha real de pago: '.DateFormat::format($paymentDate).')';
                $paymentDate = $previousDate;
            }
            $previousDates[$row->prestamo_id] = $paymentDate;

            $this->line(sprintf(
                '%s préstamo %d cuota %d  %s  principal %s  interés %s',
                $dryRun ? '[simulación]' : '[importa]',
                $row->prestamo_id, $row->numero_cuota, $paymentDate,
                number_format((float) $row->monto_principal, 2), number_format((float) $row->monto_interes, 2),
            ));

            if (! $dryRun) {
                $receipt = Recibo::create([
                    'prestamo_id' => $row->prestamo_id,
                    'tipo' => TipoRecibo::CuotaCompleta,
                    'numero_cuota' => (int) $row->numero_cuota,
                    'fecha_pago' => $paymentDate,
                    'monto_principal' => $row->monto_principal,
                    'monto_interes' => $row->monto_interes,
                    'observaciones' => $note,
                    'usuario' => 'IMPORTACION',
                    'estado' => Recibo::STATUS_ACTIVE,
                ]);
                $details->write($receipt, (int) $row->numero_cuota, Carbon::parse($row->fecha_pago)->startOfDay());
                $loanIds[$row->prestamo_id] = true;
            }

            $imported++;
        }

        foreach (array_keys($loanIds) as $loanId) {
            $schedules->refreshSnapshot(Prestamo::findOrFail($loanId));
        }

        $this->info(($dryRun ? 'Se importarían ' : 'Importados ')."{$imported} recibos; {$skipped} ya existían.");

        return self::SUCCESS;
    }
}
