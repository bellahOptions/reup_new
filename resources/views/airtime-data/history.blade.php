@extends('layouts.app')

@section('title', 'Airtime & data history')

@section('content')
@php
    $statusBadges = [
        'success' => 'badge-success',
        'processing' => 'badge-info',
        'pending' => 'badge-warning',
        'verifying' => 'badge-warning',
        'failed' => 'badge-destructive',
        'cancelled' => 'badge-neutral',
    ];

    $serviceIcons = [
        'airtime' => 'device-phone-mobile',
        'data' => 'signal',
    ];
@endphp

<div class="container-page py-8">

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">Purchase history</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Every recharge and bundle you have bought, newest first.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('transactions.index') }}" class="btn btn-outline btn-sm">
                <x-icon name="queue-list" class="h-4 w-4" />
                Full statement
            </a>
            <a href="{{ route('airtime-data.index') }}" class="btn btn-primary btn-sm">
                <x-icon name="plus" class="h-4 w-4" />
                Buy airtime or data
            </a>
        </div>
    </div>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Service</th>
                        <th>Recipient</th>
                        <th class="text-right">Amount</th>
                        <th>Status</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $transaction)
                        <tr>
                            <td class="whitespace-nowrap">
                                <span class="font-medium">{{ $transaction->created_at?->format('M j, Y') ?? '—' }}</span>
                                <p class="text-xs text-muted-foreground">{{ $transaction->created_at?->format('g:i A') }}</p>
                            </td>

                            <td>
                                <span class="flex items-center gap-2">
                                    <x-icon :name="$serviceIcons[$transaction->service_type] ?? 'receipt-percent'"
                                            class="h-4 w-4 shrink-0 text-ink-400" />
                                    <span>{{ ucfirst($transaction->service_type ?? 'unknown') }}</span>
                                </span>
                                {{--
                                    The mobile network, not the upstream provider. An
                                    older row resolves its network from the meta it
                                    stored at the time; a row that never captured one
                                    shows the neutral label rather than the provider.
                                --}}
                                @if($transaction->hasResolvedNetwork())
                                    <p class="mt-1 text-xs text-muted-foreground">{{ $transaction->network_display }}</p>
                                @endif
                            </td>

                            <td>
                                <span class="font-mono text-sm">{{ $transaction->recipient ?? '—' }}</span>
                                @if($transaction->plan_name)
                                    <p class="mt-1 text-xs text-muted-foreground">{{ $transaction->plan_name }}</p>
                                @endif
                            </td>

                            <td class="text-right">
                                <span class="font-medium tabular-nums">&#8358;{{ number_format((float) $transaction->amount, 2) }}</span>
                                @if((float) $transaction->service_fee > 0)
                                    <p class="text-xs text-muted-foreground">
                                        +&#8358;{{ number_format((float) $transaction->service_fee, 2) }} fee
                                    </p>
                                @endif
                            </td>

                            <td>
                                <span class="badge {{ $statusBadges[$transaction->status] ?? 'badge-neutral' }}">
                                    {{ ucfirst($transaction->status ?? 'unknown') }}
                                </span>
                                @if($transaction->status === 'failed' && $transaction->status_message)
                                    <p class="mt-1 max-w-[16rem] text-xs text-muted-foreground">
                                        {{ \Illuminate\Support\Str::limit($transaction->status_message, 70) }}
                                    </p>
                                @endif
                            </td>

                            <td>
                                <span class="font-mono text-xs text-muted-foreground">{{ $transaction->reference }}</span>
                                @if($transaction->api_reference)
                                    <p class="mt-1 font-mono text-xs text-ink-400">
                                        {{ \Illuminate\Support\Str::limit($transaction->api_reference, 18) }}
                                    </p>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <x-icon name="device-phone-mobile" class="h-8 w-8 text-ink-300" />
                                    <h2 class="mt-3 text-base font-semibold">No purchases yet</h2>
                                    <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                                        Airtime and data purchases will appear here as soon as you make one.
                                    </p>
                                    <a href="{{ route('airtime-data.index') }}" class="btn btn-primary mt-5">
                                        <x-icon name="plus" class="h-4 w-4" />
                                        Buy airtime or data
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($transactions->hasPages())
            <div class="card-footer justify-end">
                {{ $transactions->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
