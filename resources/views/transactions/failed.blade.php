@extends('layouts.app')
@section('title', 'Transaction failed')
@section('content')
<main class="min-h-screen bg-surface">
    <div class="container-page flex justify-center py-10 md:py-16">
        <div class="w-full max-w-xl">

            {{-- Outcome --}}
            <header class="text-center">
                <span class="inline-flex h-14 w-14 items-center justify-center rounded-full border border-red-200 bg-red-50 text-red-600">
                    <x-icon name="x-mark" variant="solid" class="h-7 w-7" />
                </span>
                <p class="mt-5 text-xs font-semibold uppercase tracking-[0.14em] text-red-700">Not completed</p>
                <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Transaction failed</h1>
                <p class="mx-auto mt-2 max-w-md text-sm text-muted-foreground">
                    No money left your wallet. You can review the details below and try again.
                </p>
            </header>

            {{-- Reason --}}
            @if($transaction->failure_reason ?? $transaction->status_message)
                <div class="mt-8 rounded-xl border border-red-200 bg-red-50 p-4">
                    <div class="flex items-start gap-2.5">
                        <x-icon name="exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                        <div>
                            <p class="text-sm font-semibold text-red-800">What went wrong</p>
                            <p class="mt-1 text-sm text-red-700">
                                {{ $transaction->failure_reason ?? $transaction->status_message }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Attempted amount --}}
            <div class="card mt-6">
                <div class="card-content text-center">
                    <p class="stat-label">Attempted amount</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums md:text-4xl">
                        &#8358;{{ number_format((float) $transaction->total_amount, 2) }}
                    </p>
                    <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
                        <span class="badge badge-destructive">
                            <x-icon name="x-mark" class="h-3.5 w-3.5" />
                            {{ ucfirst((string) $transaction->status) }}
                        </span>
                        <span class="badge badge-neutral">{{ ucfirst((string) $transaction->service_type) }}</span>
                    </div>
                </div>
            </div>

            {{-- Details --}}
            <div class="card mt-6 overflow-hidden">
                <div class="flex items-center gap-3 border-b border-border px-5 py-4">
                    <x-icon name="document-text" class="h-4 w-4 text-muted-foreground" />
                    <h2 class="card-title">Transaction details</h2>
                </div>

                <dl class="divide-y divide-border">
                    <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                        <dt class="text-sm text-muted-foreground">Status</dt>
                        <dd class="text-right text-sm font-medium">{{ ucfirst((string) $transaction->status) }}</dd>
                    </div>

                    <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                        <dt class="text-sm text-muted-foreground">Reference</dt>
                        <dd class="break-all text-right font-mono text-xs">{{ $transaction->reference }}</dd>
                    </div>

                    @if($transaction->api_reference)
                        <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                            <dt class="text-sm text-muted-foreground">Order ID</dt>
                            <dd class="break-all text-right font-mono text-xs">{{ $transaction->api_reference }}</dd>
                        </div>
                    @endif

                    <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                        <dt class="text-sm text-muted-foreground">Service type</dt>
                        <dd class="text-right text-sm font-medium">{{ ucfirst((string) $transaction->service_type) }}</dd>
                    </div>

                    <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                        <dt class="text-sm text-muted-foreground">Recipient</dt>
                        <dd class="text-right text-sm font-medium">{{ $transaction->recipient ?: '—' }}</dd>
                    </div>

                    <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                        <dt class="text-sm text-muted-foreground">Network</dt>
                        <dd class="text-right text-sm font-medium">{{ $transaction->network_display }}</dd>
                    </div>

                    @if($transaction->plan_name)
                        <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                            <dt class="text-sm text-muted-foreground">Plan</dt>
                            <dd class="text-right text-sm font-medium">{{ $transaction->plan_name }}</dd>
                        </div>
                    @endif

                    <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                        <dt class="text-sm text-muted-foreground">Date</dt>
                        <dd class="text-right text-sm font-medium">{{ $transaction->created_at->format('M j, Y \a\t g:i A') }}</dd>
                    </div>
                </dl>

                <div class="border-t border-border bg-surface px-5 py-4">
                    <div class="flex items-start justify-between gap-6">
                        <span class="text-sm text-muted-foreground">Your wallet balance</span>
                        <span class="text-right text-sm font-semibold tabular-nums">
                            &#8358;{{ number_format((float) auth()->user()->wallet_balance, 2) }}
                        </span>
                    </div>
                    <p class="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                        <x-icon name="information-circle" class="h-3.5 w-3.5" />
                        No amount was deducted from your wallet.
                    </p>
                </div>
            </div>

            {{-- Common causes --}}
            <div class="card mt-6">
                <div class="flex items-center gap-3 border-b border-border px-5 py-4">
                    <x-icon name="light-bulb" class="h-4 w-4 text-muted-foreground" />
                    <h2 class="card-title">Common causes</h2>
                </div>
                <div class="card-content space-y-3">
                    @foreach([
                        'The phone number was entered incorrectly.',
                        'The wallet balance was below the total amount.',
                        'The selected network did not match the phone number.',
                        'The provider was briefly unavailable.',
                    ] as $cause)
                        <p class="flex items-start gap-2.5 text-sm text-muted-foreground">
                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-ink-300"></span>
                            {{ $cause }}
                        </p>
                    @endforeach
                </div>
            </div>

            {{-- Actions --}}
            <div class="mt-6 space-y-3">
                <a href="{{ route('airtime-data.index') }}" class="btn btn-primary btn-lg w-full">
                    <x-icon name="arrow-path" class="h-5 w-5" />
                    Try again
                </a>
                <a href="{{ route('dashboard') }}" class="btn btn-outline btn-lg w-full">
                    Back to dashboard
                </a>
                <a href="{{ route('contact') }}" class="btn btn-link w-full justify-center">
                    Contact support
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
            </div>
        </div>
    </div>
</main>
@endsection
