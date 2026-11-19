@props(['paginator'])

@if ($paginator->total() > 0)
    <nav class="pager" aria-label="Paginación">
        <span class="pager-summary">Mostrando {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}</span>
        <span class="pager-links">
            @if ($paginator->onFirstPage())
                <span class="btn btn-small is-disabled" aria-disabled="true">‹ Anterior</span>
            @else
                <a class="btn btn-small" href="{{ $paginator->previousPageUrl() }}">‹ Anterior</a>
            @endif

            <span class="pager-page">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>

            @if ($paginator->hasMorePages())
                <a class="btn btn-small" href="{{ $paginator->nextPageUrl() }}">Siguiente ›</a>
            @else
                <span class="btn btn-small is-disabled" aria-disabled="true">Siguiente ›</span>
            @endif
        </span>
    </nav>
@endif
