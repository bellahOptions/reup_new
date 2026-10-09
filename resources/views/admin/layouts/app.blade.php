<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" {!! $themeState->attributes() !!}>
<head>
    @include('partials.head')

    @hasSection('title')
        <title>@yield('title') — Admin · {{ config('app.name', 'ReUp') }}</title>
    @else
        <title>Admin · {{ config('app.name', 'ReUp') }}</title>
    @endif

    {{-- Admin console is never indexable. --}}
    <meta name="robots" content="noindex, nofollow">

    @stack('styles')
</head>
<body class="min-h-screen bg-surface"
      x-data="{
          sidebarOpen: false,
          collapsed: JSON.parse(localStorage.getItem('admin.sidebar.collapsed') ?? 'false'),
          toggleCollapsed() {
              this.collapsed = !this.collapsed;
              localStorage.setItem('admin.sidebar.collapsed', JSON.stringify(this.collapsed));
          }
      }"
      @keydown.escape.window="sidebarOpen = false">

    @php
        $admin = auth()->user();

        // Grouped navigation. `permission` mirroring the route middleware keeps
        // the menu honest: an item only renders if the route would accept it.
        $navGroups = [
            [
                'label' => null,
                'items' => [
                    ['Dashboard', 'admin.dashboard', 'admin.dashboard', 'gauge', null, null],
                    ['Transactions', 'admin.transactions.index', 'admin.transactions.*', 'queue-list', 'view_transactions', null],
                ],
            ],
            [
                'label' => 'Money',
                'items' => [
                    ['Bank transfers', 'admin.bank-transfers.index', 'admin.bank-transfers.*', 'building-library', 'manage_wallets',
                        $pendingTransfers ?? 0],
                    ['Customers', 'admin.users.index', 'admin.users.*', 'users', 'manage_users', null],
                ],
            ],
            [
                'label' => 'Support',
                'items' => [
                    ['Live chat', 'admin.chat.index', 'admin.chat.*', 'chat-bubble-left-right', 'chat', $pendingChats ?? 0],
                    ['Messages', 'admin.contact.index', 'admin.contact.*', 'envelope', 'view_contacts', $unreadContacts ?? 0],
                ],
            ],
            [
                'label' => 'Content',
                'items' => [
                    ['Announcements', 'admin.announcement.index', 'admin.announcement.*', 'megaphone', 'manage_settings', null],
                    ['Legal documents', 'admin.terms.index', 'admin.terms.*', 'document-text', 'manage_settings', null],
                    ['Settings', 'admin.settings.index', 'admin.settings.*', 'cog-6-tooth', 'manage_settings', null],
                ],
            ],
            [
                'label' => 'Administration',
                'items' => [
                    ['Administrators', 'admin.admins.index', 'admin.admins.*', 'shield-check', 'manage_admins', null],
                ],
            ],
        ];

        // Every row carries six slots. Destructuring a five-element row threw
        // "Undefined array key 5" on any admin page, because only the rows with
        // a badge count were written with a trailing element.
        $navGroups = array_map(function (array $group) {
            $group['items'] = array_map(
                fn (array $item) => array_pad($item, 6, null),
                $group['items']
            );

            return $group;
        }, $navGroups);
    @endphp

    <div class="flex min-h-screen">

        {{-- ============================ Sidebar ============================ --}}
        <aside
            id="admin-sidebar"
            class="fixed inset-y-0 left-0 z-50 flex flex-col border-r border-border bg-surface transition-[width,transform] duration-200 ease-out"
            :class="[
                collapsed ? 'lg:w-[68px]' : 'lg:w-64',
                'w-64',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
            ]">

            {{-- Brand --}}
            <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-border px-4">
                <img src="{{ asset('images/reup-03.svg') }}" alt="ReUp" class="h-6 w-auto shrink-0">
                <div x-show="!collapsed" x-cloak class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold leading-tight">{{ config('app.name', 'ReUp') }}</p>
                    <p class="truncate text-xs text-muted-foreground">Admin console</p>
                </div>
            </div>

            {{-- Navigation --}}
            <nav class="scrollbar-slim flex-1 overflow-y-auto px-3 py-4" aria-label="Admin">
                @foreach($navGroups as $group)
                    @php
                        $visible = array_values(array_filter(
                            $group['items'],
                            fn ($item) => $item[4] === null || $admin->hasPermission($item[4])
                        ));
                    @endphp

                    @if(count($visible))
                        <div class="mb-5 last:mb-0">
                            @if($group['label'])
                                <p x-show="!collapsed" x-cloak
                                   class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-400">
                                    {{ $group['label'] }}
                                </p>
                                <div x-show="collapsed" x-cloak class="mx-3 mb-2 h-px bg-border"></div>
                            @endif

                            <ul class="space-y-0.5">
                                @foreach($visible as [$label, $route, $pattern, $icon, $permission, $badge])
                                    @php $active = request()->routeIs($pattern); @endphp
                                    <li>
                                        <a href="{{ route($route) }}"
                                           @if($active) aria-current="page" @endif
                                           title="{{ $label }}"
                                           class="sidebar-link {{ $active ? 'sidebar-link-active' : '' }}"
                                           :class="collapsed && 'lg:justify-center lg:px-0'">
                                            <x-icon :name="$icon" class="h-[18px] w-[18px] shrink-0" />
                                            <span x-show="!collapsed" x-cloak class="flex-1 truncate">{{ $label }}</span>
                                            @if($badge)
                                                <span x-show="!collapsed" x-cloak
                                                      class="ml-auto rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold leading-4 text-white tabular-nums">
                                                    {{ $badge > 99 ? '99+' : $badge }}
                                                </span>
                                            @endif
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endforeach
            </nav>

            {{-- Account --}}
            <div class="shrink-0 border-t border-border p-3">
                <div class="flex items-center gap-2.5 rounded-lg px-2 py-1.5"
                     :class="collapsed && 'lg:justify-center lg:px-0'">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-500 text-xs font-semibold text-primary-foreground">
                        {{ strtoupper(substr($admin->name ?? 'A', 0, 1)) }}
                    </span>
                    <div x-show="!collapsed" x-cloak class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium leading-tight">{{ $admin->name }}</p>
                        <p class="truncate text-xs text-muted-foreground">{{ $admin->admin_role }}</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.logout', [], false) }}" class="mt-1">
                    @csrf
                    <button type="submit"
                            class="sidebar-link w-full text-red-600 hover:bg-red-50 hover:text-red-700"
                            :class="collapsed && 'lg:justify-center lg:px-0'"
                            title="Sign out">
                        <x-icon name="arrow-right-on-rectangle" class="h-[18px] w-[18px] shrink-0" />
                        <span x-show="!collapsed" x-cloak>Sign out</span>
                    </button>
                </form>
            </div>
        </aside>

        {{-- Mobile scrim --}}
        <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"
             x-transition.opacity
             class="fixed inset-0 z-40 bg-ink-950/40 lg:hidden"></div>

        {{-- ============================ Main ============================== --}}
        <div class="flex min-w-0 flex-1 flex-col transition-[margin] duration-200 ease-out"
             :class="collapsed ? 'lg:ml-[68px]' : 'lg:ml-64'">

            <header class="sticky top-0 z-30 border-b border-border bg-surface/95 backdrop-blur supports-[backdrop-filter]:bg-surface/80">
                <div class="flex h-16 items-center gap-3 px-4 sm:px-6">
                    <button type="button" @click="sidebarOpen = true"
                            class="btn btn-ghost btn-icon lg:hidden" aria-label="Open navigation">
                        <x-icon name="bars-3" class="h-5 w-5" />
                    </button>

                    <button type="button" @click="toggleCollapsed()"
                            class="btn btn-ghost btn-icon hidden lg:inline-flex"
                            :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'">
                        <x-icon name="bars-3-bottom-left" class="h-5 w-5" />
                    </button>

                    <div class="min-w-0 flex-1">
                        <h1 class="truncate text-base font-semibold tracking-tight">
                            @yield('page-title', 'Dashboard')
                        </h1>
                        @hasSection('page-description')
                            <p class="truncate text-xs text-muted-foreground">@yield('page-description')</p>
                        @endif
                    </div>

                    <div class="flex items-center gap-1.5">
                        @hasSection('page-actions')
                            <div class="hidden items-center gap-2 sm:flex">@yield('page-actions')</div>
                        @endif

                        {{-- Notifications --}}
                        <div class="relative" x-data="{ open: false }">
                            <button type="button" @click="open = !open" @click.outside="open = false"
                                    class="btn btn-ghost btn-icon relative"
                                    :aria-expanded="open ? 'true' : 'false'"
                                    aria-label="Notifications">
                                <x-icon name="bell" class="h-5 w-5" />
                                <span id="notificationBadge"
                                      class="absolute right-1.5 top-1.5 hidden h-2 w-2 rounded-full bg-red-500 ring-2 ring-white"></span>
                            </button>

                            <div x-show="open" x-cloak x-transition.origin.top.right
                                 class="absolute right-0 mt-2 w-[22rem] overflow-hidden rounded-xl border border-border bg-surface shadow-overlay">
                                <div class="flex items-center justify-between border-b px-4 py-2.5">
                                    <p class="text-sm font-semibold">Notifications</p>
                                    <button type="button" onclick="markAllAsRead()"
                                            class="text-xs font-medium text-brand-600 hover:text-brand-700">
                                        Mark all read
                                    </button>
                                </div>

                                <div class="grid grid-cols-2 divide-x border-b bg-surface text-xs">
                                    <div class="flex items-center justify-between px-4 py-2">
                                        <span class="flex items-center gap-1.5 text-muted-foreground">
                                            <x-icon name="chat-bubble-left-right" class="h-3.5 w-3.5" /> Chats
                                        </span>
                                        <span id="chatCount" class="font-semibold tabular-nums">0</span>
                                    </div>
                                    <div class="flex items-center justify-between px-4 py-2">
                                        <span class="flex items-center gap-1.5 text-muted-foreground">
                                            <x-icon name="envelope" class="h-3.5 w-3.5" /> Messages
                                        </span>
                                        <span id="contactCount" class="font-semibold tabular-nums">0</span>
                                    </div>
                                </div>

                                <div id="notificationList" class="scrollbar-slim max-h-80 overflow-y-auto"></div>

                                <div class="flex items-center justify-between border-t bg-surface px-4 py-2.5">
                                    <a href="{{ route('admin.chat.index') }}" class="link text-xs font-medium">All chats</a>
                                    <a href="{{ route('admin.contact.index') }}" class="link text-xs font-medium">All messages</a>
                                </div>
                            </div>
                        </div>

                        {{-- Account --}}
                        <div class="relative" x-data="{ open: false }">
                            <button type="button" @click="open = !open" @click.outside="open = false"
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-ink-900 text-xs font-semibold text-white"
                                    :aria-expanded="open ? 'true' : 'false'"
                                    aria-label="Account menu">
                                {{ strtoupper(substr($admin->name ?? 'A', 0, 1)) }}
                            </button>

                            <div x-show="open" x-cloak x-transition.origin.top.right
                                 class="absolute right-0 mt-2 w-56 overflow-hidden rounded-xl border border-border bg-surface shadow-overlay">
                                <div class="border-b px-3 py-2.5">
                                    <p class="truncate text-sm font-medium">{{ $admin->name }}</p>
                                    <p class="truncate text-xs text-muted-foreground">{{ $admin->email }}</p>
                                </div>
                                <div class="p-1.5">
                                    <a href="{{ route('dashboard') }}"
                                       class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-ink-700 hover:bg-ink-100">
                                        <x-icon name="arrow-left-on-rectangle" class="h-4 w-4 text-ink-500" />
                                        Customer site
                                    </a>
                                    @if($admin->hasPermission('manage_settings'))
                                        <a href="{{ route('admin.settings.index') }}"
                                           class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-ink-700 hover:bg-ink-100">
                                            <x-icon name="cog-6-tooth" class="h-4 w-4 text-ink-500" />
                                            Settings
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="flex-1 px-4 py-6 sm:px-6">
                @include('partials.flash')
                @yield('content')
            </main>

            <footer class="border-t border-border px-4 py-4 text-xs text-muted-foreground sm:px-6">
                <div class="flex flex-col items-center justify-between gap-1 sm:flex-row">
                    <p>&copy; {{ date('Y') }} {{ config('app.name', 'ReUp') }}. All rights reserved.</p>
                    <p>Operated by Bellah Options BN3668420</p>
                </div>
            </footer>
        </div>
    </div>

    @stack('scripts')

    <script>
        // ---------------------------------------------------------------
        // Notification polling
        // ---------------------------------------------------------------
        // Rewritten from the previous inline class:
        //   - the dropdown is now Alpine-driven, so this only fetches data;
        //   - the "new notification" tone used to fire on every poll because
        //     it only checked `total_unread > 0` rather than a change, which
        //     made the console beep every five seconds;
        //   - XSS: notification title/message were interpolated into innerHTML
        //     unescaped, so a chat message containing markup executed in the
        //     admin's session.
        (function () {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const list = document.getElementById('notificationList');
            const badge = document.getElementById('notificationBadge');
            const chatCount = document.getElementById('chatCount');
            const contactCount = document.getElementById('contactCount');

            let lastSeenTotal = null;
            let audioCtx = null;

            const escapeHtml = (value) => String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');

            function beep() {
                try {
                    audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
                    const osc = audioCtx.createOscillator();
                    const gain = audioCtx.createGain();
                    osc.connect(gain);
                    gain.connect(audioCtx.destination);
                    osc.frequency.value = 720;
                    osc.type = 'sine';
                    gain.gain.setValueAtTime(0.0001, audioCtx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.15, audioCtx.currentTime + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.35);
                    osc.start();
                    osc.stop(audioCtx.currentTime + 0.36);
                } catch (e) { /* audio is a nicety, never fatal */ }
            }

            function render(notifications) {
                if (!list) return;

                if (!notifications.length) {
                    list.innerHTML = `
                        <div class="empty-state py-10">
                            <svg class="h-8 w-8 text-ink-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/>
                            </svg>
                            <p class="mt-2 text-sm text-muted-foreground">You're all caught up.</p>
                        </div>`;
                    return;
                }

                list.innerHTML = notifications.map((n) => {
                    const url = escapeHtml(n.url || '#');
                    return `
                        <a href="${url}"
                           class="flex items-start gap-3 border-b border-ink-100 px-4 py-3 transition-colors last:border-0 hover:bg-ink-50"
                           data-mark-read="${escapeHtml(n.type)}" data-id="${escapeHtml(n.id)}">
                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="9"/>
                                </svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-ink-900">${escapeHtml(n.title)}</span>
                                <span class="mt-0.5 block text-xs text-muted-foreground">${escapeHtml(n.message)}</span>
                                <span class="mt-1 block text-[11px] text-ink-400">${escapeHtml(n.time)}</span>
                            </span>
                        </a>`;
                }).join('');

                list.querySelectorAll('[data-mark-read]').forEach((link) => {
                    link.addEventListener('click', () => {
                        markAsRead(link.dataset.markRead, link.dataset.id);
                    });
                });
            }

            function updateCounts(data) {
                if (chatCount && data.unread_chats !== undefined) chatCount.textContent = data.unread_chats;
                if (contactCount && data.unread_contacts !== undefined) contactCount.textContent = data.unread_contacts;

                const total = data.total_unread ?? 0;

                if (badge) badge.classList.toggle('hidden', total === 0);

                // Only alert when the number actually rises.
                if (lastSeenTotal !== null && total > lastSeenTotal) beep();
                lastSeenTotal = total;
            }

            async function load() {
                try {
                    const res = await fetch('{{ route('admin.notifications.unread') }}', {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    if (!res.ok) return;

                    const data = await res.json();
                    render(data.notifications || []);
                    updateCounts(data);
                } catch (e) { /* transient network failure — retry on next tick */ }
            }

            window.markAsRead = async function (type, id) {
                try {
                    await fetch('{{ route('admin.notifications.mark-read') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ type, id }),
                    });
                    load();
                } catch (e) { /* ignore */ }
            };

            window.markAllAsRead = async function () {
                try {
                    await fetch('{{ route('admin.notifications.mark-read') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        credentials: 'same-origin',
                    });
                    load();
                } catch (e) { /* ignore */ }
            };

            load();
            const timer = setInterval(load, 30000);
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) clearInterval(timer);
            });
        })();
    </script>
</body>
</html>
