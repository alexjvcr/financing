@extends('layouts.app')

@section('title', 'Iniciar sesión')

@section('content')
    <h1>Iniciar sesión</h1>
    <p class="subtitle">Use su usuario y contraseña de Active Directory ({{ config('ldap.netbios_domain') }}).</p>

    @if ($errors->any())
        <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="field">
            <label for="username">Usuario</label>
            <input id="username" name="username" type="text" value="{{ old('username') }}" autocomplete="username" autofocus required>
        </div>

        <div class="field">
            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>

        <button type="submit" class="btn btn-primary btn-lg">Ingresar</button>
    </form>
@endsection
