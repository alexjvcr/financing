<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', config('app.name'))</title>
        <script>
            document.documentElement.dataset.theme = localStorage.getItem('theme')
                ?? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        </script>
        <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
        <link rel="stylesheet" href="{{ asset('css/theme.css') }}">
        <style>
            body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--sap-nav); }
            .login-card { width: 100%; max-width: 26rem; margin: 1rem; padding: 0; overflow: hidden; border: 1px solid var(--sap-border); border-radius: 3px; background: var(--sap-panel); box-shadow: 0 20px 50px rgba(5, 20, 50, .5); }
            .login-brand { display: flex; align-items: center; gap: .85rem; padding: 1rem 1.4rem; border-bottom: 1px solid var(--sap-border); background: var(--sap-panel-head); }
            .login-brand .brand-mark { width: 2.6rem; height: 2.6rem; font-size: 1.3rem; }
            .login-brand strong { display: block; color: var(--sap-title); font-size: 1.2rem; font-style: italic; }
            .login-brand small { color: var(--text-muted); }
            .login-body { padding: 1.4rem; }
            .login-body h1 { margin: 0 0 .25rem; color: var(--sap-title); font-size: 1.35rem; }
            .login-body p.subtitle { margin: 0 0 1.25rem; color: var(--text-muted); font-size: .95rem; }
            .login-body label { display: block; margin-bottom: .3rem; color: var(--sap-title); font-size: 1rem; font-weight: 600; }
            .login-body .field { margin-bottom: 1rem; }
            .login-body input { width: 100%; min-height: 2.9rem; padding: .5rem .75rem; border: 1px solid var(--sap-field-border); border-radius: 2px; background: #fff; color: #1b2433; font-size: 1.05rem; }
            [data-theme='dark'] .login-body input { background: #0d1424; color: var(--text); }
            .login-body input:focus { outline: 2px solid color-mix(in srgb, var(--primary) 35%, transparent); border-color: var(--sap-title); }
            .login-body .btn { width: 100%; margin-top: .5rem; }
            .login-body .alert { margin-bottom: 1rem; }
        </style>
    </head>
    <body>
        <main class="login-card">
            <div class="login-brand">
                <span class="brand-mark">F</span>
                <div>
                    <strong>{{ config('app.name') }}</strong>
                    <small>Préstamos pasivos y líneas de crédito</small>
                </div>
            </div>
            <div class="login-body">
                @yield('content')
            </div>
        </main>
    </body>
</html>
