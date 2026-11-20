@props(['name', 'value' => null, 'id' => null, 'required' => false, 'readonly' => false])

{{--
    Campo de fecha que muestra y acepta fechas en el formato definido por DATE_FORMAT en .env.
    Úsalo para toda fecha en un formulario:
    <x-date-input name="fecha" :value="old('fecha', $record?->fecha)" required />
    La entrada oculta "_dates[]" hace que ConvertDisplayDates convierta el valor a yyyy-mm-dd
    antes de validar, así el controlador sigue usando la regla "date" normal.
--}}
@php
    $inputId = $id ?? $name;
    $placeholder = \App\Support\DateFormat::placeholder();
@endphp

<div class="date-input" data-date-input>
    @unless ($readonly)
        <input type="hidden" name="_dates[]" value="{{ $name }}">
    @endunless

    <input
        id="{{ $inputId }}"
        type="text"
        inputmode="numeric"
        autocomplete="off"
        maxlength="10"
        @unless ($readonly) name="{{ $name }}" @endunless
        value="{{ \App\Support\DateFormat::toDisplay($value) }}"
        placeholder="{{ $placeholder }}"
        pattern="{{ \App\Support\DateFormat::regex() }}"
        title="Formato {{ $placeholder }}"
        @required($required)
        @readonly($readonly)
        @disabled($readonly)>

    @unless ($readonly)
        <button type="button" class="date-picker-button" data-date-picker aria-label="Abrir calendario" tabindex="-1">
            <x-icon name="calendar" />
        </button>
        <input type="date" class="date-native" data-date-native tabindex="-1" aria-hidden="true">
    @endunless
</div>
