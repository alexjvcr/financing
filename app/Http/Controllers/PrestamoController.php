<?php

namespace App\Http\Controllers;

use App\Enums\TipoRecibo;
use App\Models\LineaPrestamo;
use App\Models\Prestamo;
use App\Models\TipoPago;
use App\Services\Lending\LoanScheduleBuilder;
use App\Services\Lending\LoanScheduleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PrestamoController extends Controller
{
    public function __construct(
        protected LoanScheduleService $schedules,
        protected LoanScheduleBuilder $builder,
    ) {}

    public function index(Request $request): View
    {
        $status = $request->query('estado');
        $term = trim((string) $request->query('q', ''));

        $loans = Prestamo::query()
            ->with('linea.banco', 'linea.empresa')
            ->when(in_array($status, [Prestamo::STATUS_ACTIVE, Prestamo::STATUS_SETTLED], true), fn (Builder $query) => $query->where('estado', $status))
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($term): void {
                $query->where('numero_prestamo', 'like', "%{$term}%")
                    ->orWhere('observacion', 'like', "%{$term}%")
                    ->orWhereHas('linea', fn (Builder $line) => $line->where('nombre', 'like', "%{$term}%")
                        ->orWhereHas('banco', fn (Builder $bank) => $bank->where('nombre_banco', 'like', "%{$term}%")));
            }))
            ->orderBy('numero_prestamo')
            ->paginate(15)
            ->withQueryString();

        return view('prestamos.index', [
            'status' => $status,
            'term' => $term,
            'loans' => $loans,
            'blockers' => $loans->getCollection()->mapWithKeys(fn (Prestamo $loan): array => [$loan->id => $this->deletionBlockers($loan)])->all(),
        ]);
    }

    public function destroy(Prestamo $prestamo): RedirectResponse
    {
        $blockers = $this->deletionBlockers($prestamo);

        if ($blockers !== []) {
            return back()->withErrors(['delete' => "No se puede eliminar el sub préstamo {$prestamo->numero_prestamo}: ".implode(' ', $blockers)]);
        }

        $prestamo->delete();
        $prestamo->linea->refreshAvailable();

        return redirect()->route('prestamos.index')->with('status', "Sub Préstamo {$prestamo->numero_prestamo} eliminado correctamente.");
    }

    /**
     * Reasons why a loan cannot be deleted because other rows reference it.
     *
     * @return array<int, string>
     */
    protected function deletionBlockers(Prestamo $loan): array
    {
        $blockers = [];

        $receipts = $loan->recibos()->count();
        if ($receipts > 0) {
            $blockers[] = "tiene {$receipts} recibo(s) registrado(s).";
        }

        $revisions = $loan->revisiones()->count();
        if ($revisions > 0) {
            $blockers[] = "tiene {$revisions} revisión(es) de tasa o plazo.";
        }

        if (Schema::hasTable('planpagos')) {
            $legacy = DB::table('planpagos')->where('prestamo_id', $loan->id)->count();
            if ($legacy > 0) {
                $blockers[] = "tiene {$legacy} cuota(s) en la tabla histórica planpagos.";
            }
        }

        return $blockers;
    }

    public function create(): View
    {
        return $this->form(null);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(null));
        $line = LineaPrestamo::findOrFail($data['id_lineas_prestamos']);

        $this->ensureCreditAvailable($line, (float) $data['monto_prestamo'], null);

        $loan = new Prestamo($data + ['estado' => Prestamo::STATUS_ACTIVE]);
        $this->applyDerivedFigures($loan, $line);
        $loan->save();
        $line->refreshAvailable();

        return redirect()->route('prestamos.show', $loan)->with('status', 'Sub Préstamo creado. Ya puede registrar recibos y revisiones.');
    }

    public function show(Prestamo $prestamo): View
    {
        $prestamo->load('linea.banco', 'linea.empresa');
        $schedule = $this->schedules->scheduleFor($prestamo);

        return view('prestamos.show', [
            'loan' => $prestamo,
            'schedule' => $schedule,
            'next' => $schedule->nextOpenInstallment(),
            'receipts' => $prestamo->recibos()->get()->sortByDesc(fn ($receipt) => $receipt->fecha_pago->format('Ymd').str_pad((string) $receipt->id, 10, '0', STR_PAD_LEFT))->values(),
            'revisions' => $prestamo->revisiones()->get()->reverse()->values(),
            'receiptTypes' => TipoRecibo::cases(),
            'details' => $prestamo->detalles()->with(['tipoPago', 'recibo'])->get(),
            'otherTypes' => TipoPago::where('sistema', false)->orWhere('codigo', TipoPago::OTHER)->orderBy('codigo')->get(),
            'blockers' => $this->deletionBlockers($prestamo),
        ]);
    }

    public function edit(Prestamo $prestamo): View
    {
        return $this->form($prestamo);
    }

    public function update(Request $request, Prestamo $prestamo): RedirectResponse
    {
        $data = $request->validate($this->rules($prestamo));

        if ($this->isLocked($prestamo)) {
            $prestamo->update(['numero_prestamo' => $data['numero_prestamo'], 'observacion' => $data['observacion'] ?? null]);
        } else {
            $line = LineaPrestamo::findOrFail($data['id_lineas_prestamos']);
            $this->ensureCreditAvailable($line, (float) $data['monto_prestamo'], $prestamo);

            $prestamo->fill($data);
            $this->applyDerivedFigures($prestamo, $line);
            $previousLineId = $prestamo->getOriginal('id_lineas_prestamos');
            $prestamo->save();

            $line->refreshAvailable();
            if ($previousLineId !== null && (int) $previousLineId !== $line->id) {
                LineaPrestamo::find($previousLineId)?->refreshAvailable();
            }
        }

        return redirect()->route('prestamos.show', $prestamo)->with('status', 'Sub Préstamo actualizado.');
    }

    /**
     * Cost of paying the loan off on a date, as JSON for the receipt form.
     */
    public function quote(Request $request, Prestamo $prestamo): JsonResponse
    {
        $request->validate(['fecha' => ['required', 'date']]);

        return response()->json($this->schedules->payoffQuote($prestamo, Carbon::parse($request->query('fecha'))->startOfDay()));
    }

    /**
     * Terms can only change while no receipt or revision depends on them.
     */
    protected function isLocked(Prestamo $loan): bool
    {
        return $loan->recibos()->active()->exists() || $loan->revisiones()->active()->exists();
    }

    protected function form(?Prestamo $loan): View
    {
        $locked = $loan !== null && $this->isLocked($loan);
        $termField = fn (array $field): array => $field + ['readonly' => $locked];

        $lines = LineaPrestamo::active()->with(['banco', 'empresa'])->orderBy('nombre')->get();
        $used = Prestamo::active()
            ->when($loan, fn ($query) => $query->whereKeyNot($loan->id))
            ->selectRaw('id_lineas_prestamos, SUM(saldo_prestamo) as used')
            ->groupBy('id_lineas_prestamos')
            ->pluck('used', 'id_lineas_prestamos');

        $lineInfo = $lines->mapWithKeys(fn (LineaPrestamo $line): array => [$line->id => [
            'bank' => $line->banco?->nombre_banco ?? 'Sin banco',
            'company' => $line->empresa->nombre_empresa,
            'currency' => $line->moneda,
            'limit' => (float) $line->monto_maximo,
            'available' => (float) $line->monto_maximo - (float) ($used[$line->id] ?? 0),
            'payment' => $line->modalidad_pago.' · cuota '.strtolower($line->modalidad_cuota),
            'rate' => (float) $line->tasa_interes,
            'spread' => (float) $line->tasa_spread,
        ]])->all();

        return view('catalog.form', [
            'title' => 'Sub Préstamos',
            'singular' => 'Sub Préstamo',
            'routeBase' => 'prestamos',
            'record' => $loan,
            'notice' => $locked ? 'Este sub préstamo ya tiene recibos o revisiones: solo puede cambiar el número y la observación. Para cambiar la tasa o el plazo use una revisión.' : null,
            'lineInfo' => $lineInfo,
            'emptyState' => $lines->isEmpty() ? [
                'message' => 'Un sub préstamo siempre pertenece a una línea de crédito activa. Cree primero la línea (banco, monto máximo, condiciones de pago y tasa) y vuelva aquí.',
                'route' => route('lineas.create'),
                'label' => 'Crear línea de crédito',
            ] : null,
            'groups' => [
                'Identificación' => ['icon' => 'file-text', 'tone' => 'blue', 'description' => 'Número del sub préstamo y línea de crédito de la que se desembolsa.'],
                'Condiciones financieras' => ['icon' => 'banknote', 'tone' => 'green', 'description' => 'Monto, fecha de formalización y plazo.'],
                'Tasa y cuota' => ['icon' => 'percent', 'tone' => 'violet', 'description' => 'Parten de la línea y se pueden ajustar para este sub préstamo.'],
                'Notas' => ['icon' => 'info', 'tone' => 'amber', 'description' => 'Información adicional opcional.'],
            ],
            'fields' => [
                'numero_prestamo' => ['group' => 'Identificación', 'label' => 'Número de sub préstamo', 'type' => 'text', 'placeholder' => 'Ej. 9090'],
                'id_lineas_prestamos' => $termField(['group' => 'Identificación', 'label' => 'Línea de crédito', 'type' => 'select', 'options' => $lines->pluck('nombre', 'id')->all(), 'blank' => 'Seleccione una línea']),
                'monto_prestamo' => $termField(['group' => 'Condiciones financieras', 'label' => 'Monto del sub préstamo', 'type' => 'number', 'step' => '0.01', 'prefix' => '$']),
                'formalizacion' => $termField(['group' => 'Condiciones financieras', 'label' => 'Fecha de formalización', 'type' => 'date']),
                'plazo_meses' => $termField(['group' => 'Condiciones financieras', 'label' => 'Plazo', 'type' => 'number', 'step' => '1', 'suffix' => 'meses']),
                'tasa_interes' => $termField(['group' => 'Tasa y cuota', 'label' => 'Tasa de interés', 'type' => 'number', 'step' => '0.0001', 'suffix' => '%']),
                'tasa_spread' => $termField(['group' => 'Tasa y cuota', 'label' => 'Spread', 'type' => 'number', 'step' => '0.0001', 'suffix' => '%']),
                'cuota_calculada' => $termField(['group' => 'Tasa y cuota', 'label' => 'Cuota pactada', 'type' => 'number', 'step' => '0.01', 'prefix' => '$', 'required' => false, 'hint' => 'Opcional. Si la deja vacía se calcula con el plazo y la tasa; úsela cuando el banco defina la cuota.']),
                'observacion' => ['group' => 'Notas', 'span' => 2, 'label' => 'Observación', 'type' => 'text', 'required' => false],
            ],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(?Prestamo $loan): array
    {
        return [
            'numero_prestamo' => ['required', 'string', 'max:100', Rule::unique('prestamos', 'numero_prestamo')->ignore($loan?->id)],
            'id_lineas_prestamos' => ['required', Rule::exists('lineas_prestamos', 'id')->where('estado', LineaPrestamo::STATUS_ACTIVE)],
            'monto_prestamo' => ['required', 'numeric', 'gt:0'],
            'formalizacion' => ['required', 'date'],
            'plazo_meses' => ['required', 'integer', 'min:1', 'max:1200'],
            'tasa_interes' => ['required', 'numeric', 'min:0', 'max:999'],
            'tasa_spread' => ['required', 'numeric', 'min:0', 'max:999'],
            'cuota_calculada' => ['nullable', 'numeric', 'min:0'],
            'observacion' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The new loan may not exceed what is still available in its credit line.
     */
    protected function ensureCreditAvailable(LineaPrestamo $line, float $amount, ?Prestamo $current): void
    {
        $used = Prestamo::active()
            ->where('id_lineas_prestamos', $line->id)
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->sum('saldo_prestamo');
        $available = ($line->monto_maximo ?? 0) - $used;

        if ($amount > $available + 0.005) {
            throw ValidationException::withMessages([
                'monto_prestamo' => 'El monto supera el disponible de la línea ('.number_format($available, 2).').',
            ]);
        }
    }

    /**
     * Fill the contractual installment, balance and dates from the plan.
     */
    protected function applyDerivedFigures(Prestamo $loan, LineaPrestamo $line): void
    {
        $loan->setRelation('linea', $line);
        $loan->cuota_calculada = (float) ($loan->cuota_calculada ?? 0);

        $probe = $this->builder->build($loan->replicate()->forceFill(['cuota_calculada' => 0]), $line, [], [], today());
        $first = $probe->rows->first();

        if ($loan->cuota_calculada <= 0) {
            $loan->cuota_calculada = $first?->total() ?? 0;
        }

        $schedule = $this->builder->build($loan, $line, [], [], today());
        $loan->saldo_prestamo = $loan->monto_prestamo;
        $loan->proximo_pago = $schedule->rows->first()?->date ?? $loan->formalizacion;
        $loan->vencimiento = $schedule->maturityDate() ?? $loan->formalizacion;
        $loan->estado = Prestamo::STATUS_ACTIVE;
    }
}
