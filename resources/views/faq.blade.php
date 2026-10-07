@extends('layouts.main')
@section('title', 'Frequently asked questions')
@section('main')
@php
    /*
    |--------------------------------------------------------------------------
    | FAQ
    |--------------------------------------------------------------------------
    | Route::view('/faq', 'faq') passes no data, so the whole catalogue lives in
    | this view. The previous version toggled answers with a DOM script that
    | rewrote emoji glyphs in place and carried a separate inline <style> block.
    |
    | Alpine owns the search state; each answer is a native <details> element so
    | the accordions are keyboard accessible and work before JS boots. The
    | per-item search text is lower-cased once in @php rather than in every
    | Alpine expression.
    */
    $supportEmail = config('services.support.email');
    $supportPhone = config('services.support.phone');

    $faqCategories = [
        [
            'title' => 'Getting started',
            'icon' => 'rocket-launch',
            'items' => [
                [
                    'question' => 'How do I create an account?',
                    'answer' => 'Select “Get started”, then provide your email address, phone number and a secure password. Verify your email and you can start buying airtime and data straight away.',
                ],
                [
                    'question' => 'Is there a minimum deposit amount?',
                    'answer' => 'Yes — the minimum wallet funding amount is ₦100. The maximum per transaction is ₦1,000,000.',
                ],
            ],
        ],
        [
            'title' => 'Airtime & data',
            'icon' => 'device-phone-mobile',
            'items' => [
                [
                    'question' => 'How long does it take for airtime to be delivered?',
                    'answer' => 'Delivery is normally within 10–30 seconds of a successful payment. During peak hours it can take up to two minutes.',
                ],
                [
                    'question' => 'Which networks do you support?',
                    'answer' => 'All major Nigerian networks: MTN, Airtel, Glo and 9mobile.',
                ],
                [
                    'question' => 'Can I buy data for someone else?',
                    'answer' => 'Yes. Enter the recipient’s phone number at checkout and the bundle is delivered to them directly.',
                ],
            ],
        ],
        [
            'title' => 'Payments & wallet',
            'icon' => 'credit-card',
            'items' => [
                [
                    'question' => 'What payment methods do you accept?',
                    'answer' => 'Card and bank transfer through our payment gateway, and your ReUp wallet balance.',
                ],
                [
                    'question' => 'How do I fund my wallet?',
                    'answer' => 'Open Wallet, select “Fund wallet”, choose an amount and pick either card payment or bank transfer. Bank transfers are matched automatically using the reference we show you.',
                ],
                [
                    'question' => 'Are there any transaction fees?',
                    'answer' => 'A processing fee applies per transaction. It is shown in full on the confirmation screen before you approve anything — there are no hidden charges.',
                ],
            ],
        ],
        [
            'title' => 'Troubleshooting',
            'icon' => 'wrench-screwdriver',
            'items' => [
                [
                    'question' => 'What if my transaction fails?',
                    'answer' => 'Check your internet connection, confirm the phone number or meter number, and check your wallet balance. If you were debited but nothing was delivered, the transaction is reversed automatically — you can also raise it with support using the reference.',
                ],
                [
                    'question' => 'How do I contact customer support?',
                    'answer' => 'Use the contact form, start a live chat while signed in, or reach us by email or phone. Include your transaction reference so we can trace it immediately.',
                ],
            ],
        ],
    ];

    // Pre-compute the lower-cased haystack for each entry: doing it here keeps
    // the Alpine expressions readable and avoids repeating the concatenation
    // on every keystroke.
    $faqCategories = array_map(function (array $category) {
        $category['items'] = array_map(function (array $item) {
            $item['search'] = Str::lower($item['question'] . ' ' . $item['answer']);
            return $item;
        }, $category['items']);

        return $category;
    }, $faqCategories);
@endphp

