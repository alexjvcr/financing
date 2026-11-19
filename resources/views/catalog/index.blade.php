@extends('layouts.dashboard')

@section('title', $title)
@section('heading', $title)

@section('content')
    <x-search-box :action="route($routeBase.'.index')" :term="$term" :title="'Buscar en '.strtolower($title)" placeholder="Escriba para filtrar la lista" />

    <section class="card section-gap">
        <div class="card-header">
            <h2 class="card-title">{{ $title }} <span class="count-badge">{{ $records->total() }}</span></h2>
            <a href="{{ route($routeBase.'.create') }}" class="btn btn-primary"><x-icon name="plus" /> Nuevo</a>
        </div>

        @if ($records->isEmpty())
            <p class="empty">{{ $term !== '' ? 'Ningún registro coincide con «'.$term.'».' : 'No hay registros todavía.' }}</p>
        @else
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            @foreach (array_keys($columns) as $label)
                                <th>{{ $label }}</th>
                            @endforeach
                            <th class="numeric">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            <tr>
                                @foreach ($columns as $cell)
                                    <td>{{ $cell($record) }}</td>
                                @endforeach
                                <x-row-actions
                                    :edit="route($routeBase.'.edit', $record->id)"
                                    :destroy="route($routeBase.'.destroy', $record->id)"
                                    :label="$labels[$record->id]"
                                    :blockers="$blockers[$record->id]" />
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-pager :paginator="$records" />
        @endif
    </section>
@endsection
