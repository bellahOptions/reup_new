{{--
    The promotional modal: one feature, shown occasionally, advertising something the
    customer has not used.

    ## Only rendered when the controller says so

    `$prompts['show_promotion']` is false when the account has no transaction PIN,
    because the PIN prompt takes the one dialog a customer will read. The decision
    lives in `DashboardPrompts` rather than here so it can be tested without
    rendering a page — a template `@if` cannot be asserted against directly, and this
    is the interaction where getting it wrong matters.

    ## Nothing here is a campaign

    These advertise features that are always on: Social Boost, and the affiliate
    programme. Dated campaigns go in `PromotionNotification` and render in the
    marquee, which is where an operator can schedule them without a deploy.
--}}
@php
    $promo = $prompts['promotion'];
    $accent = $promo['accent'] ?? 'brand';

    // A closed set, so an unknown accent in config degrades to the default palette
    // rather than emitting a class Tailwind never compiled.
    $palette = [
        'brand' => ['bg' => 'bg-brand-50', 'text' => 'text-brand-700', 'ring' => 'ring-brand-200', 'btn' => 'btn-primary'],
        'emerald' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'ring' => 'ring-emerald-200', 'btn' => 'btn-primary'],
        'amber' => ['bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'ring' => 'ring-amber-200', 'btn' => 'btn-primary'],
    ][$accent] ?? ['bg' => 'bg-brand-50', 'text' => 'text-brand-700', 'ring' => 'ring-brand-200', 'btn' => 'btn-primary'];

    /*
     * The call to action: the route the config names, and nothing appended.
     *
     * `route_params` exists for a route that needs one. Nothing needs it today —
     * the affiliate page issues the customer's code on first visit and shows the
     * share link itself, so inventing a `?ref=` here would be a second, competing
     * way to express something the affiliate service already owns.
     */
    $ctaUrl = route($promo['route'], $promo['route_params'] ?? [], false);
@endphp

<div x-data="promoModal()"
     data-promo-modal
     x-init="init()"
     x-cloak>
    <div x-show="show"
         x-effect="document.body.classList.toggle('overflow-hidden', show)"
         @keydown.escape.window="close()"
         class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
         role="dialog"
         aria-modal="true"
         aria-labelledby="promoTitle">
        <div class="absolute inset-0 bg-ink-900/60" @click="close()" aria-hidden="true"></div>

        <div x-ref="panel"
             tabindex="-1"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4 sm:scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
             class="relative w-full max-w-lg overflow-hidden rounded-2xl border border-border bg-surface shadow-overlay">
            <div class="max-h-[85vh] overflow-y-auto">
                <div class="relative {{ $palette['bg'] }} px-5 pb-5 pt-6 sm:px-7 sm:pb-6 sm:pt-8">
                    <button type="button"
                            @click="close()"
                            class="absolute right-3 top-3 rounded-lg p-1.5 text-ink-500 transition-colors hover:bg-white/60 hover:text-ink-800"
                            aria-label="Close">
                        <x-icon name="x-mark" class="h-4 w-4" />
                    </button>

                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/70 {{ $palette['text'] }} ring-1 {{ $palette['ring'] }}">
                        <x-icon :name="$promo['icon']" variant="solid" class="h-7 w-7" />
                    </span>

                    <p class="mt-4 text-xs font-semibold uppercase tracking-wide {{ $palette['text'] }}">
                        {{ $promo['eyebrow'] ?? '' }}
                    </p>
                    <h2 id="promoTitle" class="mt-1 text-xl font-semibold sm:text-2xl">
                        {{ $promo['title'] ?? '' }}
                    </h2>
                    <p class="mt-2 text-sm text-ink-700">
                        {{ $promo['body'] ?? '' }}
                    </p>
                </div>

                <div class="p-5 sm:p-7">
                    @if(! empty($promo['highlights']))
                        <ul class="space-y-2">
                            @foreach($promo['highlights'] as $highlight)
                                <li class="flex items-start gap-2.5 text-sm">
                                    <x-icon name="check-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 {{ $palette['text'] }}" />
                                    <span>{{ $highlight }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="mt-6 flex flex-col gap-2 sm:flex-row-reverse">
                        <a href="{{ $ctaUrl }}"
                           class="btn {{ $palette['btn'] }} w-full sm:w-auto"
                           @click="close()">
                            {{ $promo['cta'] ?? 'Find out more' }}
                        </a>
                        <button type="button"
                                class="btn btn-outline w-full sm:w-auto"
                                @click="close()">
                            Maybe later
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
