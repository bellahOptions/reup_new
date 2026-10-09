@php
    $user = auth()->user();
    $walletBalance = $user?->wallet?->balance ?? 0;

    // Single source of truth for the primary nav. `active` is matched with
    // routeIs() so sub-pages (history, success, etc.) keep the parent lit.
    $primaryNav = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => 'dashboard', 'icon' => 'gauge'],
        ['label' => 'Airtime & Data', 'route' => 'airtime-data.index', 'active' => 'airtime-data.*', 'icon' => 'device-phone-mobile'],
        ['label' => 'Cable TV', 'route' => 'cable-tv.index', 'active' => 'cable-tv.*', 'icon' => 'tv'],
        ['label' => 'Electricity', 'route' => 'electricity.index', 'active' => 'electricity.*', 'icon' => 'bolt'],
    ];

    $moreNav = [
        ['label' => 'WAEC e-PIN', 'route' => 'waec-pin.index', 'active' => 'waec-pin.*', 'icon' => 'document-check', 'description' => 'Verification & registration scratch cards'],
        ['label' => 'JAMB e-PIN', 'route' => 'jamb-pin.index', 'active' => 'jamb-pin.*', 'icon' => 'academic-cap', 'description' => 'UTME and Direct Entry PINs'],
        ['label' => 'Betting wallet', 'route' => 'betting.index', 'active' => 'betting.*', 'icon' => 'wallet', 'description' => 'Fund Bet9ja, SportyBet and others'],
        ['label' => 'Pricelist', 'route' => 'pricelist', 'active' => 'pricelist', 'icon' => 'receipt-percent', 'description' => 'Current rates across all networks'],
        ['label' => 'Transactions', 'route' => 'transactions.index', 'active' => 'transactions.*', 'icon' => 'queue-list', 'description' => 'Full history of your activity'],
        ['label' => 'Refer and earn', 'route' => 'affiliate.index', 'active' => 'affiliate.*', 'icon' => 'users', 'description' => 'Earn ₦200 for every funded referral'],
    ];

    /*
     * Support is an external WhatsApp link, not a route, so it is kept out of
     * `$moreNav` (which the mobile drawer resolves through route()) and given
     * its own item. Omitted entirely when no number is configured.
     */
    $whatsappUrl = config('services.support.whatsapp_url');

    $isMoreActive = collect($moreNav)->contains(fn ($item) => request()->routeIs($item['active']));
@endphp

<header
    x-data="{ mobileOpen: false, moreOpen: false }"
    @keydown.escape.window="mobileOpen = false; moreOpen = false"
    class="sticky top-0 z-40 border-b border-border bg-surface/95 backdrop-blur supports-[backdrop-filter]:bg-surface/80"
