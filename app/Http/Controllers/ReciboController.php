<?php

namespace App\Http\Controllers;

use App\Enums\TipoRecibo;
use App\Models\Prestamo;
use App\Models\Recibo;
use App\Services\Lending\ReceiptRegistrar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReciboController extends Controller
{
    public function __construct(protected ReceiptRegistrar $registrar) {}

    /**
     * Every receipt of every sub préstamo, with search and filters.
     */
    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));
        $type = TipoRecibo::tryFrom((string) $request->query('tipo', ''));
        $status = (string) $request->query('estado', Recibo::STATUS_ACTIVE);
        $status = in_array($status, [Recibo::STATUS_ACTIVE, Recibo::STATUS_VOIDED, 'TODOS'], true) ? $status : Recibo::STATUS_ACTIVE;
        $from = $this->isoDate($request->query('desde'));
        $to = $this->isoDate($request->query('hasta'));

        $query = Recibo::query()
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($term): void {
                $query->where('usuario', 'like', "%{$term}%")
                    ->orWhere('observaciones', 'like', "%{$term}%")
                    ->orWhereHas('prestamo', fn (Builder $loan) => $loan->where('numero_prestamo', 'like', "%{$term}%")
                        ->orWhereHas('linea', fn (Builder $line) => $line->where('nombre', 'like', "%{$term}%")
                            ->orWhereHas('banco', fn (Builder $bank) => $bank->where('nombre_banco', 'like', "%{$term}%"))));
            }))
            ->when($type, fn (Builder $query) => $query->where('tipo', $type->value))
            ->when($status !== 'TODOS', fn (Builder $query) => $query->where('estado', $status))
            ->when($from, fn (Builder $query) => $query->whereDate('fecha_pago', '>=', $from))
            ->when($to, fn (Builder $query) => $query->whereDate('fecha_pago', '<=', $to));

        $totals = (clone $query)->toBase()->where('estado', Recibo::STATUS_ACTIVE)
            ->selectRaw('COUNT(*) as receipts, COALESCE(SUM(monto_principal), 0) as principal, COALESCE(SUM(monto_interes), 0) as interest, COALESCE(SUM(monto_otros), 0) as other')
            ->first();

        return view('recibos.index', [
            'receipts' => $query->with('prestamo.linea.banco')->orderByDesc('fecha_pago')->orderByDesc('id')->paginate(15)->withQueryString(),
            'totals' => $totals,
            'term' => $term,
            'type' => $type?->value,
            'status' => $status,
            'from' => $from,
            'to' => $to,
            'types' => TipoRecibo::cases(),
        ]);
    }

    public function store(Request $request, Prestamo $prestamo): RedirectResponse
    {
        $data = $request->validate([
            'tipo' => ['required', Rule::enum(TipoRecibo::class)],
            'fecha_pago' => ['required', 'date'],
            'numero_cuota' => ['nullable', 'integer', 'min:1'],
            'monto_principal' => ['nullable', 'numeric', 'min:0'],
            'monto_interes' => ['nullable', 'numeric', 'min:0'],
            'monto_otros' => ['nullable', 'numeric', 'min:0'],
            'tipo_pago_otros_id' => ['nullable', 'exists:tipos_pago,id'],
            'descripcion_otros' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:255'],
        ]);

        $receipt = $this->registrar->register($prestamo, $data, $request->user()->email);

        return redirect()
            ->route('prestamos.show', $prestamo)
            ->with('status', 'Recibo registrado: '.$receipt->tipo->label().'.');
    }

    public function void(Request $request, Recibo $recibo): RedirectResponse
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'max:255']]);

        $this->registrar->void($recibo, $data['motivo'], $request->user()->email);

        return $this->backTo($request, $recibo->prestamo_id)->with('status', 'Recibo anulado. Se restauró el saldo del sub préstamo, su cuota y el disponible de la línea.');
    }

    /**
     * Back to the voiding screen when the request came from it, otherwise to the loan.
     */
    protected function backTo(Request $request, int $loanId): RedirectResponse
    {
        return $request->input('volver') === 'anulaciones'
            ? redirect()->route('anulaciones.index')
            : redirect()->route('prestamos.show', $loanId);
    }

    /**
     * A yyyy-mm-dd date from the query string, or null when it is empty or not a real date.
     */
    protected function isoDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }
}
