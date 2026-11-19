@extends('layouts.dashboard')

@section('title', 'Recibos')
@section('heading', 'Recibos')

@section('content')
    <x-search-box :action="route('recibos.index')" :term="$term" title="Buscar recibos" placeholder="Sub préstamo, banco, línea, usuario u observación">
        <div class="search-field search-field-small">
            <label for="tipo">Tipo de recibo</label>
            <select id="tipo" name="tipo">
                <option value="">Todos</option>
                @foreach ($types as $receiptType)
                    <option value="{{ $receiptType->value }}" @selected($type === $receiptType->value)>{{ $receiptType->code() }}. {{ $receiptType->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="search-field search-field-small">
            <label for="estado">Estado</label>
            <select id="estado" name="estado">
                <option value="ACTIVO" @selected($status === 'ACTIVO')>Activos</option>
                <option value="ANULADO" @selected($status === 'ANULADO')>Anulados</option>
                <option value="TODOS" @selected($status === 'TODOS')>Todos</option>
            </select>
        </div>
        <div class="search-field search-field-small">
            <label for="desde">Pagado desde</label>
            <x-date-input name="desde" :value="$from" />
        </div>
        <div class="search-field search-field-small">
            <label for="hasta">Pagado hasta</label>
            <x-date-input name="hasta" :value="$to" />
        </div>
    </x-search-box>

    <section class="stat-grid" aria-label="Totales de la búsqueda">
        <article class="card stat">
            <span class="stat-icon"><x-icon name="file-text" /></span>
            <div>
                <p class="stat-label">Recibos activos</p>
                <p class="stat-value">{{ number_format($totals->receipts) }}</p>
                <p class="stat-hint">Según los filtros aplicados</p>
            </div>
        </article>
        <article class="card stat">
            <span class="stat-icon"><x-icon name="banknote" /></span>
            <div>
                <p class="stat-label">Principal pagado</p>
                <p class="stat-value">${{ number_format($totals->principal, 2) }}</p>
            </div>
        </article>
        <article class="card stat">
            <span class="stat-icon"><x-icon name="percent" /></span>
            <div>
                <p class="stat-label">Interés pagado</p>
                <p class="stat-value">${{ number_format($totals->interest, 2) }}</p>
            </div>
        </article>
        <article class="card stat">
            <span class="stat-icon"><x-icon name="dollar" /></span>
            <div>
                <p class="stat-label">Total pagado</p>
                <p class="stat-value">${{ number_format($totals->principal + $totals->interest + $totals->other, 2) }}</p>
                <p class="stat-hint">Incluye otros rubros ${{ number_format($totals->other, 2) }}</p>
            </div>
        </article>
    </section>

    <section class="card section-gap">
        <div class="card-header">
            <h2 class="card-title">Recibos <span class="count-badge">{{ $receipts->total() }}</span></h2>
            <a href="{{ route('prestamos.index') }}" class="btn btn-primary"><x-icon name="plus" /> Registrar recibo</a>
        </div>
        <p class="pane-note">Para registrar un recibo, abra el sub préstamo y use la pestaña "Registrar recibo".</p>

        @if ($receipts->isEmpty())
            <p class="empty">No hay recibos con estos filtros.</p>
        @else
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha de pago</th>
                            <th>Sub Préstamo</th>
                            <th>Banco / línea</th>
                            <th>Tipo</th>
                            <th>Cuota</th>
                            <th class="numeric">Principal</th>
                            <th class="numeric">Interés</th>
                            <th class="numeric">Otros</th>
                            <th class="numeric">Total</th>
                            <th>Usuario</th>
                            <th>Estado</th>
                            <th class="numeric">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($receipts as $receipt)
                            <tr class="{{ $receipt->isActive() ? '' : 'row-voided' }}">
                                <td>@fecha($receipt->fecha_pago)</td>
                                <td><a href="{{ route('prestamos.show', $receipt->prestamo_id) }}"><strong>{{ $receipt->prestamo->numero_prestamo }}</strong></a></td>
                                <td>{{ $receipt->prestamo->linea->banco?->nombre_banco ?? $receipt->prestamo->linea->nombre }}</td>
                                <td>{{ $receipt->tipo->code() }}. {{ $receipt->tipo->label() }}</td>
                                <td>{{ $receipt->numero_cuota ?? '—' }}</td>
                                <td class="numeric">{{ number_format($receipt->monto_principal, 2) }}</td>
                                <td class="numeric">{{ number_format($receipt->monto_interes, 2) }}</td>
                                <td class="numeric">{{ number_format($receipt->monto_otros, 2) }}</td>
                                <td class="numeric"><strong>{{ number_format($receipt->totalAmount(), 2) }}</strong></td>
                                <td>{{ $receipt->usuario }}</td>
                                <td>
                                    @if ($receipt->isActive())
                                        <span class="badge badge-success">Activo</span>
                                    @else
                                        <span class="badge badge-neutral" title="{{ $receipt->motivo_anulacion }}">Anulado</span>
                                    @endif
                                </td>
                                <td class="actions">
                                    <a href="{{ route('prestamos.show', $receipt->prestamo_id) }}" class="btn btn-small" title="Ver el sub préstamo"><x-icon name="eye" /> Ver</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-pager :paginator="$receipts" />
        @endif
    </section>
@endsection
