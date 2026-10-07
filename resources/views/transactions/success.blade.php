@extends('layouts.app')
@section('title', 'Transaction successful')
@section('content')
<main class="min-h-screen bg-surface">
    <div class="container-page flex justify-center py-10 md:py-16">
        <div class="w-full max-w-xl">

            {{-- Outcome --}}
            <header class="text-center">
                <span class="inline-flex h-14 w-14 items-center justify-center rounded-full border border-brand-200 bg-accent text-brand-700">
                    <x-icon name="check-circle" variant="solid" class="h-7 w-7" />
                </span>
                <h1 class="mt-5 text-2xl font-semibold md:text-3xl">Transaction successful</h1>
                <p class="mx-auto mt-2 max-w-md text-sm text-muted-foreground">
                    We have delivered your order and sent a receipt to your email address.
                </p>
            </header>

            {{-- Amount summary --}}
            <div class="card mt-8">
                <div class="card-content text-center">
                    <p class="stat-label">Total paid</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums md:text-4xl">
                        &#8358;{{ number_format((float) $transaction->total_amount, 2) }}
                    </p>
                    <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
                        <span class="badge badge-success">
                            <x-icon name="check-circle" class="h-3.5 w-3.5" />
                            Successful
                        </span>
                        <span class="badge badge-neutral">{{ ucfirst((string) $transaction->service_type) }}</span>
                    </div>
                </div>
            </div>

            {{-- Receipt --}}
            <div class="card mt-6 overflow-hidden">
                <div class="flex items-center gap-3 border-b border-border px-5 py-4">
                    <x-icon name="document-text" class="h-4 w-4 text-muted-foreground" />
                    <h2 class="card-title">Transaction details</h2>
                </div>

                <dl class="divide-y divide-border">
                    <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                        <dt class="text-sm text-muted-foreground">Service type</dt>
                        <dd class="text-right text-sm font-medium">{{ ucfirst((string) $transaction->service_type) }}</dd>
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
                        <dt class="text-sm text-muted-foreground">Recipient</dt>
                        <dd class="text-right text-sm font-medium">{{ $transaction->recipient ?: '—' }}</dd>
                    </div>

                    <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                        <dt class="text-sm text-muted-foreground">Network</dt>
                        <dd class="text-right text-sm font-medium">{{ $transaction->provider ?: '—' }}</dd>
                    </div>

                    @if($transaction->plan_name)
                        <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                            <dt class="text-sm text-muted-foreground">Plan</dt>
                            <dd class="text-right text-sm font-medium">{{ $transaction->plan_name }}</dd>
                        </div>
                    @endif

                    <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                        <dt class="text-sm text-muted-foreground">Amount</dt>
                        <dd class="text-right text-sm font-medium tabular-nums">&#8358;{{ number_format((float) $transaction->amount, 2) }}</dd>
                    </div>

                    <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                        <dt class="text-sm text-muted-foreground">Service fee</dt>
                        <dd class="text-right text-sm font-medium tabular-nums">&#8358;{{ number_format((float) $transaction->service_fee, 2) }}</dd>
                    </div>

                    <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                        <dt class="text-sm font-semibold">Total paid</dt>
                        <dd class="text-right text-base font-semibold tabular-nums text-green-700">
                            &#8358;{{ number_format((float) $transaction->total_amount, 2) }}
                        </dd>
                    </div>

                    <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                        <dt class="text-sm text-muted-foreground">Wallet balance</dt>
                        <dd class="text-right text-sm font-medium tabular-nums">
                            @if(is_null($transaction->balance_after))
                                —
                            @else
                                &#8358;{{ number_format((float) $transaction->balance_after, 2) }}
                            @endif
                        </dd>
                    </div>

                    <div class="flex items-start justify-between gap-6 px-5 py-3.5">
                        <dt class="text-sm text-muted-foreground">Date</dt>
                        <dd class="text-right text-sm font-medium">{{ $transaction->created_at->format('M j, Y \a\t g:i A') }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Actions --}}
            <div class="mt-6 space-y-3">
                <a href="{{ route('airtime-data.index') }}" class="btn btn-primary btn-lg w-full">
                    <x-icon name="rocket-launch" class="h-5 w-5" />
                    Make another purchase
                </a>
                <a href="{{ route('dashboard') }}" class="btn btn-outline btn-lg w-full">
                    Back to dashboard
                </a>
                <a href="{{ route('transactions.index') }}" class="btn btn-link w-full justify-center">
                    View transaction history
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
            </div>
        </div>
    </div>
</main>
@endsection
