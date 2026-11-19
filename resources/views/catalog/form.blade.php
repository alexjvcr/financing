@extends('layouts.dashboard')

@section('title', $title)
@section('heading', $title)

@section('content')
    @php
        $groups = $groups ?? [];
        $sections = collect($fields)->groupBy(fn (array $field): string => $field['group'] ?? $singular, preserveKeys: true);
        $action = $record ? route($routeBase.'.update', $record->id) : route($routeBase.'.store');
    @endphp

    <div class="page-actions">
        <a href="{{ route($routeBase.'.index') }}" class="btn btn-soft-primary"><x-icon name="arrow-left" /> {{ $title }}</a>
    </div>

    @php
        $firstGroup = collect($groups)->first();
    @endphp
    <div class="form-hero tone-{{ $firstGroup['tone'] ?? 'blue' }}">
        <span class="hero-icon"><x-icon :name="$firstGroup['icon'] ?? 'file-text'" /></span>
        <div>
            <h2>{{ $record ? 'Editar' : 'Nuevo' }} {{ strtolower($singular) }}</h2>
            <p>{{ $record ? 'Modifique los datos y guarde los cambios.' : 'Complete los datos de cada recuadro y guarde el registro.' }}</p>
        </div>
    </div>

    @if (! empty($emptyState) && ! $record)
        <section class="card empty-state">
            <span class="section-icon"><x-icon name="layers" /></span>
            <h3>Primero necesita una línea de crédito</h3>
            <p>{{ $emptyState['message'] }}</p>
            <a href="{{ $emptyState['route'] }}" class="btn btn-primary">{{ $emptyState['label'] }}</a>
        </section>
    @else
        <form method="POST" action="{{ $action }}" class="form-layout" id="record-form">
            @csrf
            @if ($record)
                @method('PUT')
            @endif

            <div class="form-main {{ $sections->count() > 1 ? 'has-tabs' : '' }}" data-tabs>
                @php
                    $activeTab = 0;
                    foreach ($sections->keys() as $index => $name) {
                        if ($sections[$name]->keys()->contains(fn ($field) => $errors->has($field))) {
                            $activeTab = $index;
                            break;
                        }
                    }
                @endphp

                @if ($sections->count() > 1)
                    <div class="tab-bar" role="tablist">
                        @foreach ($sections as $sectionName => $sectionFields)
                            <button type="button" class="tab {{ $loop->index === $activeTab ? 'is-active' : '' }}" role="tab" data-tab="{{ $loop->index }}" aria-selected="{{ $loop->index === $activeTab ? 'true' : 'false' }}">
                                <x-icon :name="$groups[$sectionName]['icon'] ?? 'file-text'" /> {{ $sectionName }}
                                @if ($sectionFields->keys()->contains(fn ($field) => $errors->has($field)))
                                    <span class="tab-dot" title="Hay campos con errores"></span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endif

                @foreach ($sections as $sectionName => $sectionFields)
                    <section class="card form-section tone-{{ $groups[$sectionName]['tone'] ?? 'blue' }}" data-panel="{{ $loop->index }}" role="tabpanel" @if ($sections->count() > 1 && $loop->index !== $activeTab) hidden @endif>
                        <header class="section-header">
                            <span class="section-icon"><x-icon :name="$groups[$sectionName]['icon'] ?? 'file-text'" /></span>
                            <div>
                                <h3>{{ $sectionName }}</h3>
                                @isset($groups[$sectionName]['description'])
                                    <p>{{ $groups[$sectionName]['description'] }}</p>
                                @endisset
                            </div>
                        </header>

                        <div class="section-fields">
                            @foreach ($sectionFields as $name => $field)
                                @php
                                    $value = old($name, $record?->{$name});
                                    $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
                                    $value = is_bool($value) ? (int) $value : $value;
                                    $readonly = $field['readonly'] ?? false;
                                    $required = $field['required'] ?? true;
                                @endphp
                                <div class="form-field {{ ($field['span'] ?? 1) > 1 ? 'span-2' : '' }} @error($name) has-error @enderror">
                                    <label for="{{ $name }}">
                                        @isset($field['icon'])<x-icon :name="$field['icon']" class="label-icon" />@endisset
                                        {{ $field['label'] }}@if ($required) <span class="required">*</span>@endif
                                    </label>

                                    @if ($readonly)
                                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                                    @endif

                                    @if ($field['type'] === 'select')
                                        <select id="{{ $name }}" name="{{ $readonly ? '' : $name }}" @disabled($readonly) @required($required)>
                                            @if (! $required || isset($field['blank']))
                                                <option value="">{{ $field['blank'] ?? 'Seleccione' }}</option>
                                            @endif
                                            @foreach ($field['options'] as $optionValue => $optionLabel)
                                                <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                                            @endforeach
                                        </select>
                                    @elseif ($field['type'] === 'date')
                                        <x-date-input :name="$name" :value="$value" :required="$required" :readonly="$readonly" />
                                    @else
                                        <div class="input-wrap">
                                            @isset($field['prefix'])<span class="affix">{{ $field['prefix'] }}</span>@endisset
                                            <input id="{{ $name }}" type="{{ $field['type'] }}" name="{{ $readonly ? '' : $name }}" value="{{ $value }}"
                                                @isset($field['step']) step="{{ $field['step'] }}" @endisset
                                                @isset($field['placeholder']) placeholder="{{ $field['placeholder'] }}" @endisset
                                                @readonly($readonly) @disabled($readonly) @required($required)>
                                            @isset($field['suffix'])<span class="affix">{{ $field['suffix'] }}</span>@endisset
                                        </div>
                                    @endif

                                    @error($name)
                                        <small class="field-error">{{ $message }}</small>
                                    @enderror
                                    @isset($field['hint'])
                                        <small class="hint">{{ $field['hint'] }}</small>
                                    @endisset
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>

            <aside class="form-aside">
                @isset($lineInfo)
                    <section class="card line-info tone-blue" id="line-info" data-empty="true">
                        <h3 class="aside-title"><x-icon name="layers" /> Línea seleccionada</h3>
                        <p class="empty" data-placeholder>Elija una línea de crédito para ver sus condiciones y el cupo disponible.</p>
                        <dl hidden>
                            <div><dt>Banco</dt><dd data-info="bank"></dd></div>
                            <div><dt>Empresa</dt><dd data-info="company"></dd></div>
                            <div><dt>Moneda</dt><dd data-info="currency"></dd></div>
                            <div><dt>Pago</dt><dd data-info="payment"></dd></div>
                            <div><dt>Monto máximo</dt><dd data-info="limit"></dd></div>
                            <div class="highlight"><dt>Disponible</dt><dd data-info="available"></dd></div>
                            <div><dt>Tasa de la línea</dt><dd data-info="rate"></dd></div>
                        </dl>
                        <div class="usage" hidden data-usage><span></span></div>
                    </section>
                @endisset

                @if (! empty($notice))
                    <p class="alert alert-info">{{ $notice }}</p>
                @endif

                <section class="card form-actions-card">
                    <button type="submit" class="btn btn-success btn-lg btn-block">
                        <x-icon name="check" /> Guardar
                    </button>
                    <a href="{{ route($routeBase.'.index') }}" class="btn btn-soft-danger btn-lg btn-block">
                        <x-icon name="x" /> Cancelar
                    </a>
                </section>
            </aside>
        </form>
    @endif

    @isset($historial)
        @if ($historial->isNotEmpty())
            <section class="card section-gap">
                <h2 class="card-title">Historial de cambios</h2>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Fecha</th><th class="numeric">Anterior</th><th class="numeric">Nuevo</th><th>Usuario</th></tr></thead>
                        <tbody>
                            @foreach ($historial as $change)
                                <tr>
                                    <td>@fechahora($change->created_at)</td>
                                    <td class="numeric">{{ $change->base_anterior === null ? '—' : number_format($change->base_anterior, 5) }}</td>
                                    <td class="numeric">{{ $change->base_nueva === null ? '—' : number_format($change->base_nueva, 5) }}</td>
                                    <td>{{ $change->usuario }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endisset

    @isset($lineInfo)
        <script>
            (() => {
                const lines = @json($lineInfo);
                const select = document.getElementById('id_lineas_prestamos');
                const panel = document.getElementById('line-info');
                const rate = document.getElementById('tasa_interes');
                const spread = document.getElementById('tasa_spread');
                const isNew = @json($record === null);

                if (! select || ! panel) {
                    return;
                }

                const money = (value, currency) => new Intl.NumberFormat('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value) + ' ' + currency;
                [rate, spread].forEach((input) => input && input.addEventListener('input', () => { input.dataset.touched = '1'; }));

                const render = () => {
                    const line = lines[select.value];
                    panel.querySelector('[data-placeholder]').hidden = Boolean(line);
                    panel.querySelector('dl').hidden = ! line;
                    panel.querySelector('[data-usage]').hidden = ! line;

                    if (! line) {
                        return;
                    }

                    const text = {
                        bank: line.bank, company: line.company, currency: line.currency, payment: line.payment,
                        limit: money(line.limit, line.currency), available: money(line.available, line.currency),
                        rate: `${line.rate.toFixed(4)}% + ${line.spread.toFixed(4)}% spread`,
                    };
                    Object.entries(text).forEach(([key, value]) => { panel.querySelector(`[data-info="${key}"]`).textContent = value; });

                    const usedShare = line.limit > 0 ? Math.min(100, (1 - line.available / line.limit) * 100) : 0;
                    panel.querySelector('[data-usage] span').style.width = `${usedShare}%`;

                    if (isNew) {
                        if (rate && ! rate.dataset.touched) { rate.value = line.rate; }
                        if (spread && ! spread.dataset.touched) { spread.value = line.spread; }
                    }
                };

                select.addEventListener('change', render);
                render();
            })();
        </script>
    @endisset
@endsection
