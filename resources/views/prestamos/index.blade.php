@extends('layouts.dashboard')

@section('title', 'Sub Préstamos')
@section('heading', 'Sub Préstamos')

@section('content')
    <x-search-box :action="route('prestamos.index')" :term="$term" title="Buscar sub préstamos" placeholder="Número, banco, línea u observación">
        <div class="search-field search-field-small">
            <label for="estado">Estado</label>
            <select id="estado" name="estado">
                <option value="">Todos</option>
                <option value="ACTIVO" @selected($status === 'ACTIVO')>Activos</option>
                <option value="LIQUIDADO" @selected($status === 'LIQUIDADO')>Liquidados</option>
            </select>
        </div>
    </x-search-box>

    <section class="card section-gap">
        <div class="card-header">
            <h2 class="card-title">Sub Préstamos <span class="count-badge">{{ $loans->total() }}</span></h2>
            <a href="{{ route('prestamos.create') }}" class="btn btn-primary"><x-icon name="plus" /> Nuevo sub préstamo</a>
        </div>

        @if ($loans->isEmpty())
            <p class="empty">{{ $term !== '' || $status ? 'Ningún sub préstamo coincide con la búsqueda.' : 'No hay sub préstamos.' }}</p>
        @else
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Sub Préstamo</th>
                            <th>Banco / línea</th>
                            <th class="numeric">Monto</th>
                            <th class="numeric">Saldo</th>
                            <th class="numeric">Tasa</th>
                            <th>Próximo pago</th>
                            <th>Vencimiento</th>
                            <th>Estado</th>
                            <th class="numeric">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($loans as $loan)
                            <tr>
                                <td><a href="{{ route('prestamos.show', $loan) }}"><strong>{{ $loan->numero_prestamo }}</strong></a></td>
                                <td>{{ $loan->linea->banco?->nombre_banco ?? $loan->linea->nombre }}</td>
                                <td class="numeric">{{ number_format($loan->monto_prestamo, 2) }}</td>
                                <td class="numeric">{{ number_format($loan->saldo_prestamo, 2) }}</td>
                                <td class="numeric">{{ number_format($loan->initialAnnualRate(), 2) }}%</td>
                                <td>{{ $loan->isActive() ? \App\Support\DateFormat::format($loan->proximo_pago) : '—' }}</td>
                                <td>@fecha($loan->vencimiento)</td>
                                <td><span class="badge {{ $loan->isActive() ? 'badge-info' : 'badge-success' }}">{{ $loan->estado }}</span></td>
                                <x-row-actions
                                    :show="route('prestamos.show', $loan)"
                                    :edit="route('prestamos.edit', $loan)"
                                    :destroy="route('prestamos.destroy', $loan)"
                                    :label="'Sub Préstamo '.$loan->numero_prestamo"
                                    :blockers="$blockers[$loan->id]" />
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-pager :paginator="$loans" />
        @endif
    </section>
@endsection
