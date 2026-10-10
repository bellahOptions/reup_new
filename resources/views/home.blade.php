@extends('layouts.main')

@section('title', 'One wallet for everything digital')
@section('meta_description', 'Pay bills, buy data, get gift cards, top up international numbers, activate eSIMs and grow your social presence — all from one simple ReUp wallet.')

@section('main')
@php
    $services = [
        [
            'title' => 'Airtime',
            'copy' => 'Top up MTN, Glo, 9mobile and Airtel at discounted rates.',
            'icon' => 'device-phone-mobile',
            'route' => 'airtime-data.index',
            'cta' => 'Buy airtime',
        ],
        [
            'title' => 'Data bundles',
            'copy' => 'SME, corporate and direct data plans with instant delivery.',
            'icon' => 'signal',
            'route' => 'airtime-data.index',
            'cta' => 'Buy data',
        ],
        [
            'title' => 'Cable TV',
            'copy' => 'Renew DStv, GOtv and StarTimes subscriptions in one tap.',
            'icon' => 'tv',
            'route' => 'cable-tv.index',
            'cta' => 'Subscribe',
        ],
        [
            'title' => 'Electricity',
            'copy' => 'Buy prepaid tokens or settle postpaid bills for every disco.',
            'icon' => 'bolt',
            'route' => 'electricity.index',
            'cta' => 'Pay a bill',
        ],
        [
            'title' => 'WAEC e-PIN',
            'copy' => 'Verification and registration scratch cards, issued instantly.',
            'icon' => 'document-check',
            'route' => 'waec-pin.index',
            'cta' => 'Get a PIN',
        ],
        [
            'title' => 'JAMB e-PIN',
            'copy' => 'UTME and Direct Entry PINs delivered to your dashboard.',
            'icon' => 'academic-cap',
            'route' => 'jamb-pin.index',
            'cta' => 'Get a PIN',
        ],
    ];

    $steps = [
        ['Create an account', 'Sign up with your email in under a minute. No paperwork.', 'user-plus'],
        ['Fund your wallet', 'Pay by card or direct bank transfer. Funds land instantly.', 'credit-card'],
        ['Pay for anything', 'Buy airtime, data, TV, power or PINs from a single balance.', 'bolt'],
    ];
@endphp