>
    <div class="container-page">
        <div class="flex h-16 items-center justify-between gap-4">

            {{-- Brand --}}
            {{-- Points at the public home page for guests: /dashboard is behind
                 `auth`, so sending a signed-out visitor there just bounced them
                 through a redirect. --}}
            <a href="{{ auth()->check() ? route('dashboard') : route('home') }}" class="flex shrink-0 items-center gap-2">
                <img src="{{ asset('images/reup-03.svg') }}" alt="ReUp" class="h-7 w-auto">
            </a>

            {{--
                Product navigation is for signed-in users only.

                These routes all sit behind `auth`, so rendering them to a
                signed-out visitor produced a row of links that silently
                redirected to the login page — it looked like the site was
                broken rather than locked. Guests get the brand, and the
                Sign in / Get started buttons in the right cluster.
            --}}
            @auth
            <nav class="hidden items-center gap-0.5 lg:flex" aria-label="Primary">
                @foreach($primaryNav as $item)
                    <a href="{{ route($item['route']) }}"
                       @if(request()->routeIs($item['active'])) aria-current="page" @endif
                       class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors
                              {{ request()->routeIs($item['active'])
                                    ? 'bg-brand-50 text-brand-700'
                                    : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">
                        <x-icon :name="$item['icon']" class="h-4 w-4" />
                        {{ $item['label'] }}
                    </a>
                @endforeach

                {{-- "More" overflow --}}
                <div class="relative">
                    <button type="button"
                            @click="moreOpen = !moreOpen"
                            @click.outside="moreOpen = false"
                            :aria-expanded="moreOpen ? 'true' : 'false'"
                            aria-haspopup="true"
                            class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors
                                   {{ $isMoreActive ? 'bg-brand-50 text-brand-700' : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">
                        More
                        <x-icon name="chevron-down" class="h-3.5 w-3.5 transition-transform" ::class="moreOpen && 'rotate-180'" />
                    </button>

                    <div x-show="moreOpen" x-cloak x-transition.origin.top.right
                         class="absolute right-0 mt-2 w-80 overflow-hidden rounded-xl border border-border bg-surface shadow-overlay">
                        <div class="p-1.5">
                            @foreach($moreNav as $item)
                                <a href="{{ route($item['route']) }}"
                                   class="flex items-start gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-ink-100">
                                    <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-600">
                                        <x-icon :name="$item['icon']" class="h-4 w-4" />
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-ink-900">{{ $item['label'] }}</span>
                                        <span class="block text-xs text-muted-foreground">{{ $item['description'] }}</span>
                                    </span>
                                </a>
                            @endforeach

                            @if($whatsappUrl)
                                <a href="{{ $whatsappUrl }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="flex items-start gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-ink-100">
                                    <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-[#128C7E]">
                                        <x-icon name="whatsapp" variant="solid" class="h-4 w-4" />
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-ink-900">Support on WhatsApp</span>
                                        <span class="block text-xs text-muted-foreground">Message us, opens in WhatsApp</span>
                                    </span>
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </nav>
            @endauth

            {{-- Right cluster --}}
            <div class="flex items-center gap-2">
                {{-- Appearance. Rendered for guests as well as members: a
                     signed-out visitor has just as much right to a dark page,
                     and their choice is kept on the device until they have an
                     account to keep it on. --}}
                <x-theme-switch />

                @auth
                    {{-- Wallet balance --}}
                    <a href="{{ route('wallet.index') }}"
                       class="hidden items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm transition-colors hover:bg-surface-subtle sm:inline-flex">
                        <x-icon name="wallet" class="h-4 w-4 text-brand-600" />
                        <span class="font-semibold tabular-nums">&#8358;{{ number_format($walletBalance, 2) }}</span>
                    </a>

                    <a href="{{ route('wallet.fund') }}" class="btn btn-primary btn-sm">
                        <x-icon name="plus" class="h-4 w-4" />
                        <span class="hidden sm:inline">Fund</span>
                    </a>

                    {{-- Account menu --}}
                    <div class="relative" x-data="{ open: false }">
                        <button type="button"
                                @click="open = !open"
                                @click.outside="open = false"
                                :aria-expanded="open ? 'true' : 'false'"
                                aria-haspopup="true"
                                class="inline-flex items-center justify-center rounded-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                                aria-label="Account menu">
                            <x-avatar :user="$user" size="sm" />
                        </button>

                        <div x-show="open" x-cloak x-transition.origin.top.right
                             class="absolute right-0 mt-2 w-56 overflow-hidden rounded-xl border border-border bg-surface shadow-overlay">
                            <div class="border-b px-3 py-2.5">
                                <p class="truncate text-sm font-medium text-ink-900">{{ $user->name }}</p>
                                <p class="truncate text-xs text-muted-foreground">{{ $user->email }}</p>
                            </div>
                            <div class="p-1.5">
                                <a href="{{ route('profile.index') }}" class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-ink-700 hover:bg-ink-100">
                                    <x-icon name="user" class="h-4 w-4 text-ink-500" /> Profile
                                </a>
                                <a href="{{ route('wallet.history') }}" class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-ink-700 hover:bg-ink-100">
                                    <x-icon name="queue-list" class="h-4 w-4 text-ink-500" /> Wallet history
                                </a>
                                <a href="{{ route('contact') }}" class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-ink-700 hover:bg-ink-100">
                                    <x-icon name="lifebuoy" class="h-4 w-4 text-ink-500" /> Support
                                </a>

                                @if($user->isAdmin())
                                    <div class="my-1.5 h-px bg-border"></div>
                                    <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-ink-700 hover:bg-ink-100">
                                        <x-icon name="shield-check" class="h-4 w-4 text-ink-500" /> Admin console
                                    </a>
                                @endif

                                <div class="my-1.5 h-px bg-border"></div>
                                <form method="POST" action="{{ route('logout', [], false) }}">
                                    @csrf
                                    <button type="submit" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50">
                                        <x-icon name="arrow-right-on-rectangle" class="h-4 w-4" /> Log out
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @else
                    <a href="{{ route('login') }}" class="btn btn-ghost btn-sm">Sign in</a>
                    <a href="{{ route('register') }}" class="btn btn-primary btn-sm">Get started</a>
                @endauth

                {{-- Mobile trigger --}}
                <button type="button"
                        @click="mobileOpen = !mobileOpen"
                        :aria-expanded="mobileOpen ? 'true' : 'false'"
                        aria-controls="mobile-nav"
                        class="btn btn-ghost btn-icon lg:hidden"
                        aria-label="Toggle navigation">
                    <x-icon name="bars-3" class="h-5 w-5" x-show="!mobileOpen" />
                    <x-icon name="x-mark" class="h-5 w-5" x-show="mobileOpen" x-cloak />
                </button>
            </div>
        </div>
    </div>

    {{-- Mobile navigation. Signed-in only, for the same reason as the desktop
         nav: every one of these routes is behind `auth`, so a guest tapping one
         just landed on the login page. --}}
    @auth
    <div id="mobile-nav" x-show="mobileOpen" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="border-t bg-surface lg:hidden">
        <div class="container-page space-y-1 py-3">
            @foreach(array_merge($primaryNav, $moreNav) as $item)
                <a href="{{ route($item['route']) }}"
                   class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium
                          {{ request()->routeIs($item['active']) ? 'bg-brand-50 text-brand-700' : 'text-ink-700 hover:bg-ink-100' }}">
                    <x-icon :name="$item['icon']" class="h-4 w-4" />
                    {{ $item['label'] }}
                </a>
            @endforeach

            @if($whatsappUrl)
                <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer"
                   class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-700 hover:bg-ink-100">
                    <x-icon name="whatsapp" variant="solid" class="h-4 w-4 text-[#128C7E]" />
                    Support on WhatsApp
                </a>
            @endif
        </div>
    </div>
    @endauth
</header>
