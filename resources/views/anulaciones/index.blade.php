@extends('layouts.dashboard')

@section('title', 'Anulaciones')
@section('heading', 'Anulaciones')

@section('content')
    @php
        $tabs = [
            'receipts' => ['file-text', 'Recibos por anular ('.$voidableReceipts->count().')'],
            'revisions' => ['percent', 'Revisiones por anular ('.$voidableRevisions->count().')'],
            'voided-receipts' => ['x', 'Recibos anulados ('.$voidedReceipts->total().')'],
            'voided-revisions' => ['x', 'Revisiones anuladas ('.$voidedRevisions->total().')'],
        ];
        $activeTab = 'receipts';
        if ($errors->has('revision')) {
            $activeTab = 'revisions';
        } elseif (request()->query('recibos_page')) {
            $activeTab = 'voided-receipts';
        } elseif (request()->query('revisiones_page')) {
            $activeTab = 'voided-revisions';
        }
    @endphp

    <x-search-box :action="route('anulaciones.index')" :term="$term" title="Buscar sub préstamo" placeholder="Número de sub préstamo, banco o línea" />

    <p class="alert alert-info">
        Las anulaciones se hacen <strong>del recibo más reciente al más antiguo</strong>: no se puede deshacer un pago mientras exista uno posterior.
        Al anular se restaura el saldo del sub préstamo, la cuota en el plan de pagos y el disponible de la línea, y queda registrado quién anuló, cuándo y por qué.
    </p>

    <div data-tabs>
        <div class="tab-bar" role="tablist">
            @foreach ($tabs as $key => [$icon, $label])
                <button type="button" class="tab {{ $key === $activeTab ? 'is-active' : '' }}" role="tab" data-tab="{{ $key }}" aria-selected="{{ $key === $activeTab ? 'true' : 'false' }}">
                    <x-icon :name="$icon" /> {{ $label }}
                </button>
            @endforeach
        </div>

        <section class="card tab-pane" data-panel="receipts" role="tabpanel" @if ($activeTab !== 'receipts') hidden @endif>
            <p class="pane-note">Solo aparece el recibo más reciente de cada sub préstamo, que es el único que se puede anular ahora.</p>
            @if ($voidableReceipts->isEmpty())
                <p class="empty">No hay recibos para anular.</p>
            @else
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha de pago</th>
                                <th>Sub Préstamo</th>
                                <th>Banco / línea</th>
                                <th>Recibo</th>
                                <th>Tipo</th>
                                <th>Cuota</th>
                                <th class="numeric">Total</th>
                                <th>Registrado por</th>
                                <th>Anteriores</th>
                                <th class="numeric">Anular</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($voidableReceipts as $receipt)
                                @php
                                    $loan = $receipt->prestamo;
                                @endphp
                                <tr>
                                    <td>@fecha($receipt->fecha_pago)</td>
                                    <td><a href="{{ route('prestamos.show', $loan) }}"><strong>{{ $loan->numero_prestamo }}</strong></a></td>
                                    <td>{{ $loan->linea->banco?->nombre_banco ?? $loan->linea->nombre }}</td>
                                    <td>#{{ $receipt->id }}</td>
                                    <td>{{ $receipt->tipo->code() }}. {{ $receipt->tipo->label() }}</td>
                                    <td>{{ $receipt->numero_cuota ?? '—' }}</td>
                                    <td class="numeric"><strong>{{ number_format($receipt->totalAmount(), 2) }}</strong></td>
                                    <td>{{ $receipt->usuario }}</td>
                                    <td>{{ $loan->recibos()->active()->count() - 1 }} más</td>
                                    <td class="actions">
                                        <form method="POST" action="{{ route('recibos.anular', $receipt) }}" class="inline-form"
                                              data-confirm="¿Anular el recibo #{{ $receipt->id }} del sub préstamo {{ $loan->numero_prestamo }}? Se restaurará el saldo, la cuota y el disponible de la línea.">
                                            @csrf
                                            <input type="hidden" name="volver" value="anulaciones">
                                            <input type="text" name="motivo" placeholder="Motivo" required maxlength="255" aria-label="Motivo de anulación">
                                            <button type="submit" class="btn btn-small btn-danger"><x-icon name="x" /> Anular</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card tab-pane" data-panel="revisions" role="tabpanel" @if ($activeTab !== 'revisions') hidden @endif>
            <p class="pane-note">Solo aparece la revisión más reciente de cada sub préstamo.</p>
            @if ($voidableRevisions->isEmpty())
                <p class="empty">No hay revisiones para anular.</p>
            @else
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha efectiva</th>
                                <th>Sub Préstamo</th>
                                <th class="numeric">Tasa</th>
                                <th class="numeric">Spread</th>
                                <th class="numeric">Plazo restante</th>
                                <th>Registrada por</th>
                                <th class="numeric">Anular</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($voidableRevisions as $revision)
                                <tr>
                                    <td>@fecha($revision->fecha_efectiva)</td>
                                    <td><a href="{{ route('prestamos.show', $revision->prestamo_id) }}"><strong>{{ $revision->prestamo->numero_prestamo }}</strong></a></td>
                                    <td class="numeric">{{ $revision->tasa_interes === null ? '—' : number_format($revision->tasa_interes, 4) }}</td>
                                    <td class="numeric">{{ $revision->tasa_spread === null ? '—' : number_format($revision->tasa_spread, 4) }}</td>
                                    <td class="numeric">{{ $revision->plazo_restante_meses === null ? '—' : $revision->plazo_restante_meses.' m' }}</td>
                                    <td>{{ $revision->usuario }}</td>
                                    <td class="actions">
                                        <form method="POST" action="{{ route('revisiones.anular', $revision) }}" class="inline-form"
                                              data-confirm="¿Anular la revisión #{{ $revision->id }} del sub préstamo {{ $revision->prestamo->numero_prestamo }}? El plan de pagos se recalculará.">
                                            @csrf
                                            <input type="hidden" name="volver" value="anulaciones">
                                            <input type="text" name="motivo" placeholder="Motivo" required maxlength="255" aria-label="Motivo de anulación">
                                            <button type="submit" class="btn btn-small btn-danger"><x-icon name="x" /> Anular</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="card tab-pane" data-panel="voided-receipts" role="tabpanel" @if ($activeTab !== 'voided-receipts') hidden @endif>
            <p class="pane-note">Auditoría: cada recibo anulado conserva la fecha, el usuario y el motivo de la anulación.</p>
            @if ($voidedReceipts->isEmpty())
                <p class="empty">Aún no hay recibos anulados.</p>
            @else
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Anulado el</th>
                                <th>Anulado por</th>
                                <th>Motivo</th>
                                <th>Recibo</th>
                                <th>Sub Préstamo</th>
                                <th>Fecha de pago</th>
                                <th>Tipo</th>
                                <th class="numeric">Total</th>
                                <th>Registrado por</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($voidedReceipts as $receipt)
                                <tr>
                                    <td>@fechahora($receipt->anulado_at)</td>
                                    <td>{{ $receipt->anulado_por ?? '—' }}</td>
                                    <td>{{ $receipt->motivo_anulacion ?? '—' }}</td>
                                    <td>#{{ $receipt->id }}</td>
                                    <td><a href="{{ route('prestamos.show', $receipt->prestamo_id) }}"><strong>{{ $receipt->prestamo->numero_prestamo }}</strong></a></td>
                                    <td>@fecha($receipt->fecha_pago)</td>
                                    <td>{{ $receipt->tipo->code() }}. {{ $receipt->tipo->label() }}</td>
                                    <td class="numeric">{{ number_format($receipt->totalAmount(), 2) }}</td>
                                    <td>{{ $receipt->usuario }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-pager :paginator="$voidedReceipts" />
            @endif
        </section>

        <section class="card tab-pane" data-panel="voided-revisions" role="tabpanel" @if ($activeTab !== 'voided-revisions') hidden @endif>
            @if ($voidedRevisions->isEmpty())
                <p class="empty">Aún no hay revisiones anuladas.</p>
            @else
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Anulada el</th>
                                <th>Anulada por</th>
                                <th>Motivo</th>
                                <th>Sub Préstamo</th>
                                <th>Fecha efectiva</th>
                                <th class="numeric">Tasa</th>
                                <th class="numeric">Spread</th>
                                <th class="numeric">Plazo restante</th>
                                <th>Registrada por</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($voidedRevisions as $revision)
                                <tr>
                                    <td>@fechahora($revision->anulado_at)</td>
                                    <td>{{ $revision->anulado_por ?? '—' }}</td>
                                    <td>{{ $revision->motivo_anulacion ?? '—' }}</td>
                                    <td><a href="{{ route('prestamos.show', $revision->prestamo_id) }}"><strong>{{ $revision->prestamo->numero_prestamo }}</strong></a></td>
                                    <td>@fecha($revision->fecha_efectiva)</td>
                                    <td class="numeric">{{ $revision->tasa_interes === null ? '—' : number_format($revision->tasa_interes, 4) }}</td>
                                    <td class="numeric">{{ $revision->tasa_spread === null ? '—' : number_format($revision->tasa_spread, 4) }}</td>
                                    <td class="numeric">{{ $revision->plazo_restante_meses === null ? '—' : $revision->plazo_restante_meses.' m' }}</td>
                                    <td>{{ $revision->usuario }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-pager :paginator="$voidedRevisions" />
            @endif
        </section>
    </div>
@endsection
