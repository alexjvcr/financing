@extends('layouts.dashboard')

@section('title', $report->title())
@section('heading', $report->title())

@section('content')
    @php
        $currencyFilter = $filters['currency'];
        $months = $data['service_months'];
        $money = fn ($value) => $value === null ? '—' : number_format($value, 2);
        $percent = fn ($value, int $decimals = 2) => $value === null ? '—' : number_format($value, $decimals).' %';
        $excelUrl = route('reportes.excel', ['slug' => $report->slug(), 'moneda' => $currencyFilter]);
        $tabs = [
            'banks' => ['layers', 'Resumen por banco ('.$data['banks']->count().')'],
            'lines' => ['calendar', 'Detalle por línea ('.$data['lines']->count().')'],
            'loans' => ['banknote', 'Sub préstamos ('.$data['loans']->count().')'],
            'guidance' => ['alert', 'Guía de decisión ('.count($data['guidance']['attention']).')'],
        ];
    @endphp

    <div class="page-actions">
        <a href="{{ route('reportes.index') }}" class="btn btn-soft-primary"><x-icon name="arrow-left" /> Reportes</a>
        <a href="{{ $excelUrl }}" class="btn btn-primary"><x-icon name="file-text" /> Descargar Excel</a>
    </div>

    <section class="card search-box">
        <h2 class="card-title"><x-icon name="search" /> Filtros</h2>
        <form method="GET" action="{{ route('reportes.show', $report->slug()) }}" class="search-form">
            <div class="search-field search-field-small">
                <label for="moneda">Moneda</label>
                <select id="moneda" name="moneda">
                    <option value="">Todas</option>
                    @foreach ($data['currencies'] as $currency)
                        <option value="{{ $currency }}" @selected($currencyFilter === $currency)>{{ $currency }}</option>
                    @endforeach
                </select>
            </div>
            <div class="search-field">
                <label>Fecha de corte</label>
                <p class="report-date">@fecha($data['as_of'])</p>
            </div>
            <div class="search-actions">
                <button type="submit" class="btn btn-primary"><x-icon name="search" /> Aplicar</button>
                <a href="{{ route('reportes.show', $report->slug()) }}" class="btn"><x-icon name="x" /> Limpiar</a>
            </div>
        </form>
    </section>

    @forelse ($data['totals'] as $total)
        <h3 class="report-subtitle">Total en {{ $total['currency'] }} · {{ $total['lines_count'] }} línea(s) · {{ $total['banks_count'] }} banco(s)</h3>
        <section class="stat-grid" aria-label="Totales en {{ $total['currency'] }}">
            <article class="card stat">
                <span class="stat-icon"><x-icon name="banknote" /></span>
                <div>
                    <p class="stat-label">Deuda (saldo)</p>
                    <p class="stat-value">{{ $money($total['balance']) }}</p>
                    <p class="stat-hint">{{ $total['loans_count'] }} sub préstamo(s) activos</p>
                </div>
            </article>
            <article class="card stat">
                <span class="stat-icon"><x-icon name="layers" /></span>
                <div>
                    <p class="stat-label">Límite aprobado</p>
                    <p class="stat-value">{{ $money($total['limit']) }}</p>
                    <p class="stat-hint">Disponible {{ $money($total['available']) }}</p>
                </div>
            </article>
            <article class="card stat {{ $total['utilization'] >= 80 ? 'stat-danger' : '' }}">
                <span class="stat-icon"><x-icon name="activity" /></span>
                <div>
                    <p class="stat-label">Utilización</p>
                    <p class="stat-value">{{ $percent($total['utilization'], 1) }}</p>
                    <p class="stat-hint">Del cupo aprobado</p>
                </div>
            </article>
            <article class="card stat">
                <span class="stat-icon"><x-icon name="percent" /></span>
                <div>
                    <p class="stat-label">Tasa ponderada</p>
                    <p class="stat-value">{{ $percent($total['wavg_rate']) }}</p>
                    <p class="stat-hint">Interés anual estimado {{ $money($total['annual_interest']) }}</p>
                </div>
            </article>
            <article class="card stat">
                <span class="stat-icon"><x-icon name="calendar" /></span>
                <div>
                    <p class="stat-label">Servicio de la deuda (12 meses)</p>
                    <p class="stat-value">{{ $money($total['service_12']) }}</p>
                    <p class="stat-hint">3 m: {{ $money($total['service_3']) }} · 6 m: {{ $money($total['service_6']) }}</p>
                </div>
            </article>
            <article class="card stat {{ $total['overdue_count'] > 0 ? 'stat-danger' : '' }}">
                <span class="stat-icon"><x-icon name="alert" /></span>
                <div>
                    <p class="stat-label">Cuotas vencidas</p>
                    <p class="stat-value">{{ $total['overdue_count'] }}</p>
                    <p class="stat-hint">{{ $money($total['overdue_amount']) }} pendientes</p>
                </div>
            </article>
        </section>
    @empty
        <section class="card"><p class="empty">No hay líneas de crédito activas para esta moneda.</p></section>
    @endforelse

    <div class="section-gap" data-tabs>
        <div class="tab-bar" role="tablist">
            @foreach ($tabs as $key => [$icon, $label])
                <button type="button" class="tab {{ $loop->first ? 'is-active' : '' }}" role="tab" data-tab="{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                    <x-icon :name="$icon" /> {{ $label }}
                </button>
            @endforeach
        </div>

        <section class="card tab-pane" data-panel="banks" role="tabpanel">
            <p class="pane-note">Posición por banco. El servicio es principal más interés de las cuotas abiertas (incluye las vencidas) hasta cada horizonte.</p>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Banco</th><th>Moneda</th><th class="numeric">Líneas</th><th class="numeric">Límite</th><th class="numeric">Saldo</th><th class="numeric">Disponible</th>
                            <th class="numeric">% uso</th><th class="numeric">Tasa pond.</th><th class="numeric">% de la deuda</th><th class="numeric">Interés anual</th>
                            @foreach ($months as $m)
                                <th class="numeric">Servicio {{ $m }} m</th>
                            @endforeach
                            <th class="numeric">Vencidas</th><th>Próxima cuota</th><th class="numeric">Plazo prom. (años)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['banks'] as $bank)
                            <tr>
                                <td><strong>{{ $bank['bank'] }}</strong></td>
                                <td>{{ $bank['currency'] }}</td>
                                <td class="numeric">{{ $bank['lines_count'] }}</td>
                                <td class="numeric">{{ $money($bank['limit']) }}</td>
                                <td class="numeric">{{ $money($bank['balance']) }}</td>
                                <td class="numeric">{{ $money($bank['available']) }}</td>
                                <td class="numeric {{ $bank['utilization'] >= 80 ? 'is-negative' : '' }}">{{ $percent($bank['utilization'], 1) }}</td>
                                <td class="numeric">{{ $percent($bank['wavg_rate']) }}</td>
                                <td class="numeric">{{ $percent($bank['concentration'], 1) }}</td>
                                <td class="numeric">{{ $money($bank['annual_interest']) }}</td>
                                @foreach ($months as $m)
                                    <td class="numeric">{{ $money($bank["service_{$m}"]) }}</td>
                                @endforeach
                                <td class="numeric {{ $bank['overdue_count'] > 0 ? 'is-negative' : '' }}">{{ $bank['overdue_count'] }}</td>
                                <td>{{ $bank['next_due'] ? \App\Support\DateFormat::format($bank['next_due']) : '—' }}</td>
                                <td class="numeric">{{ $bank['wam_years'] === null ? '—' : number_format($bank['wam_years'], 1) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($data['totals']->isNotEmpty())
                        <tfoot>
                            @foreach ($data['totals'] as $total)
                                <tr>
                                    <td>TOTAL</td><td>{{ $total['currency'] }}</td><td class="numeric">{{ $total['lines_count'] }}</td>
                                    <td class="numeric">{{ $money($total['limit']) }}</td><td class="numeric">{{ $money($total['balance']) }}</td><td class="numeric">{{ $money($total['available']) }}</td>
                                    <td class="numeric">{{ $percent($total['utilization'], 1) }}</td><td class="numeric">{{ $percent($total['wavg_rate']) }}</td><td class="numeric">100.0 %</td>
                                    <td class="numeric">{{ $money($total['annual_interest']) }}</td>
                                    @foreach ($months as $m)
                                        <td class="numeric">{{ $money($total["service_{$m}"]) }}</td>
                                    @endforeach
                                    <td class="numeric">{{ $total['overdue_count'] }}</td>
                                    <td>{{ $total['next_due'] ? \App\Support\DateFormat::format($total['next_due']) : '—' }}</td>
                                    <td class="numeric">{{ $total['wam_years'] === null ? '—' : number_format($total['wam_years'], 1) }}</td>
                                </tr>
                            @endforeach
                        </tfoot>
                    @endif
                </table>
            </div>
        </section>

        <section class="card tab-pane" data-panel="lines" role="tabpanel" hidden>
            <p class="pane-note">"Tasa de la línea" es la de un nuevo desembolso; "Tasa ponderada" es la de lo que hoy se debe.</p>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Banco</th><th>Línea</th><th>Moneda</th><th class="numeric">Límite</th><th class="numeric">Saldo</th><th class="numeric">Disponible</th><th class="numeric">% uso</th>
                            <th class="numeric">Tasa línea</th><th class="numeric">Tasa pond.</th><th class="numeric">Sub préstamos</th><th class="numeric">Interés anual</th>
                            <th class="numeric">Servicio 12 m</th><th class="numeric">Vencidas</th><th>Próxima cuota</th><th>Vence la línea</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['lines'] as $line)
                            <tr>
                                <td>{{ $line['bank'] }}</td>
                                <td><strong>{{ $line['line'] }}</strong></td>
                                <td>{{ $line['currency'] }}</td>
                                <td class="numeric">{{ $money($line['limit']) }}</td>
                                <td class="numeric">{{ $money($line['balance']) }}</td>
                                <td class="numeric">{{ $money($line['available']) }}</td>
                                <td class="numeric {{ $line['utilization'] >= 80 ? 'is-negative' : '' }}">{{ $percent($line['utilization'], 1) }}</td>
                                <td class="numeric">{{ $percent($line['line_rate']) }}</td>
                                <td class="numeric">{{ $percent($line['wavg_rate']) }}</td>
                                <td class="numeric">{{ $line['loans_count'] }}</td>
                                <td class="numeric">{{ $money($line['annual_interest']) }}</td>
                                <td class="numeric">{{ $money($line['service_12']) }}</td>
                                <td class="numeric {{ $line['overdue_count'] > 0 ? 'is-negative' : '' }}">{{ $line['overdue_count'] }}</td>
                                <td>{{ $line['next_due'] ? \App\Support\DateFormat::format($line['next_due']) : '—' }}</td>
                                <td>{{ \App\Support\DateFormat::format($line['expires']) ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card tab-pane" data-panel="loans" role="tabpanel" hidden>
            @if ($data['loans']->isEmpty())
                <p class="empty">No hay sub préstamos activos.</p>
            @else
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Banco</th><th>Línea</th><th>Sub préstamo</th><th>Moneda</th><th class="numeric">Monto original</th><th class="numeric">Saldo</th><th class="numeric">Tasa</th>
                                <th class="numeric">Interés anual</th><th class="numeric">Cuota actual</th><th>Próxima cuota</th><th class="numeric">Vencidas</th><th>Vencimiento final</th><th class="numeric">Años</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($data['loans'] as $loan)
                                <tr>
                                    <td>{{ $loan['bank'] }}</td>
                                    <td>{{ $loan['line'] }}</td>
                                    <td><strong>{{ $loan['number'] }}</strong></td>
                                    <td>{{ $loan['currency'] }}</td>
                                    <td class="numeric">{{ $money($loan['original']) }}</td>
                                    <td class="numeric">{{ $money($loan['balance']) }}</td>
                                    <td class="numeric">{{ $percent($loan['rate']) }}</td>
                                    <td class="numeric">{{ $money($loan['annual_interest']) }}</td>
                                    <td class="numeric">{{ $money($loan['installment']) }}</td>
                                    <td>{{ $loan['next_due'] ? \App\Support\DateFormat::format($loan['next_due']) : '—' }}</td>
                                    <td class="numeric {{ $loan['overdue_count'] > 0 ? 'is-negative' : '' }}">{{ $loan['overdue_count'] }}</td>
                                    <td>{{ $loan['maturity'] ? \App\Support\DateFormat::format($loan['maturity']) : '—' }}</td>
                                    <td class="numeric">{{ number_format($loan['years'], 1) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card tab-pane" data-panel="guidance" role="tabpanel" hidden>
            <p class="pane-note">Sugerencias automáticas basadas en reglas: pagar primero lo más caro, usar primero el cupo más barato y revisar utilización ≥ 80 %, vencimientos en 12 meses, concentración ≥ 50 % por banco y cuotas vencidas.</p>

            <div class="two-columns">
                <div>
                    <h3 class="report-subtitle">Pagar primero (mayor costo)</h3>
                    @forelse ($data['guidance']['pay_first'] as $item)
                        <p class="guidance-item"><span class="badge badge-danger">{{ number_format($item['rate'], 2) }} %</span>
                            <strong>{{ $item['bank'] }}</strong> · {{ $item['line'] }} · sub préstamo {{ $item['number'] }}<br>
                            <small>Saldo {{ $money($item['balance']) }} {{ $item['currency'] }} · interés anual estimado {{ $money($item['annual_interest']) }}</small></p>
                    @empty
                        <p class="empty">No hay deuda con saldo.</p>
                    @endforelse
                </div>
                <div>
                    <h3 class="report-subtitle">Usar primero (cupo más barato)</h3>
                    @forelse ($data['guidance']['use_first'] as $item)
                        <p class="guidance-item"><span class="badge badge-success">{{ number_format($item['line_rate'], 2) }} %</span>
                            <strong>{{ $item['bank'] }}</strong> · {{ $item['line'] }}<br>
                            <small>Disponible {{ $money($item['available']) }} {{ $item['currency'] }}</small></p>
                    @empty
                        <p class="empty">No hay cupo disponible.</p>
                    @endforelse
                </div>
            </div>

            <h3 class="report-subtitle">Atención</h3>
            @forelse ($data['guidance']['attention'] as $item)
                <p class="guidance-item"><span class="badge {{ ['danger' => 'badge-danger', 'warning' => 'badge-info', 'info' => 'badge-neutral'][$item['severity']] }}">{{ $item['severity_label'] }}</span>
                    <strong>{{ $item['scope'] }}</strong><br><small>{{ $item['text'] }}</small></p>
            @empty
                <p class="empty">Sin alertas: no hay cuotas vencidas, vencimientos cercanos, utilización alta ni concentración excesiva.</p>
            @endforelse
        </section>
    </div>
@endsection
