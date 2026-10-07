@extends('layouts.app')

@section('title', 'Payment status')

@section('content')
@php
    $statusMeta = [
        'success' => ['badge-success', 'check-circle', 'Payment confirmed'],
        'processing' => ['badge-info', 'arrow-path', 'Payment processing'],
        'pending' => ['badge-warning', 'clock', 'Awaiting payment'],
        'verifying' => ['badge-warning', 'magnifying-glass', 'Verifying your payment'],
        'failed' => ['badge-destructive', 'exclamation-triangle', 'Payment failed'],
        'cancelled' => ['badge-neutral', 'no-symbol', 'Payment cancelled'],
    ];

    $status = $transaction->status ?? 'pending';
    [$badge, $icon, $headline] = $statusMeta[$status] ?? ['badge-neutral', 'information-circle', 'Payment status'];
@endphp

<div class="container-page py-12">
    <div class="mx-auto max-w-lg">

        <div class="card">
            <div class="card-content text-center sm:p-8">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-accent text-brand-600">
                    <x-icon :name="$icon" class="h-6 w-6" />
                </span>

                <h1 class="mt-5 text-xl font-semibold tracking-tight">{{ $headline }}</h1>

                <span class="badge {{ $badge }} mt-3">{{ ucfirst($status) }}</span>

                <p class="mt-4 text-sm text-muted-foreground">
                    @switch($status)
                        @case('success')
                            Your wallet has been credited. The funds are available immediately.
                            @break
                        @case('processing')
                            The gateway is still processing this payment. Nothing further is needed from you.
                            @break
                        @case('pending')
                            We have not received a confirmation from the gateway yet. This page updates as soon as we do.
                            @break
                        @case('verifying')
                            Your proof of payment is queued for review. We credit the wallet once it is approved.
                            @break
                        @case('failed')
                            No funds left your account for this attempt. You can start a new funding request.
                            @break
                        @case('cancelled')
                            This payment attempt was cancelled. You can start a new one at any time.
                            @break
                        @default
                            We could not determine the state of this payment. Contact support with the reference below.
                    @endswitch
                </p>

                <dl class="mt-6 space-y-2 border-t pt-5 text-left text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-muted-foreground">Reference</dt>
                        <dd class="truncate font-mono text-xs font-medium">{{ $reference }}</dd>
                    </div>

                    @if($transaction)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-muted-foreground">Amount</dt>
                            <dd class="font-medium tabular-nums">
                                &#8358;{{ number_format((float) $transaction->amount, 2) }}
                            </dd>
                        </div>

                        @if((float) $transaction->service_fee > 0)
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-muted-foreground">Processing fee</dt>
                                <dd class="font-medium tabular-nums">
                                    &#8358;{{ number_format((float) $transaction->service_fee, 2) }}
                                </dd>
                            </div>
                        @endif

                        <div class="flex items-center justify-between gap-3 border-t pt-2">
                            <dt class="font-medium">Total</dt>
                            <dd class="font-semibold tabular-nums">
                                &#8358;{{ number_format((float) $transaction->total_amount, 2) }}
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-muted-foreground">Started</dt>
                            <dd class="font-medium">{{ $transaction->created_at?->format('M j, Y g:i A') ?? '—' }}</dd>
                        </div>
                    @endif
                </dl>

                <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                    @if($status === 'failed' || $status === 'cancelled')
                        <a href="{{ route('wallet.fund') }}" class="btn btn-primary flex-1">
                            <x-icon name="arrow-path" class="h-4 w-4" />
                            Try again
                        </a>
                    @else
                        <a href="{{ route('wallet.index') }}" class="btn btn-primary flex-1">
                            <x-icon name="wallet" class="h-4 w-4" />
                            Go to wallet
                        </a>
                    @endif

                    <a href="{{ route('contact') }}" class="btn btn-outline flex-1">
                        <x-icon name="lifebuoy" class="h-4 w-4" />
                        Contact support
                    </a>
                </div>

                <p class="mt-5 text-xs text-muted-foreground">
                    If you were debited but this page has not updated within 30 minutes, contact support
                    with the reference above. Never share your card details.
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
