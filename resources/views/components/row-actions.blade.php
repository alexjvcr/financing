@props(['edit' => null, 'show' => null, 'destroy', 'label', 'blockers' => []])

<td class="actions">
    @if ($show)
        <a href="{{ $show }}" class="btn btn-small" title="Ver detalle"><x-icon name="eye" /> Ver</a>
    @endif

    @if ($edit)
        <a href="{{ $edit }}" class="btn btn-small" title="Actualizar"><x-icon name="edit" /> Editar</a>
    @endif

    @if (count($blockers) > 0)
        <span class="badge badge-neutral" title="No se puede eliminar: {{ implode(' ', $blockers) }}">En uso</span>
        <span title="No se puede eliminar: {{ implode(' ', $blockers) }}">
            <button type="button" class="btn btn-small btn-danger" disabled><x-icon name="lock" /> Eliminar</button>
        </span>
    @else
        <form method="POST" action="{{ $destroy }}" data-confirm="¿Eliminar «{{ $label }}»? Esta acción no se puede deshacer.">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-small btn-danger"><x-icon name="trash" /> Eliminar</button>
        </form>
    @endif
</td>
