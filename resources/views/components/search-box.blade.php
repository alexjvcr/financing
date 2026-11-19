@props(['action', 'term' => '', 'placeholder' => 'Buscar…', 'title' => 'Buscar'])

<section class="card search-box">
    <h2 class="card-title"><x-icon name="search" /> {{ $title }}</h2>

    <form method="GET" action="{{ $action }}" class="search-form">
        <div class="search-field">
            <label for="q">Texto a buscar</label>
            <div class="input-wrap">
                <span class="affix"><x-icon name="search" class="icon-small" /></span>
                <input id="q" type="search" name="q" value="{{ $term }}" placeholder="{{ $placeholder }}" autocomplete="off">
            </div>
        </div>

        {{ $slot }}

        <div class="search-actions">
            <button type="submit" class="btn btn-primary"><x-icon name="search" /> Buscar</button>
            <a href="{{ $action }}" class="btn"><x-icon name="x" /> Limpiar</a>
        </div>
    </form>
</section>