{{-- ============================ Hero ============================ --}}
<section class="border-b border-border">
    <div class="container-page py-20 sm:py-28 lg:py-32">
        <div class="grid items-center gap-14 lg:grid-cols-12 lg:gap-16">

            <div class="lg:col-span-6">

                {{-- §51.1 approved copy. The headline and supporting text come from
                     config/copy.php so the approved deck has exactly one source, and the
                     CTAs use the approved labels ("Get Started" / "Sign In") rather than
                     a variant invented here. --}}
                <h1 class="mt-4 text-4xl font-semibold leading-[1.08] tracking-tight text-ink-950 sm:text-5xl lg:text-6xl">
                    {{ \App\Support\UiCopy::get('brand.tagline') }}
                </h1>

                <p class="mt-6 max-w-xl text-lg leading-relaxed text-muted-foreground">
                    {{ \App\Support\UiCopy::get('brand.supporting') }}
                </p>

                <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('register') }}" class="btn btn-primary btn-lg">
                        {{ \App\Support\UiCopy::get('actions.get_started') }}
                        <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                    <a href="{{ route('login') }}" class="btn btn-outline btn-lg">
                        {{ \App\Support\UiCopy::get('actions.sign_in') }}
                    </a>
                </div>

                <dl class="mt-12 grid max-w-lg grid-cols-3 gap-6 border-t border-border pt-8">
                    <div>
                        <dt class="text-xs text-muted-foreground">Settlement</dt>
                        <dd class="mt-1 text-lg font-semibold">Instant</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Availability</dt>
                        <dd class="mt-1 text-lg font-semibold">24 / 7</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Support</dt>
                        <dd class="mt-1 text-lg font-semibold">In-app</dd>
                    </div>
                </dl>
            </div>

            {{-- Product surface: a quiet mock of the dashboard wallet card --}}
            <div class="lg:col-span-6">
                <div class="relative mx-auto max-w-md">
                    <div class="card overflow-hidden shadow-overlay">
                        {{-- Inverse panel — flips with the theme. --}}
                        <div class="bg-inverse-surface px-6 py-7">
                            <div class="flex items-center justify-between">
                                <p class="text-xs font-medium uppercase tracking-[0.14em] text-inverse-subtle">Wallet balance</p>
                                <x-icon name="wallet" class="h-5 w-5 text-brand-400" />
                            </div>
                            <p class="mt-3 text-3xl font-semibold tracking-tight text-inverse-foreground tabular-nums">
                                &#8358;48,250.00
                            </p>
                            <div class="mt-6 flex gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-inverse-overlay px-3 py-1.5 text-xs font-medium text-inverse-muted">
                                    <x-icon name="plus" class="h-3.5 w-3.5" /> Fund wallet
                                </span>
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-inverse-overlay px-3 py-1.5 text-xs font-medium text-inverse-muted">
                                    <x-icon name="arrow-up-right" class="h-3.5 w-3.5" /> Send
                                </span>
                            </div>
                        </div>

                        <ul class="divide-y divide-border">
                            @foreach([
                                ['Airtime — MTN', '0803 ••• 4471', '− ₦2,000.00', 'bolt'],
                                ['Data — 10GB SME', '0806 ••• 1180', '− ₦3,400.00', 'signal'],
                                ['Wallet funding', 'Card • 4242', '+ ₦20,000.00', 'credit-card'],
                            ] as [$title, $meta, $amount, $icon])
                                <li class="flex items-center gap-3 px-5 py-3.5">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-600">
                                        <x-icon :name="$icon" class="h-4 w-4" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium">{{ $title }}</p>
                                        <p class="truncate text-xs text-muted-foreground">{{ $meta }}</p>
                                    </div>
                                    <span class="shrink-0 text-sm font-medium tabular-nums {{ str_starts_with($amount, '+') ? 'text-success-soft-foreground' : 'text-ink-700' }}">
                                        {{ $amount }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="pointer-events-none absolute -bottom-5 -right-5 hidden rounded-xl border border-border bg-surface px-4 py-3 shadow-card sm:block">
                        <div class="flex items-center gap-2">
                            <x-icon name="shield-check" variant="solid" class="h-5 w-5 text-brand-500" />
                            <div>
                                <p class="text-xs font-semibold">Encrypted</p>
                                <p class="text-[11px] text-muted-foreground">PCI-DSS gateway</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================ Announcements ============================ --}}
@if(isset($announcements) && $announcements->count() > 0)
    <section class="border-b border-border bg-surface">
        <div class="container-page flex items-center gap-4 py-4">
            <span class="flex shrink-0 items-center gap-2 text-sm font-semibold text-brand-700">
                <x-icon name="megaphone" class="h-4 w-4" />
                Announcements
            </span>
            <div class="marquee-container min-w-0 flex-1 overflow-hidden">
                <x-marquee :items="$announcements" :speed="38" compact />
            </div>
        </div>
    </section>
@endif

{{-- ============================ Services ============================ --}}
<section id="services" class="border-b border-border">
    <div class="container-page py-20 sm:py-24">
        <div class="max-w-2xl">
            <h2 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">
                Everything on one balance
            </h2>
            <p class="mt-4 text-lg text-muted-foreground">
                Fund once, then spend across every service. Each purchase settles against
                your wallet and appears in your history immediately.
            </p>
        </div>

        <div class="mt-14 grid gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-2 lg:grid-cols-3">
            @foreach($services as $service)
                <a href="{{ route($service['route']) }}"
                   class="group flex flex-col bg-surface p-6 transition-colors hover:bg-surface sm:p-7">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-accent text-brand-600 transition-colors group-hover:bg-brand-100">
                        <x-icon :name="$service['icon']" class="h-5 w-5" />
                    </span>

                    <h3 class="mt-5 text-base font-semibold">{{ $service['title'] }}</h3>
                    <p class="mt-2 flex-1 text-sm leading-relaxed text-muted-foreground">{{ $service['copy'] }}</p>

                    <span class="mt-5 inline-flex items-center gap-1.5 text-sm font-medium text-brand-700">
                        {{ $service['cta'] }}
                        <x-icon name="arrow-right" class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" />
                    </span>
                </a>
            @endforeach
        </div>
    </div>
