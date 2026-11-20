<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
        <script>
            document.documentElement.dataset.theme = localStorage.getItem('theme')
                ?? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        </script>
        <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
        <link rel="stylesheet" href="{{ asset('css/theme.css') }}">
    </head>
    <body>
        <div class="app-shell">
            <aside class="sidebar" id="sidebar">
                <div class="sidebar-brand">
                    <span class="brand-mark">F</span>
                    <span class="brand-name">{{ config('app.name') }}</span>
                </div>

                <nav class="sidebar-nav" aria-label="Menú principal">
                    <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'is-active' : '' }}">
                        <x-icon name="dashboard" /> Dashboard
                    </a>
                    <a href="{{ route('prestamos.index') }}" class="nav-link {{ request()->routeIs('prestamos.*', 'recibos.anular', 'revisiones.*') ? 'is-active' : '' }}">
                        <x-icon name="banknote" /> Sub Préstamo
                    </a>
                    <a href="{{ route('recibos.index') }}" class="nav-link {{ request()->routeIs('recibos.index') ? 'is-active' : '' }}">
                        <x-icon name="file-text" /> Recibos
                    </a>
                    <a href="{{ route('anulaciones.index') }}" class="nav-link {{ request()->routeIs('anulaciones.index') ? 'is-active' : '' }}">
                        <x-icon name="x" /> Anulaciones
                    </a>

                    <p class="nav-section">Reportes</p>
                    @foreach ([
                        ['trending-up', 'Reportes'],
                        ['file-text', 'Estado de cuenta'],
                        ['clock', 'Alertas de vencimiento'],
                    ] as [$icon, $label])
                        <span class="nav-link is-disabled" aria-disabled="true" title="Disponible próximamente">
                            <x-icon :name="$icon" /> {{ $label }} <span class="nav-badge">Pronto</span>
                        </span>
                    @endforeach

                    <p class="nav-section">Configuración</p>
                    @foreach ([
                        ['empresas.*', 'empresas.index', 'building', 'Empresas'],
                        ['bancos.*', 'bancos.index', 'layers', 'Bancos'],
                        ['lineas.*', 'lineas.index', 'calendar', 'Líneas de crédito'],
                        ['tipos-tasa.*', 'tipos-tasa.index', 'activity', 'Tipos de tasa'],
                        ['tipos-pago.*', 'tipos-pago.index', 'dollar', 'Tipos de pago'],
                    ] as [$pattern, $routeName, $icon, $label])
                        <a href="{{ route($routeName) }}" class="nav-link {{ request()->routeIs($pattern) ? 'is-active' : '' }}">
                            <x-icon :name="$icon" /> {{ $label }}
                        </a>
                    @endforeach

                    <p class="nav-section">Administración</p>
                    @foreach ([
                        ['lock', 'Roles y permisos'],
                        ['eye', 'Auditoría'],
                    ] as [$icon, $label])
                        <span class="nav-link is-disabled" aria-disabled="true" title="Disponible próximamente">
                            <x-icon :name="$icon" /> {{ $label }} <span class="nav-badge">Pronto</span>
                        </span>
                    @endforeach
                </nav>

                <div class="sidebar-footer">
                    <div class="sidebar-user">
                        <span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                        <span class="sidebar-user-text">
                            <strong>{{ auth()->user()->name }}</strong>
                            <small>{{ auth()->user()->email }}</small>
                        </span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="nav-link logout-link"><x-icon name="logout" /> Cerrar sesión</button>
                    </form>
                </div>
            </aside>

            <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

            <div class="main">
                <header class="topbar">
                    <button type="button" class="icon-button" id="sidebar-toggle" aria-label="Mostrar u ocultar el menú" aria-controls="sidebar">
                        <x-icon name="menu" />
                    </button>

                    <h1 class="topbar-title">@yield('heading', 'Dashboard')</h1>

                    <div class="topbar-actions">
                        <button type="button" class="icon-button" id="theme-toggle" aria-label="Cambiar entre modo claro y oscuro">
                            <x-icon name="moon" class="icon-moon" />
                            <x-icon name="sun" class="icon-sun" />
                        </button>

                        <div class="user-menu" id="user-menu">
                            <button type="button" class="user-button" id="user-menu-button" aria-haspopup="true" aria-expanded="false">
                                <span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                                <span class="user-button-name">{{ auth()->user()->name }}</span>
                                <x-icon name="chevron-down" class="icon-small" />
                            </button>
                            <div class="user-dropdown" id="user-dropdown" hidden>
                                <div class="user-dropdown-header">
                                    <strong>{{ auth()->user()->name }}</strong>
                                    <small>{{ auth()->user()->email }}</small>
                                </div>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item"><x-icon name="logout" /> Cerrar sesión</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="content">
                    @if (session('status'))
                        <p class="alert alert-success" role="status">{{ session('status') }}</p>
                    @endif
                    @if ($errors->any())
                        <div class="alert alert-error" role="alert">
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @yield('content')
                </main>

                <footer class="page-footer">
                    © {{ now()->year }} {{ config('app.name') }}
                </footer>
            </div>
        </div>

        <dialog id="confirm-dialog" class="modal">
            <form method="dialog">
                <header class="modal-title"><x-icon name="alert" /> <span>Confirmar acción</span></header>
                <p class="modal-message" id="confirm-message"></p>
                <footer class="modal-actions">
                    <button type="submit" value="cancel" class="btn btn-lg"><x-icon name="x" /> Cancelar</button>
                    <button type="submit" value="ok" class="btn btn-primary btn-lg"><x-icon name="check" /> Aceptar</button>
                </footer>
            </form>
        </dialog>

        <script>
            const root = document.documentElement;
            const isDesktop = () => window.matchMedia('(min-width: 1024px)').matches;

            document.getElementById('theme-toggle').addEventListener('click', () => {
                const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
                root.dataset.theme = next;
                localStorage.setItem('theme', next);
            });

            const toggleSidebar = (open) => {
                const className = isDesktop() ? 'sidebar-collapsed' : 'sidebar-open';
                document.body.classList.toggle(className, open ?? undefined);
            };
            document.getElementById('sidebar-toggle').addEventListener('click', () => toggleSidebar());
            document.getElementById('sidebar-backdrop').addEventListener('click', () => document.body.classList.remove('sidebar-open'));

            const userMenu = document.getElementById('user-menu');
            const userMenuButton = document.getElementById('user-menu-button');
            const userDropdown = document.getElementById('user-dropdown');
            const setUserMenu = (open) => {
                userDropdown.hidden = !open;
                userMenuButton.setAttribute('aria-expanded', String(open));
            };
            userMenuButton.addEventListener('click', () => setUserMenu(userDropdown.hidden));
            document.addEventListener('click', (event) => {
                if (! userMenu.contains(event.target)) {
                    setUserMenu(false);
                }
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    setUserMenu(false);
                    document.body.classList.remove('sidebar-open');
                }
            });

            // Forms with data-confirm ask for confirmation in a dialog before they are submitted.
            const confirmDialog = document.getElementById('confirm-dialog');
            document.addEventListener('submit', (event) => {
                const form = event.target;
                const message = form.dataset.confirm;

                if (! message || form.dataset.confirmed) {
                    return;
                }

                event.preventDefault();
                document.getElementById('confirm-message').textContent = message;
                confirmDialog.returnValue = '';
                confirmDialog.addEventListener('close', () => {
                    if (confirmDialog.returnValue === 'ok') {
                        form.dataset.confirmed = '1';
                        form.requestSubmit();
                    }
                }, { once: true });
                confirmDialog.showModal();
            });

            // Tab bars: every [data-tabs] container switches its own [data-panel] sections.
            document.querySelectorAll('[data-tabs]').forEach((container) => {
                const tabs = container.querySelectorAll('.tab-bar .tab');
                const panels = container.querySelectorAll('[data-panel]');
                const show = (index) => {
                    tabs.forEach((tab) => {
                        const active = tab.dataset.tab === String(index);
                        tab.classList.toggle('is-active', active);
                        tab.setAttribute('aria-selected', active ? 'true' : 'false');
                    });
                    panels.forEach((panel) => { panel.hidden = panel.dataset.panel !== String(index); });
                };

                tabs.forEach((tab) => tab.addEventListener('click', () => show(tab.dataset.tab)));

                // A required field inside a hidden tab would block the submit silently: open its tab first.
                container.closest('body').addEventListener('invalid', (event) => {
                    const panel = event.target.closest('[data-panel]');
                    if (panel && container.contains(panel) && panel.hidden) {
                        show(panel.dataset.panel);
                    }
                }, true);
            });

            // Dates: the format comes from DATE_FORMAT (config/formats.php). Fields of the x-date-input
            // component get a typing mask, validation and a calendar picker; forms submit the typed text
            // and the server converts it to yyyy-mm-dd.
            const dateFormat = (() => {
                const settings = @json(\App\Support\DateFormat::forJavaScript());
                const separator = settings.separator;
                const separatorPattern = separator === '-' ? '\\-' : '/';
                const matcher = new RegExp('^' + settings.order.map((part) => (part === 'Y' ? '(\\d{4})' : '(\\d{1,2})')).join(separatorPattern) + '$');
                const pad = (number, size = 2) => String(number).padStart(size, '0');

                // Parse typed text into { d, m, Y } numbers, or null when it is not a real date.
                const parse = (text) => {
                    const match = matcher.exec(String(text).trim());
                    if (! match) {
                        return null;
                    }

                    const values = {};
                    settings.order.forEach((part, index) => { values[part] = Number(match[index + 1]); });
                    const date = new Date(values.Y, values.m - 1, values.d);

                    return date.getFullYear() === values.Y && date.getMonth() === values.m - 1 && date.getDate() === values.d ? values : null;
                };

                return {
                    ...settings,
                    parse,
                    // Typed text -> yyyy-mm-dd ('' when invalid).
                    toIso: (text) => {
                        const values = parse(text);

                        return values ? `${pad(values.Y, 4)}-${pad(values.m)}-${pad(values.d)}` : '';
                    },
                    // yyyy-mm-dd -> text in the configured format.
                    fromIso: (iso) => {
                        const [year, month, day] = String(iso).split('-');
                        const values = { d: day, m: month, Y: year };

                        return iso ? settings.order.map((part) => values[part]).join(separator) : '';
                    },
                    // Add the separator by itself once a part is complete.
                    mask: (raw, isDeleting) => {
                        let value = raw.replace(new RegExp('[^0-9' + separatorPattern + ']', 'g'), '').slice(0, 10);
                        const parts = value.split(separator);
                        const index = parts.length - 1;

                        if (! isDeleting && index < settings.order.length - 1 && parts[index].length === (settings.order[index] === 'Y' ? 4 : 2)) {
                            value += separator;
                        }

                        return value;
                    },
                };
            })();
            window.dateFormat = dateFormat;

            document.querySelectorAll('[data-date-input]').forEach((box) => {
                const text = box.querySelector('input[type="text"]');
                const picker = box.querySelector('[data-date-picker]');
                const native = box.querySelector('[data-date-native]');

                const validate = () => {
                    const value = text.value.trim();
                    text.setCustomValidity(value === '' || dateFormat.parse(value) ? '' : `Fecha no válida. Use el formato ${dateFormat.placeholder}.`);
                };

                text.addEventListener('input', (event) => {
                    text.value = dateFormat.mask(text.value, Boolean(event.inputType && event.inputType.startsWith('delete')));
                    validate();
                });
                text.addEventListener('change', validate);
                validate();

                if (picker && native) {
                    picker.addEventListener('click', () => {
                        native.value = dateFormat.toIso(text.value);

                        try {
                            native.showPicker();
                        } catch (error) {
                            native.focus();
                        }
                    });

                    native.addEventListener('change', () => {
                        text.value = dateFormat.fromIso(native.value);
                        validate();
                        text.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }
            });
        </script>
    </body>
</html>
