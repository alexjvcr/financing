<?php

namespace App\Http\Controllers;

use App\Models\Prestamo;
use App\Models\Recibo;
use App\Models\RevisionPrestamo;
use App\Services\Lending\ReceiptRegistrar;
use App\Services\Lending\RevisionRegistrar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Voiding screen: what can be voided right now and the audit history of what was voided.
 */
class AnulacionController extends Controller
{
    public function __construct(
        protected ReceiptRegistrar $receipts,
        protected RevisionRegistrar $revisions,
    ) {}

    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));

        $loans = Prestamo::query()
            ->with('linea.banco')
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($term): void {
                $query->where('numero_prestamo', 'like', "%{$term}%")
                    ->orWhereHas('linea', fn (Builder $line) => $line->where('nombre', 'like', "%{$term}%")
                        ->orWhereHas('banco', fn (Builder $bank) => $bank->where('nombre_banco', 'like', "%{$term}%")));
            }))
            ->orderBy('numero_prestamo')
            ->get();

        // Only the most recent receipt and revision of each loan can be voided now.
        $voidableReceipts = $loans->map(fn (Prestamo $loan): ?Recibo => $this->receipts->latestActive($loan))->filter()->values();
        $voidableRevisions = $loans->map(fn (Prestamo $loan): ?RevisionPrestamo => $this->revisions->latestActive($loan))->filter()->values();

        $voidedReceipts = Recibo::query()
            ->with('prestamo.linea.banco')
            ->where('estado', Recibo::STATUS_VOIDED)
            ->whereIn('prestamo_id', $loans->modelKeys())
            ->orderByDesc('anulado_at')
            ->orderByDesc('id')
            ->paginate(15, pageName: 'recibos_page')
            ->withQueryString();

        $voidedRevisions = RevisionPrestamo::query()
            ->with('prestamo')
            ->whereNotNull('anulado_at')
            ->whereIn('prestamo_id', $loans->modelKeys())
            ->orderByDesc('anulado_at')
            ->orderByDesc('id')
            ->paginate(15, pageName: 'revisiones_page')
            ->withQueryString();

        return view('anulaciones.index', [
            'term' => $term,
            'voidableReceipts' => $voidableReceipts,
            'voidableRevisions' => $voidableRevisions,
            'voidedReceipts' => $voidedReceipts,
            'voidedRevisions' => $voidedRevisions,
        ]);
    }
}
