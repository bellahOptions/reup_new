@php
    /*
    |--------------------------------------------------------------------------
    | First sign-in tips
    |--------------------------------------------------------------------------
    | Shown once, on the dashboard, to a user who has not completed it. The point
    | is orientation: a new account lands on a dashboard full of products and
    | shortcuts with nothing explaining where to start, and the wallet has to be
    | funded before any of it works.
    |
    | Five steps, each pointing at the screen it describes, because a tip that
    | only names a feature makes the user go hunting for it.
    |
    | Alpine owns the stepping. The whole block is rendered server-side inside
    | `x-show` WITHOUT `x-cloak`, so if the bundle fails to load the user still
    | sees the introduction (just without the carousel) rather than a blank gap —
    | the same reasoning that applies to the airtime form.
    */
    $tips = [
        [
            'title' => 'Fund your wallet first',
            'body' => 'Every purchase is paid from your wallet balance. Add money by card, or by bank transfer to the account we generate for you.',
            'icon' => 'wallet',
            'route' => 'wallet.fund',
            'cta' => 'Fund wallet',
        ],
        [
            'title' => 'Buy airtime and data',
            'body' => 'Pick a network, enter the phone number, choose an amount or a data plan. Delivery is instant and the receipt lands in your inbox.',
            'icon' => 'device-phone-mobile',
            'route' => 'airtime-data.index',
            'cta' => 'Buy airtime',
        ],
        [
            'title' => 'Pay bills from one place',
            'body' => 'Cable TV, electricity tokens, WAEC and JAMB e-PINs, and betting wallets — all from the same balance, with the price shown before you pay.',
            'icon' => 'bolt',
            'route' => 'electricity.index',
            'cta' => 'See bills',
        ],
        [
            'title' => 'Set your transaction PIN',
            'body' => 'Your PIN authorises every purchase. Set it once in Profile, and we will email you a code each time you change it.',
            'icon' => 'lock-closed',
            'route' => 'profile.index',
            'cta' => 'Set PIN',
        ],
        [
            'title' => 'Refer and earn &#8358;200',
            'body' => 'Share your referral link. When someone you invited funds their wallet with &#8358;1,000 or more, &#8358;200 is credited to your wallet instantly.',
            'icon' => 'users',
            'route' => 'affiliate.index',
            'cta' => 'Get your link',
        ],
    ];

    // Deep-link straight to the PIN section rather than the top of the profile.
    $tips[3]['route'] = 'profile.index';
@endphp

<section class="mb-6"
         x-data="{ step: 0, total: {{ count($tips) }}, done: false }"
         x-show="!done"
         aria-labelledby="tips-heading">
    <div class="card overflow-hidden border-brand-200">
        <div class="flex items-center gap-3 border-b border-brand-100 bg-accent px-5 py-3">
            <x-icon name="sparkles" variant="solid" class="h-4 w-4 shrink-0 text-brand-700" />
            <h2 id="tips-heading" class="text-sm font-semibold text-accent-foreground">
                Getting started
            </h2>
            <span class="ml-auto text-xs font-medium tabular-nums text-accent-foreground/80"
                  x-text="(step + 1) + ' of ' + total"></span>
            <form method="POST" action="{{ route('tips.dismiss', [], false) }}">
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm text-accent-foreground" aria-label="Skip the introduction">
                    <x-icon name="x-mark" class="h-4 w-4" />
                </button>
            </form>
        </div>

        <div class="card-content">
            @foreach($tips as $index => $tip)
                <div x-show="step === {{ $index }}" @if($index > 0) x-cloak @endif>
                    <div class="flex items-start gap-4">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                            <x-icon :name="$tip['icon']" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold">{!! $tip['title'] !!}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-muted-foreground">{!! $tip['body'] !!}</p>
                            <a href="{{ route($tip['route']) }}" class="btn btn-outline btn-sm mt-3">
                                {{ $tip['cta'] }}
                                <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card-footer items-center justify-between gap-3">
            <div class="flex items-center gap-1.5" role="tablist" aria-label="Tip progress">
                @foreach($tips as $index => $tip)
                    <button type="button"
                            @click="step = {{ $index }}"
                            :class="step === {{ $index }} ? 'w-6 bg-brand-500' : 'w-2 bg-ink-200 hover:bg-ink-300'"
                            class="h-2 rounded-full transition-all"
                            :aria-label="'Go to tip ' + {{ $index + 1 }}"
                            :aria-selected="step === {{ $index }} ? 'true' : 'false'"
                            role="tab"></button>
                @endforeach
            </div>

            <div class="flex items-center gap-2">
                <button type="button" class="btn btn-ghost btn-sm" x-show="step > 0" x-cloak @click="step--">
                    Back
                </button>
                <button type="button" class="btn btn-outline btn-sm" x-show="step < total - 1" @click="step++">
                    Next
                </button>

                {{-- Finishing marks the introduction seen so it does not return. --}}
                <form method="POST" action="{{ route('tips.dismiss', [], false) }}" x-show="step === total - 1" x-cloak>
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">
                        Got it
                        <x-icon name="check" class="h-3.5 w-3.5" />
                    </button>
                </form>
            </div>
        </div>
    </div>

    <noscript>
        <p class="mt-2 text-xs text-muted-foreground">
            Use the “Got it” button to hide this introduction.
        </p>
    </noscript>
</section>