</section>

{{-- ============================ How it works ============================ --}}
<section class="border-b border-border bg-surface">
    <div class="container-page py-20 sm:py-24">
        <div class="max-w-2xl">
            <h2 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">
                Three steps, start to finish
            </h2>
        </div>

        <ol class="mt-14 grid gap-10 sm:grid-cols-3 sm:gap-8">
            @foreach($steps as $index => [$title, $copy, $icon])
                <li class="relative">
                    <div class="flex items-center gap-4">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-border bg-surface text-brand-600">
                            <x-icon :name="$icon" class="h-5 w-5" />
                        </span>
                        <span class="font-mono text-xs text-ink-400">0{{ $index + 1 }}</span>
                    </div>
                    <h3 class="mt-5 text-base font-semibold">{{ $title }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">{{ $copy }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>

{{-- ============================ Why ReUp ============================ --}}
<section class="border-b border-border">
    <div class="container-page py-20 sm:py-24">
        <div class="grid gap-14 lg:grid-cols-12 lg:gap-16">
            <div class="lg:col-span-5">
                <h2 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">
                    Built to be boringly reliable
                </h2>
                <p class="mt-4 text-lg text-muted-foreground">
                    Payments should not be exciting. They should just work — at 2am on a
                    Sunday, on a slow connection, the first time.
                </p>
            </div>

            <div class="lg:col-span-7">
                <dl class="grid gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-2">
                    @foreach([
                        ['Secure by default', 'Every session is encrypted and every payment verified against the gateway before your wallet moves.', 'lock-closed'],
                        ['Clear pricing', 'What you see on the pricelist is what gets charged. Fees are itemised on every receipt.', 'receipt-percent'],
                        ['Full history', 'Every kobo in and out, searchable and exportable from your dashboard.', 'queue-list'],
                        ['Human support', 'Live chat with a real agent, plus email for anything that needs a paper trail.', 'lifebuoy'],
                    ] as [$title, $copy, $icon])
                        <div class="bg-surface p-6">
                            <x-icon :name="$icon" class="h-5 w-5 text-brand-600" />
                            <dt class="mt-4 text-base font-semibold">{{ $title }}</dt>
                            <dd class="mt-2 text-sm leading-relaxed text-muted-foreground">{{ $copy }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </div>
</section>

{{-- ============================ Final CTA ============================ --}}
{{-- An inverse panel: dark in light mode, and flipped in dark mode so it keeps
     standing out against the page rather than merging into it. Its text comes
     from the `inverse-*` tokens rather than the ink scale, because the ink steps
     that read correctly on this panel in light mode would be near-black on it in
     dark mode. --}}
<section class="bg-inverse-surface">
    <div class="container-page py-20 sm:py-24">
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="text-3xl font-semibold tracking-tight text-inverse-foreground sm:text-4xl">
                Open a wallet in a minute
            </h2>
            <p class="mt-4 text-lg leading-relaxed text-inverse-subtle">
                No credit checks, no minimum balance and no monthly fee. Fund what you
                need, when you need it.
            </p>

            <div class="mt-9 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ route('register') }}" class="btn btn-primary btn-lg">
                    Create a free account
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
                <a href="{{ route('contact') }}"
                   class="btn btn-lg border border-inverse-line bg-transparent text-inverse-foreground hover:bg-inverse-overlay">
                    Talk to us
                </a>
            </div>

            <p class="mt-6 text-xs text-inverse-subtle">
                Already registered? <a href="{{ route('login') }}" class="font-medium text-inverse-muted underline underline-offset-4 hover:text-inverse-foreground">Sign in</a>
            </p>
        </div>
    </div>
</section>
@endsection
