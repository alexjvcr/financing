@extends('layouts.dashboard')

@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')
    <p class="greeting">Bienvenido, <strong>{{ auth()->user()->name }}</strong></p>

    <section class="stat-grid" aria-label="Indicadores">
        <article class="card stat">
            <span class="stat-icon"><x-icon name="banknote" /></span>
            <div>
                <p class="stat-label">Saldo de sub préstamos activos</p>
                <p class="stat-value">${{ number_format($summary['outstanding_balance'], 2) }}</p>
                <p class="stat-hint">{{ $summary['active_loans'] }} {{ Str::plural('sub préstamo', $summary['active_loans']) }} activos</p>
            </div>
        </article>

        <article class="card stat">
            <span class="stat-icon"><x-icon name="layers" /></span>
            <div>
                <p class="stat-label">Líneas de crédito activas</p>
                <p class="stat-value">{{ $summary['active_credit_lines'] }}</p>
                <p class="stat-hint">Monto máximo ${{ number_format($summary['credit_lines_limit'], 2) }}</p>
            </div>
        </article>

        <article class="card stat">
            <span class="stat-icon"><x-icon name="calendar" /></span>
            <div>
                <p class="stat-label">Próxima cuota</p>
                @if ($nextInstallment)
                    <p class="stat-value">${{ number_format($nextInstallment->amount, 2) }}</p>
                    <p class="stat-hint">@fecha($nextInstallment->due_date) · sub préstamo {{ $nextInstallment->loan_number }}</p>
                @else
                    <p class="stat-value">—</p>
                    <p class="stat-hint">Sin cuotas por vencer</p>
                @endif
            </div>
        </article>

        <article class="card stat {{ $summary['overdue_installments'] > 0 ? 'stat-danger' : '' }}">
            <span class="stat-icon"><x-icon name="alert" /></span>
            <div>
                <p class="stat-label">Cuotas vencidas</p>
                <p class="stat-value">{{ $summary['overdue_installments'] }}</p>
                <p class="stat-hint">${{ number_format($summary['overdue_amount'], 2) }} pendientes</p>
            </div>
        </article>
    </section>

    <section class="card section-gap">
        <h2 class="card-title">Disponible por banco</h2>
        <p class="card-subtitle">
            Disponible = monto máximo de la línea − saldo de sus sub préstamos activos. Los plazos suman el capital que se
            amortiza según el plan de pagos.
        </p>

        @if ($availability['rows']->isEmpty())
            <p class="empty">No hay líneas de crédito activas.</p>
        @else
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Banco / línea</th>
                            <th class="numeric">Monto máximo</th>
                            <th class="numeric">Saldo</th>
                            <th class="numeric">Disponible hoy</th>
                            @foreach ($availability['horizons'] as $months)
                                <th class="numeric">{{ $months }} meses</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($availability['rows'] as $row)
                            <tr>
                                <td>
                                    <strong>{{ $row->name }}</strong>
                                    <div class="usage" title="Utilizado {{ $row->limit > 0 ? number_format($row->balance / $row->limit * 100, 1) : 0 }}%">
                                        <span style="width: {{ $row->limit > 0 ? min(100, $row->balance / $row->limit * 100) : 0 }}%"></span>
                                    </div>
                                </td>
                                <td class="numeric">${{ number_format($row->limit, 2) }}</td>
                                <td class="numeric">${{ number_format($row->balance, 2) }}</td>
                                <td class="numeric {{ $row->available < 0 ? 'is-negative' : 'is-strong' }}">${{ number_format($row->available, 2) }}</td>
                                @foreach ($availability['horizons'] as $months)
                                    <td class="numeric {{ $row->projected[$months] < 0 ? 'is-negative' : '' }}">${{ number_format($row->projected[$months], 2) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        @foreach ($availability['totals'] as $total)
                            <tr>
                                <td>Total {{ $total->currency }}</td>
                                <td class="numeric">${{ number_format($total->limit, 2) }}</td>
                                <td class="numeric">${{ number_format($total->balance, 2) }}</td>
                                <td class="numeric {{ $total->available < 0 ? 'is-negative' : '' }}">${{ number_format($total->available, 2) }}</td>
                                @foreach ($availability['horizons'] as $months)
                                    <td class="numeric {{ $total->projected[$months] < 0 ? 'is-negative' : '' }}">${{ number_format($total->projected[$months], 2) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tfoot>
                </table>
            </div>

            @if ($availability['plan_ends_on'])
                <p class="card-note">
                    Último vencimiento de los sub préstamos activos: @fecha($availability['plan_ends_on']).
                    Los plazos se calculan con el plan de pagos reconstruido (recibos, abonos y revisiones de tasa).
                </p>
            @endif
        @endif
    </section>

    <section class="card section-gap">
        <h2 class="card-title">Cuotas pendientes</h2>

        @if ($installments->isEmpty())
            <p class="empty">No hay cuotas pendientes.</p>
        @else
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Sub Préstamo</th>
                            <th>Línea</th>
                            <th class="numeric">Cuota</th>
                            <th>Fecha de pago</th>
                            <th class="numeric">Monto</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($installments as $installment)
                            <tr>
                                <td>{{ $installment->loan_number }}</td>
                                <td>{{ $installment->credit_line }}</td>
                                <td class="numeric">{{ $installment->installment_number }}</td>
                                <td>@fecha($installment->due_date)</td>
                                <td class="numeric">${{ number_format($installment->amount, 2) }}</td>
                                <td>
                                    <span class="badge {{ $installment->is_overdue ? 'badge-danger' : 'badge-neutral' }}">
                                        {{ $installment->is_overdue ? 'Vencida' : 'Por vencer' }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
