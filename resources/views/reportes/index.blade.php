@extends('layouts.dashboard')

@section('title', 'Reportes')
@section('heading', 'Reportes')

@section('content')
    <p class="pane-note">Reportes para la toma de decisiones. Cada uno se puede consultar en pantalla y descargar en Excel.</p>

    <div class="report-grid">
        @foreach ($reports as $report)
            <section class="card report-card {{ $report['available'] ? '' : 'is-planned' }}">
                <h2 class="card-title"><x-icon :name="$report['icon']" /> {{ $report['title'] }}</h2>
                <p class="report-description">{{ $report['description'] }}</p>

                @if ($report['available'])
                    <div class="report-actions">
                        <a href="{{ route('reportes.show', $report['slug']) }}" class="btn btn-primary"><x-icon name="eye" /> Abrir</a>
                        <a href="{{ route('reportes.excel', $report['slug']) }}" class="btn"><x-icon name="file-text" /> Descargar Excel</a>
                    </div>
                @else
                    <span class="badge badge-neutral">Pronto</span>
                @endif
            </section>
        @endforeach
    </div>
@endsection
