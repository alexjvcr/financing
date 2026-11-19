@extends('layouts.dashboard')

@section('title', 'Sub Préstamo '.$loan->numero_prestamo)
@section('heading', 'Sub Préstamo '.$loan->numero_prestamo)

@section('content')
    @php
        $latestReceipt = $receipts->first(fn ($receipt) => $receipt->isActive());
        $latestRevision = $revisions->first(fn ($revision) => $revision->isActive());
        $canOperate = ! $schedule->isSettled();

        $tabs = ['plan' => ['calendar', 'Plan de pagos']];
        if ($canOperate) {
            $tabs['receipt'] = ['plus', 'Registrar recibo'];
            $tabs['revision'] = ['percent', 'Revisión de tasa o plazo'];
        }
        $tabs['receipts'] = ['file-text', 'Recibos ('.$receipts->count().')'];
        $tabs['details'] = ['dollar', 'Detalle de pagos ('.$details->count().')'];
        $tabs['revisions'] = ['activity', 'Revisiones ('.$revisions->count().')'];

        $activeTab = 'plan';
        if ($errors->hasAny(['tipo', 'fecha_pago', 'numero_cuota', 'monto_principal', 'monto_interes', 'monto_otros', 'tipo_pago_otros_id', 'descripcion_otros', 'observaciones', 'recibo'])) {
            $activeTab = 'receipt';
        } elseif ($errors->hasAny(['fecha_efectiva', 'tasa_interes', 'tasa_spread', 'plazo_restante_meses', 'observacion', 'revision'])) {
            $activeTab = 'revision';
        }
        $activeTab = array_key_exists($activeTab, $tabs) ? $activeTab : 'plan';
    @endphp

    <div class="page-actions">
        <a href="{{ route('prestamos.index') }}" class="btn btn-soft-primary"><x-icon name="arrow-left" /> Sub Préstamos</a>
        <a href="{{ route('prestamos.edit', $loan) }}" class="btn"><x-icon name="edit" /> Editar</a>
        @if (count($blockers) > 0)
            <span title="No se puede eliminar: {{ implode(' ', $blockers) }}">
                <button type="button" class="btn btn-danger" disabled><x-icon name="lock" /> Eliminar</button>
            </span>
        @else
            <form method="POST" action="{{ route('prestamos.destroy', $loan) }}" data-confirm="¿Eliminar el sub préstamo {{ $loan->numero_prestamo }}? Esta acción no se puede deshacer.">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger"><x-icon name="trash" /> Eliminar</button>
            </form>
        @endif
    </div>

    <section class="stat-grid" aria-label="Resumen del sub préstamo">
        <article class="card stat">
            <span class="stat-icon"><x-icon name="banknote" /></span>
            <div>
                <p class="stat-label">Saldo de principal</p>
                <p class="stat-value">${{ number_format($schedule->outstandingPrincipal(), 2) }}</p>
                <p class="stat-hint">Monto original ${{ number_format($loan->monto_prestamo, 2) }}</p>
            </div>
        </article>
        <article class="card stat">
            <span class="stat-icon"><x-icon name="activity" /></span>
            <div>
                <p class="stat-label">Tasa vigente</p>
                <p class="stat-value">{{ number_format($schedule->currentAnnualRate, 2) }}%</p>
                <p class="stat-hint">{{ $loan->linea->banco?->nombre_banco ?? $loan->linea->nombre }} · {{ $loan->linea->modalidad_cuota }}</p>
            </div>
        </article>
        <article class="card stat">
            <span class="stat-icon"><x-icon name="calendar" /></span>
            <div>
                <p class="stat-label">Próxima cuota</p>
                @if ($next)
                    <p class="stat-value">${{ number_format($next->total(), 2) }}</p>
                    <p class="stat-hint">#{{ $next->number }} · @fecha($next->date)</p>
                @else
                    <p class="stat-value">—</p>
                    <p class="stat-hint">{{ $schedule->isSettled() ? 'Sub Préstamo liquidado' : 'Sin cuotas pendientes' }}</p>
                @endif
            </div>
        </article>
        <article class="card stat {{ $schedule->overdueInstallments()->isNotEmpty() ? 'stat-danger' : '' }}">
            <span class="stat-icon"><x-icon name="alert" /></span>
            <div>
                <p class="stat-label">Cuotas vencidas</p>
                <p class="stat-value">{{ $schedule->overdueInstallments()->count() }}</p>
                <p class="stat-hint">Vence el {{ \App\Support\DateFormat::format($schedule->maturityDate()) ?: '—' }}</p>
            </div>
        </article>
    </section>

    <div class="section-gap" data-tabs>
        <div class="tab-bar" role="tablist">
            @foreach ($tabs as $key => [$icon, $label])
                <button type="button" class="tab {{ $key === $activeTab ? 'is-active' : '' }}" role="tab" data-tab="{{ $key }}" aria-selected="{{ $key === $activeTab ? 'true' : 'false' }}">
                    <x-icon :name="$icon" /> {{ $label }}
                </button>
            @endforeach
        </div>

        <section class="card tab-pane" data-panel="plan" role="tabpanel" @if ($activeTab !== 'plan') hidden @endif>
            <p class="pane-note">Plan reconstruido con los recibos, abonos y revisiones de tasa.</p>
            <div class="table-wrapper table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Fecha</th>
                            <th>Movimiento</th>
                            <th class="numeric">Tasa</th>
                            <th class="numeric">Principal</th>
                            <th class="numeric">Interés</th>
                            <th class="numeric">Total</th>
                            <th class="numeric">Saldo</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($schedule->rows as $row)
                            <tr class="{{ $row->status->value === 'VENCIDA' ? 'row-overdue' : '' }}">
                                <td>{{ $row->number ?? '' }}</td>
                                <td>@fecha($row->date)</td>
                                <td>{{ match ($row->kind) { 'cuota' => 'Cuota', 'abono' => 'Abono a principal', default => 'Cancelación' } }}</td>
                                <td class="numeric">{{ number_format($row->annualRate, 2) }}%</td>
                                <td class="numeric">{{ number_format($row->principal, 2) }}</td>
                                <td class="numeric">{{ number_format($row->interest, 2) }}</td>
                                <td class="numeric">{{ number_format($row->total(), 2) }}</td>
                                <td class="numeric">{{ number_format($row->balance, 2) }}</td>
                                <td>
                                    <span class="badge {{ $row->status->badgeClass() }}">{{ $row->status->label() }}</span>
                                    @if ($row->hasVariance())
                                        <span class="badge badge-neutral" title="Plan: principal {{ number_format($row->scheduledPrincipal, 2) }}, interés {{ number_format($row->scheduledInterest, 2) }}">≠ plan</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if ($canOperate)
            <section class="card tab-pane" data-panel="receipt" role="tabpanel" @if ($activeTab !== 'receipt') hidden @endif>
                <h2 class="card-title">Registrar recibo</h2>
                <form method="POST" action="{{ route('prestamos.recibos.store', $loan) }}" class="form-grid" id="receipt-form">
                    @csrf
                    <div class="form-field">
                        <label for="tipo">Tipo de recibo</label>
                        <select id="tipo" name="tipo" required>
                            @foreach ($receiptTypes as $type)
                                <option value="{{ $type->value }}" @selected(old('tipo') === $type->value)>{{ $type->code() }}. {{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="fecha_pago">Fecha de pago</label>
                        <x-date-input name="fecha_pago" :value="old('fecha_pago', today()->toDateString())" required />
                    </div>
                    <div class="form-field" id="installment-field">
                        <label for="numero_cuota">Número de cuota</label>
                        <input id="numero_cuota" type="number" min="1" name="numero_cuota" value="{{ old('numero_cuota', $next?->number) }}">
                    </div>
                    <div class="form-field">
                        <label for="monto_principal">Principal</label>
                        <input id="monto_principal" type="number" step="0.01" min="0" name="monto_principal" value="{{ old('monto_principal') }}">
                    </div>
                    <div class="form-field">
                        <label for="monto_interes">Interés</label>
                        <input id="monto_interes" type="number" step="0.01" min="0" name="monto_interes" value="{{ old('monto_interes') }}">
                    </div>
                    <div class="form-field">
                        <label for="observaciones">Observaciones</label>
                        <input id="observaciones" type="text" name="observaciones" maxlength="255" value="{{ old('observaciones') }}">
                    </div>
                    <div class="form-field">
                        <label for="monto_otros">Otros rubros (monto)</label>
                        <input id="monto_otros" type="number" step="0.01" min="0" name="monto_otros" value="{{ old('monto_otros') }}">
                    </div>
                    <div class="form-field">
                        <label for="tipo_pago_otros_id">Tipo de otros rubros</label>
                        <select id="tipo_pago_otros_id" name="tipo_pago_otros_id">
                            @foreach ($otherTypes as $otherType)
                                <option value="{{ $otherType->id }}" @selected((string) old('tipo_pago_otros_id') === (string) $otherType->id)>{{ $otherType->codigo }} · {{ $otherType->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="descripcion_otros">Detalle de otros rubros</label>
                        <input id="descripcion_otros" type="text" name="descripcion_otros" maxlength="255" value="{{ old('descripcion_otros') }}">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-lg"><x-icon name="check" /> Registrar recibo</button>
                    </div>
                </form>
            </section>

            <section class="card tab-pane" data-panel="revision" role="tabpanel" @if ($activeTab !== 'revision') hidden @endif>
                <h2 class="card-title">Revisión de tasa o plazo</h2>
                <form method="POST" action="{{ route('prestamos.revisiones.store', $loan) }}" class="form-grid">
                    @csrf
                    <div class="form-field">
                        <label for="fecha_efectiva">Fecha efectiva</label>
                        <x-date-input name="fecha_efectiva" :value="old('fecha_efectiva', today()->toDateString())" required />
                    </div>
                    <div class="form-field">
                        <label for="rev_tasa_interes">Nueva tasa (%)</label>
                        <input id="rev_tasa_interes" type="number" step="0.0001" min="0" name="tasa_interes" value="{{ old('tasa_interes') }}">
                    </div>
                    <div class="form-field">
                        <label for="rev_tasa_spread">Nuevo spread (%)</label>
                        <input id="rev_tasa_spread" type="number" step="0.0001" min="0" name="tasa_spread" value="{{ old('tasa_spread') }}">
                    </div>
                    <div class="form-field">
                        <label for="plazo_restante_meses">Nuevo plazo restante (meses)</label>
                        <input id="plazo_restante_meses" type="number" min="1" name="plazo_restante_meses" value="{{ old('plazo_restante_meses') }}">
                    </div>
                    <div class="form-field">
                        <label for="observacion">Observación</label>
                        <input id="observacion" type="text" name="observacion" maxlength="255" value="{{ old('observacion') }}">
                    </div>
                    <p class="hint">Deje vacío lo que no cambia. La cuota se recalcula desde el período siguiente.</p>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-lg"><x-icon name="check" /> Registrar revisión</button>
                    </div>
                </form>
            </section>
        @endif

        <section class="card tab-pane" data-panel="receipts" role="tabpanel" @if ($activeTab !== 'receipts') hidden @endif>
            @if ($receipts->isEmpty())
                <p class="empty">Aún no hay recibos.</p>
            @else
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr><th>Fecha</th><th>Tipo</th><th>Cuota</th><th class="numeric">Principal</th><th class="numeric">Interés</th><th class="numeric">Otros</th><th class="numeric">Total</th><th>Usuario</th><th class="numeric">Acciones</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($receipts as $receipt)
                                <tr class="{{ $receipt->isActive() ? '' : 'row-voided' }}">
                                    <td>@fecha($receipt->fecha_pago)</td>
                                    <td>{{ $receipt->tipo->code() }}. {{ $receipt->tipo->label() }}</td>
                                    <td>{{ $receipt->numero_cuota ?? '—' }}</td>
                                    <td class="numeric">{{ number_format($receipt->monto_principal, 2) }}</td>
                                    <td class="numeric">{{ number_format($receipt->monto_interes, 2) }}</td>
                                    <td class="numeric">{{ number_format($receipt->monto_otros, 2) }}</td>
                                    <td class="numeric"><strong>{{ number_format($receipt->totalAmount(), 2) }}</strong></td>
                                    <td>{{ $receipt->usuario }}</td>
                                    <td class="actions">
                                        @if (! $receipt->isActive())
                                            <span class="badge badge-neutral" title="{{ $receipt->motivo_anulacion }}">Anulado</span>
                                        @elseif ($latestReceipt?->id === $receipt->id)
                                            <form method="POST" action="{{ route('recibos.anular', $receipt) }}" class="inline-form" data-confirm="¿Anular este recibo? El plan de pagos se recalculará.">
                                                @csrf
                                                <input type="text" name="motivo" placeholder="Motivo" required maxlength="255" aria-label="Motivo de anulación">
                                                <button type="submit" class="btn btn-small btn-danger"><x-icon name="x" /> Anular</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card tab-pane" data-panel="details" role="tabpanel" @if ($activeTab !== 'details') hidden @endif>
            <p class="pane-note">Cada recibo se desglosa por tipo de pago, con la cuota del plan a la que se aplicó.</p>
            @if ($details->isEmpty())
                <p class="empty">Aún no hay detalle de pagos.</p>
            @else
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr><th>Fecha de pago</th><th>Tipo de pago</th><th class="numeric">Monto</th><th>Cuota</th><th>Fecha de cuota</th><th>Detalle</th><th>Recibo</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($details as $detail)
                                <tr class="{{ $detail->recibo->isActive() ? '' : 'row-voided' }}">
                                    <td>@fecha($detail->fecha_pago)</td>
                                    <td><span class="badge badge-info">{{ $detail->tipoPago->codigo }}</span> {{ $detail->tipoPago->nombre }}</td>
                                    <td class="numeric">{{ number_format($detail->monto, 2) }}</td>
                                    <td>{{ $detail->numero_cuota ?? '—' }}</td>
                                    <td>{{ \App\Support\DateFormat::format($detail->fecha_cuota) ?: '—' }}</td>
                                    <td>{{ $detail->descripcion ?? '—' }}</td>
                                    <td>#{{ $detail->recibo_id }}@unless ($detail->recibo->isActive()) <span class="badge badge-neutral">Anulado</span>@endunless</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card tab-pane" data-panel="revisions" role="tabpanel" @if ($activeTab !== 'revisions') hidden @endif>
            @if ($revisions->isEmpty())
                <p class="empty">Sin revisiones.</p>
            @else
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr><th>Efectiva</th><th class="numeric">Tasa</th><th class="numeric">Spread</th><th class="numeric">Plazo restante</th><th>Usuario</th><th class="numeric">Acciones</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($revisions as $revision)
                                <tr class="{{ $revision->isActive() ? '' : 'row-voided' }}">
                                    <td>@fecha($revision->fecha_efectiva)</td>
                                    <td class="numeric">{{ $revision->tasa_interes === null ? '—' : number_format($revision->tasa_interes, 4) }}</td>
                                    <td class="numeric">{{ $revision->tasa_spread === null ? '—' : number_format($revision->tasa_spread, 4) }}</td>
                                    <td class="numeric">{{ $revision->plazo_restante_meses === null ? '—' : $revision->plazo_restante_meses.' m' }}</td>
                                    <td>{{ $revision->usuario }}</td>
                                    <td class="actions">
                                        @if (! $revision->isActive())
                                            <span class="badge badge-neutral">Anulada</span>
                                        @elseif ($latestRevision?->id === $revision->id)
                                            <form method="POST" action="{{ route('revisiones.anular', $revision) }}" class="inline-form" data-confirm="¿Anular esta revisión? El plan de pagos se recalculará.">
                                                @csrf
                                                <button type="submit" class="btn btn-small btn-danger"><x-icon name="x" /> Anular</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <script>
        (() => {
            const form = document.getElementById('receipt-form');
            if (! form) {
                return;
            }

            const next = @json($next ? ['number' => $next->number, 'principal' => $next->principal, 'interest' => $next->interest] : null);
            const quoteUrl = @json(route('prestamos.cotizacion', $loan));
            const type = form.querySelector('#tipo');
            const date = form.querySelector('#fecha_pago');
            const number = form.querySelector('#numero_cuota');
            const principal = form.querySelector('#monto_principal');
            const interest = form.querySelector('#monto_interes');
            const installmentField = form.querySelector('#installment-field');

            // The date field shows the configured format (DATE_FORMAT); the quote endpoint expects yyyy-mm-dd.
            const isoDate = () => window.dateFormat.toIso(date.value);

            const apply = async () => {
                const kind = type.value;
                installmentField.hidden = ! ['CUOTA_COMPLETA', 'PARCIAL_INTERES'].includes(kind);

                if (kind === 'CUOTA_COMPLETA' && next) {
                    number.value = next.number;
                    principal.value = next.principal.toFixed(2);
                    interest.value = next.interest.toFixed(2);
                } else if (kind === 'PARCIAL_INTERES' && next) {
                    number.value = next.number;
                    principal.value = '0';
                    interest.value = next.interest.toFixed(2);
                } else if (kind === 'SOLO_PRINCIPAL' || kind === 'ADELANTO_CUOTA') {
                    number.value = '';
                    interest.value = '0';
                } else if (kind === 'CANCELACION_TOTAL' && isoDate()) {
                    number.value = '';
                    const response = await fetch(`${quoteUrl}?fecha=${isoDate()}`, { headers: { Accept: 'application/json' } });
                    if (response.ok) {
                        const quote = await response.json();
                        principal.value = quote.principal.toFixed(2);
                        interest.value = quote.interest.toFixed(2);
                    }
                }
            };

            type.addEventListener('change', apply);
            date.addEventListener('change', () => type.value === 'CANCELACION_TOTAL' && apply());
            installmentField.hidden = ! ['CUOTA_COMPLETA', 'PARCIAL_INTERES'].includes(type.value);
        })();
    </script>
@endsection
