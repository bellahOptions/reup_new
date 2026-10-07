@php
    $year = date('Y');
    $supportEmail = config('services.support.email', 'support@reup.com.ng');
    $supportPhone = config('services.support.phone', '+234 907 601 7916');

    $columns = [
        'Services' => [
            ['Airtime & Data', route('airtime-data.index')],
            ['Cable TV', route('cable-tv.index')],
            ['Electricity', route('electricity.index')],
            ['WAEC e-PIN', route('waec-pin.index')],
            ['JAMB e-PIN', route('jamb-pin.index')],
        ],
        'Company' => [
            ['Pricelist', route('pricelist')],
            ['FAQs', route('faq')],
            ['Contact us', route('contact')],
            ['Terms of service', route('terms-of-service')],
            ['Privacy policy', route('privacy-policy')],
        ],
    ];

    $socials = [
        ['Facebook', 'https://web.facebook.com/reupByBellah/', 'M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.52 1.49-3.91 3.77-3.91 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.89h2.78l-.45 2.91h-2.33V22c4.78-.76 8.44-4.92 8.44-9.94Z'],
        ['X', 'https://x.com/ReupNG', 'M18.24 2.25h3.31l-7.23 8.26 8.5 11.24h-6.65l-5.21-6.82-5.96 6.82H1.68l7.73-8.84L1.25 2.25h6.82l4.71 6.23 5.46-6.23Zm-1.16 17.52h1.83L7.08 4.13H5.11l11.97 15.64Z'],
        ['Instagram', 'https://www.instagram.com/reup.ng/', 'M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.8 3.8 0 0 1-1.38-.9 3.8 3.8 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16Zm0 5.68a4.16 4.16 0 1 0 0 8.32 4.16 4.16 0 0 0 0-8.32Zm0 6.86a2.7 2.7 0 1 1 0-5.4 2.7 2.7 0 0 1 0 5.4Zm5.3-7.03a.97.97 0 1 1-1.94 0 .97.97 0 0 1 1.94 0Z'],
    ];
@endphp

<footer class="mt-auto border-t border-ink-800 bg-ink-950 text-ink-300">
    <div class="container-page py-14">
        <div class="grid gap-10 lg:grid-cols-12">

            {{-- Brand + contact --}}
            <div class="lg:col-span-5">
                <img src="{{ asset('images/reup-04.svg') }}" alt="ReUp" class="h-7 w-auto brightness-0 invert">

                <p class="mt-4 max-w-sm text-sm leading-relaxed text-ink-400">
                    ReUp is a Nigerian bill-payment platform for airtime, data, cable TV,
                    electricity tokens and exam PINs — with wallet funding that settles in seconds.
                </p>

                <div class="mt-6 space-y-3 text-sm">
                    <a href="mailto:{{ $supportEmail }}" class="flex items-center gap-2.5 text-ink-300 transition-colors hover:text-white">
                        <x-icon name="envelope" class="h-4 w-4 text-brand-400" />
                        {{ $supportEmail }}
                    </a>
                    <a href="tel:{{ preg_replace('/\s+/', '', $supportPhone) }}" class="flex items-center gap-2.5 text-ink-300 transition-colors hover:text-white">
                        <x-icon name="phone" class="h-4 w-4 text-brand-400" />
                        {{ $supportPhone }}
                    </a>
                </div>

                <div class="mt-6 flex items-center gap-2">
                    @foreach($socials as [$label, $url, $path])
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                           aria-label="{{ $label }}"
                           class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/5 text-ink-300 transition-colors hover:bg-white/10 hover:text-white">
                            <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                                <path d="{{ $path }}" />
                            </svg>
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- Link columns --}}
            @foreach($columns as $heading => $links)
                <div class="lg:col-span-2">
                    <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-500">{{ $heading }}</h3>
                    <ul class="mt-4 space-y-2.5">
                        @foreach($links as [$label, $href])
                            <li>
                                <a href="{{ $href }}" class="text-sm text-ink-400 transition-colors hover:text-white">{{ $label }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            {{-- Trust --}}
            <div class="lg:col-span-3">
                <h3 class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-500">Payments</h3>
                <p class="mt-4 text-sm text-ink-400">
                    Fund your wallet by card or direct bank transfer. All connections are
                    encrypted end to end.
                </p>
                <div class="mt-4 inline-flex items-center gap-2 rounded-lg bg-white/5 px-3 py-2 text-xs text-ink-300">
                    <x-icon name="shield-check" variant="solid" class="h-4 w-4 text-brand-400" />
                    PCI-DSS compliant gateway
                </div>
            </div>
        </div>

        <div class="mt-12 flex flex-col items-center justify-between gap-4 border-t border-ink-800 pt-6 sm:flex-row">
            <p class="text-xs text-ink-500">&copy; {{ $year }} ReUp. All rights reserved.</p>
            <p class="text-xs text-ink-500">
                Built and operated by
                <a href="https://www.bellahoptions.com" target="_blank" rel="noopener noreferrer"
                   class="font-medium text-ink-300 transition-colors hover:text-white">Bellah Options</a>
            </p>
        </div>
    </div>
</footer>
