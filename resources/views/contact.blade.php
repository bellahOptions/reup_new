@extends('layouts.main')
@section('title', 'Contact us')
@section('main')
@php
    /*
    |--------------------------------------------------------------------------
    | Contact
    |--------------------------------------------------------------------------
    | ContactController::index() renders this view with no data, and
    | ::submit() validates {name, email, subject, message} and returns JSON.
    | The hardcoded mailto:/tel: literals are gone — the addresses now come
    | from config('services.support.*') so they can change per environment.
    */
    $supportEmail = config('services.support.email');
    $supportPhone = config('services.support.phone');

    $topics = [
        'General Inquiry' => 'General inquiry',
        'Technical Support' => 'Technical support',
        'Billing Issue' => 'Billing issue',
        'Feature Request' => 'Feature request',
        'Partnership' => 'Partnership',
        'Other' => 'Other',
    ];

    $faqs = [
        [
            'question' => 'How quickly will I receive my airtime or data?',
            'answer' => 'All recharges are delivered within seconds of a successful payment.',
        ],
        [
            'question' => 'What payment methods do you accept?',
            'answer' => 'Bank transfer, debit card and your ReUp wallet balance.',
        ],
        [
            'question' => 'Is there a service fee?',
            'answer' => 'A processing fee applies to each transaction and is always shown before you confirm.',
        ],
    ];
@endphp

<main class="min-h-screen bg-background">
    <div class="container-page py-10 md:py-16">

        {{-- Page heading --}}
        <header class="mb-10 max-w-2xl md:mb-14">
            <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Contact us</h1>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground md:text-base">
                Questions about a transaction, your wallet or anything else — send us a message and the
                support team will get back to you.
            </p>
        </header>

        <div class="grid grid-cols-1 gap-8 lg:grid-cols-12 lg:gap-12">

            {{-- Direct channels --}}
            <div class="space-y-8 lg:col-span-5">
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Talk to us directly</h2>
                        <p class="card-description">Fastest routes to a human.</p>
                    </div>
                    <div class="card-content space-y-4">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-border bg-surface text-ink-600">
                                <x-icon name="envelope" class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium">Email</p>
                                @if($supportEmail)
                                    <a href="mailto:{{ $supportEmail }}" class="link text-sm break-all">{{ $supportEmail }}</a>
                                @endif
                                <p class="mt-1 text-xs text-muted-foreground">Replies within one business day.</p>
                            </div>
                        </div>

                        <div class="divider"></div>

                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-border bg-surface text-ink-600">
                                <x-icon name="phone" class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium">Phone</p>
                                @if($supportPhone)
                                    <a href="tel:{{ preg_replace('/\s+/', '', $supportPhone) }}" class="link text-sm tabular-nums">{{ $supportPhone }}</a>
                                @endif
                                <p class="mt-1 text-xs text-muted-foreground">Mon&ndash;Fri, 9am&ndash;6pm WAT.</p>
                            </div>
                        </div>

                        <div class="divider"></div>

                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-border bg-surface text-ink-600">
                                <x-icon name="chat-bubble-left-right" class="h-5 w-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium">Live chat</p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    Signed-in customers can reach an agent in real time.
                                </p>
                                <a href="{{ route('live.chat') }}" class="link mt-1 inline-flex items-center gap-1 text-sm">
                                    Open live chat
                                    <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                                </a>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Common questions</h2>
                        <p class="card-description">
                            The full list lives in our
                            <a href="{{ route('faq') }}" class="link">frequently asked questions</a>.
                        </p>
                    </div>
                    <div class="card-content space-y-4">
                        @foreach($faqs as $faq)
                            <div @class(['border-b border-border pb-4' => ! $loop->last])>
                                <h3 class="text-sm font-medium">{{ $faq['question'] }}</h3>
                                <p class="mt-1 text-sm text-muted-foreground">{{ $faq['answer'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Registered office</h2>
                    </div>
                    <div class="card-content space-y-3 text-sm text-muted-foreground">
                        <p class="flex items-start gap-2.5">
                            <x-icon name="map-pin" class="mt-0.5 h-4 w-4 shrink-0 text-ink-400" />
                            <span>Atan Ota, Ogun State, Nigeria</span>
                        </p>
                        <p class="flex items-start gap-2.5">
                            <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0 text-ink-400" />
                            <span>Monday to Friday, 9am&ndash;6pm WAT</span>
                        </p>
                    </div>
                </section>
            </div>

            {{-- Message form. A progressive-enhancement POST to
                 ContactsController::submit() — the field names, @csrf token and
                 route are unchanged; validation errors are now surfaced in the
                 rendered page instead of being swallowed by inline script. --}}
            <div class="lg:col-span-7">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Send us a message</h2>
                        <p class="card-description">Tell us what happened and include a reference if you have one.</p>
                    </div>

                    <form id="contactForm" action="{{ route('contact.submit') }}" method="POST" class="card-content space-y-5">
                        @csrf

                        @if($errors->any())
                            <div class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4" role="alert">
                                <x-icon name="exclamation-circle" variant="solid" class="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                                <div class="min-w-0 text-sm text-red-800">
                                    <p class="font-medium">We could not send that message.</p>
                                    <ul class="mt-1 list-disc space-y-0.5 pl-4">
                                        @foreach($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        @endif

                        <div>
                            <label class="label" for="name">Full name</label>
                            <input type="text" id="name" name="name" placeholder="Tobi Olaide"
                                   value="{{ old('name', auth()->user()->name ?? '') }}"
                                   @class(['input mt-1.5', 'input-error' => $errors->has('name')])
                                   autocomplete="name" required>
                            @error('name')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="label" for="email">Email address</label>
                            <input type="email" id="email" name="email" placeholder="you@example.com"
                                   value="{{ old('email', auth()->user()->email ?? '') }}"
                                   @class(['input mt-1.5', 'input-error' => $errors->has('email')])
                                   autocomplete="email" required>
                            @error('email')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="label" for="subject">Subject</label>
                            <select id="subject" name="subject"
                                    @class(['select mt-1.5', 'input-error' => $errors->has('subject')]) required>
                                <option value="">Select a topic</option>
                                @foreach($topics as $value => $label)
                                    <option value="{{ $value }}" @selected(old('subject') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('subject')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="label" for="message">Message</label>
                            <textarea id="message" name="message" rows="6" placeholder="How can we help?"
                                      @class(['textarea mt-1.5', 'input-error' => $errors->has('message')]) required>{{ old('message') }}</textarea>
                            @error('message')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <button type="submit" class="btn btn-primary">
                                <x-icon name="paper-airplane" class="h-4 w-4" />
                                Send message
                            </button>
                            <p class="text-xs text-muted-foreground">We usually reply within 1&ndash;2 business hours.</p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
@endsection