<main class="min-h-screen bg-background"
      x-data="{ query: '', sectionMatches: {} }"
      @faq:found.window="sectionMatches[$event.detail.section] = $event.detail.count">
    <div class="container-page py-10 md:py-16">

        {{-- Search --}}
        <div class="mb-8 max-w-xl">
            <label class="label" for="faqSearch">Search questions</label>
            <div class="relative mt-1.5">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                    <x-icon name="magnifying-glass" class="h-4 w-4" />
                </span>
                <input type="search" id="faqSearch" x-model="query" class="input pl-9"
                       placeholder="e.g. refund, data bundle, withdrawal">
            </div>
        </div>

        {{-- Page heading --}}
        <header class="mb-8 max-w-2xl md:mb-10">
            <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Frequently asked questions</h1>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground md:text-base">
                Short answers to the questions our support team hears most. If your situation is
                different, <a href="{{ route('contact') }}" class="link">contact us</a> and we will look into it.
            </p>
        </header>

        <div class="space-y-8">
            @foreach($faqCategories as $category)
                {{-- The haystack for each entry is carried on the server-rendered
                     markup in data-search, so filtering never depends on an
                     inline JS literal that has to be escaped twice. --}}
                <section class="card"
                         x-data="{
                            local: [],
                            matches() {
                                const q = this.query.trim().toLowerCase();
                                if (q === '') return this.local.length;
                                return this.local.filter(h => h.includes(q)).length;
                            }
                         }"
                         x-init="local = Array.from($el.querySelectorAll('details')).map(el => el.dataset.search)"
                         x-effect="$dispatch('faq:found', { section: @js(Str::slug($category['title'])), count: matches() })"
                         x-show="matches() > 0">
                    <div class="card-header flex-row items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-border bg-surface text-ink-600">
                            <x-icon :name="$category['icon']" class="h-5 w-5" />
                        </span>
                        <h2 class="card-title">{{ $category['title'] }}</h2>
                    </div>

                    <div class="divide-y divide-border">
                        @foreach($category['items'] as $item)
                            <details class="group px-5" data-search="{{ $item['search'] }}"
                                     x-show="query.trim() === '' || $el.dataset.search.includes(query.trim().toLowerCase())">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-4 text-sm font-medium marker:content-none">
                                    <span>{{ $item['question'] }}</span>
                                    <x-icon name="chevron-down"
                                            class="h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200 group-open:rotate-180" />
                                </summary>
                                <div class="pb-4 text-sm leading-relaxed text-muted-foreground">
                                    {{ $item['answer'] }}
                                </div>
                            </details>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        {{-- No-match notice. Each section publishes its own match count to the
             page-level component, so this never queries the DOM for hidden
             nodes and only appears once every section has reported zero. --}}
        <p class="mt-8 rounded-lg border border-dashed border-border bg-surface px-5 py-6 text-center text-sm text-muted-foreground"
           x-show="query.trim() !== '' && Object.values(sectionMatches).reduce((n, c) => n + c, 0) === 0" x-cloak>
            Nothing matched that search. Try a different word, or
            <a href="{{ route('contact') }}" class="link">ask our support team</a>.
        </p>

        {{-- Still need help --}}
        <section class="mt-12 border-t border-border pt-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold">Still need help?</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Our support team replies to most messages within a couple of hours.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
                        @if($supportEmail)
                            <span class="flex items-center gap-2">
                                <x-icon name="envelope" class="h-4 w-4 shrink-0 text-ink-400" />
                                <a href="mailto:{{ $supportEmail }}" class="link break-all">{{ $supportEmail }}</a>
                            </span>
                        @endif
                        @if($supportPhone)
                            <span class="flex items-center gap-2">
                                <x-icon name="phone" class="h-4 w-4 shrink-0 text-ink-400" />
                                <a href="tel:{{ preg_replace('/\s+/', '', $supportPhone) }}" class="link tabular-nums">{{ $supportPhone }}</a>
                            </span>
                        @endif
                    </div>
                </div>
                <a href="{{ route('contact') }}" class="btn btn-primary shrink-0">
                    <x-icon name="chat-bubble-left-right" class="h-4 w-4" />
                    Contact support
                </a>
            </div>
        </section>
    </div>
</main>
@endsection
