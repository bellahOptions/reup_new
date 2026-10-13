@extends('layouts.app')
@section('title', 'Social Boost help')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Social Boost — help
    |--------------------------------------------------------------------------
    | Written around the four things customers actually contact support about:
    | what link to paste for their platform, why an order has not finished, why
    | an order failed, and what a refill does.
    |
    | The per-platform link guidance is generated from `config/smm.php`, which is
    | also what validates the target on the order form. One source, so the example
    | on this page is by definition the example the form accepts — a help page
    | that contradicts the form is worse than no help page.
    |
    | No provider name appears anywhere on it. The customer bought a boost from
    | ReUp.
    */
    $platforms = collect(config('smm.platforms', []))
        ->unique(fn ($entry) => $entry['label'] ?? null);

    $faqs = [
        [
            'q' => 'How long does delivery take?',
            'a' => 'Most orders start within a few minutes and finish within a few hours; large quantities take longer. '
                . 'The order page shows live progress once delivery begins, and updates itself — there is no need to '
                . 'reload it.',
        ],
        [
            'q' => 'Why has my order not finished yet?',
            'a' => 'Orders move through Pending, then In progress, then Completed. A large order can sit In progress for '
                . 'several hours while it is delivered gradually. If it has not moved at all after a day, contact support '
                . 'with your reference.',
        ],
        [
            'q' => 'The count went down again after delivery.',
            'a' => 'Some platforms remove accounts over time, which is normal and outside our control. Where the service '
                . 'supports refills, the order page offers a free refill that tops it back up.',
        ],
        [
            'q' => 'My order failed. Do I get my money back?',
            'a' => 'Yes. An order that cannot be delivered is refunded to your ReUp wallet in full, automatically. You will '
                . 'see a separate refund entry in your wallet history so the credit is easy to find.',
        ],
        [
            'q' => 'What does "Being confirmed" mean?',
            'a' => 'It means we sent the order and are waiting for the platform to tell us what happened to it. Your money '
                . 'is held, not lost. Please do not place the order again — if two orders were accepted you would be '
                . 'charged twice.',
        ],
        [
            'q' => 'Can I change the link after ordering?',
            'a' => 'No. Delivery starts almost immediately and the platform cannot redirect it. Please check the link on '
                . 'the review screen before paying — it is shown in full there for exactly that reason.',
        ],
        [
            'q' => 'Why must my account be public?',
            'a' => 'A private account cannot receive followers, likes or views. If your account is private during '
                . 'delivery, the order will be counted as delivered by the platform even though nothing arrived. Make '
                . 'it public before ordering and keep it public until the order completes.',
        ],
        [
            'q' => 'Is this safe for my account?',
            'a' => 'Yes. We never ask for your password, and we never need access to your account. All we need is the '
                . 'public link, and we only ever pass that link to the delivery provider.',
        ],
    ];
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-10">

        <nav class="mb-4 flex items-center gap-2 text-xs text-muted-foreground" aria-label="Breadcrumb">
            <a href="{{ route('social-boost.index', [], false) }}" class="link">Social Boost</a>
            <x-icon name="chevron-right" class="h-3 w-3" />
            <span>Help</span>
        </nav>

        <header class="mb-6 max-w-3xl">
            <h1 class="text-2xl font-semibold tracking-tight">Social Boost guide</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                What to paste, how long delivery takes, and what happens when something goes wrong.
            </p>
        </header>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:gap-8">

            <div class="space-y-6 lg:col-span-2">

                {{-- ===================== What link to paste ===================== --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">What link should I paste?</h2>
                        <p class="card-description">
                            The single most common reason an order fails. Each platform expects a particular shape.
                        </p>
                    </div>

                    <div class="card-content md:p-6">
                        <div class="overflow-hidden rounded-xl border border-border">
                            <table class="w-full text-sm">
                                <caption class="sr-only">The link format each platform expects</caption>
                                <thead class="bg-ink-50">
                                    <tr>
                                        <th scope="col" class="px-3 py-2.5 text-left font-medium">Platform</th>
                                        <th scope="col" class="px-3 py-2.5 text-left font-medium">What to paste</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach($platforms as $key => $platform)
                                        @php
                                            $rule = config("smm.targets.{$key}", config('smm.target_fallback', []));
                                        @endphp
                                        <tr>
                                            <td class="whitespace-nowrap px-3 py-2.5 align-top font-medium">
                                                {{ $platform['label'] ?? ucfirst($key) }}
                                            </td>
                                            <td class="px-3 py-2.5 align-top">
                                                <span class="font-mono text-xs break-all">{{ $rule['hint'] ?? '—' }}</span>
                                                <p class="mt-0.5 text-xs text-muted-foreground">{{ $rule['label'] ?? '' }}</p>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <p class="mt-3 text-xs text-muted-foreground">
                            For any platform not listed here, paste the full link to the profile or post —
                            starting with <span class="font-mono">https://</span>.
                        </p>
                    </div>
                </section>

                {{-- ===================== Requirements ===================== --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Before you order</h2>
                    </div>
                    <div class="card-content space-y-3 md:p-6">
                        @foreach([
                            ['title' => 'Make your account public', 'body' => 'A private account cannot receive anything. Keep it public until the order finishes.'],
                            ['title' => 'Check the link twice', 'body' => 'A boost cannot be redirected once it starts. The review screen shows the exact link that will be used.'],
                            ['title' => 'Have enough in your wallet', 'body' => 'The total is shown with your balance and your balance after, before you confirm.'],
                            ['title' => 'Read the service note', 'body' => 'Some services need setup first — inviting a bot, for example. Where that applies, it is shown on the order form and again on the review screen.'],
                        ] as $item)
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600">
                                    <x-icon name="check" class="h-3.5 w-3.5" />
                                </span>
                                <div>
                                    <p class="text-sm font-medium">{{ $item['title'] }}</p>
                                    <p class="mt-0.5 text-sm text-muted-foreground">{{ $item['body'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>

                {{-- ===================== FAQ ===================== --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Common questions</h2>
                    </div>
                    <div class="card-content divide-y divide-border md:p-6" x-data="{ open: null }">
                        @foreach($faqs as $index => $faq)
                            <div class="py-3 first:pt-0 last:pb-0">
                                <button type="button"
                                        class="flex w-full items-start justify-between gap-3 text-left"
                                        :aria-expanded="open === {{ $index }} ? 'true' : 'false'"
                                        @click="open = open === {{ $index }} ? null : {{ $index }}">
                                    <span class="text-sm font-medium">{{ $faq['q'] }}</span>
                                    <x-icon name="chevron-down"
                                            class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground transition-transform"
                                            x-bind:class="open === {{ $index }} ? 'rotate-180' : ''" />
                                </button>
                                <p class="mt-2 text-sm text-muted-foreground" x-show="open === {{ $index }}" x-cloak>
                                    {{ $faq['a'] }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>

            {{-- ===================== Sidebar ===================== --}}
            <aside class="lg:col-span-1">
                <div class="lg:sticky lg:top-6 space-y-4">
                    <div class="card p-4 md:p-5">
                        <h2 class="text-sm font-semibold">Still stuck?</h2>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            Send us your order reference and we will look into it. Have it ready — it starts with
                            <span class="font-mono">SMM-</span>.
                        </p>
                        <a href="{{ route('contact') }}" class="btn btn-primary btn-sm mt-3 w-full">
                            <x-icon name="chat-dots" class="h-3.5 w-3.5" />
                            Contact support
                        </a>
                        <a href="{{ route('social-boost.orders.index', [], false) }}" class="btn btn-outline btn-sm mt-2 w-full">
                            View my orders
                        </a>
                    </div>

                    <div class="card p-4 md:p-5">
                        <h2 class="text-sm font-semibold">What your status means</h2>
                        <dl class="mt-3 space-y-2.5 text-xs">
                            @foreach([
                                ['label' => 'Pending', 'tone' => 'warning', 'body' => 'Sent to the provider, not started yet.'],
                                ['label' => 'In progress', 'tone' => 'info', 'body' => 'Being delivered now.'],
                                ['label' => 'Completed', 'tone' => 'success', 'body' => 'Delivered in full.'],
                                ['label' => 'Partly delivered', 'tone' => 'warning', 'body' => 'Some of it arrived; we are reviewing the rest.'],
                                ['label' => 'Being confirmed', 'tone' => 'info', 'body' => 'We are checking the outcome. Do not reorder.'],
                                ['label' => 'Refunded', 'tone' => 'neutral', 'body' => 'Returned to your wallet in full.'],
                            ] as $state)
                                <div class="flex items-start gap-2">
                                    <span class="badge badge-{{ $state['tone'] }} shrink-0">{{ $state['label'] }}</span>
                                    <span class="text-muted-foreground">{{ $state['body'] }}</span>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>
@endsection
