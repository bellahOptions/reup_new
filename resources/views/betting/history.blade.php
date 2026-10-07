@extends('layouts.app')

@section('title', 'Betting funding history')

@section('content')
<div class="container-page py-8">

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">Wallet funding history</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Every bookmaker top-up you have made, newest first.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('transactions.index') }}" class="btn btn-outline btn-sm">
                <x-icon name="queue-list" class="h-4 w-4" />
                Full statement
            </a>
            <a href="{{ route('betting.index') }}" class="btn btn-primary btn-sm">
                <x-icon name="plus" class="h-4 w-4" />
                Fund a wallet
            </a>
        </div>
    </div>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Bookmaker</th>
                        <th>Account</th>
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
                            <td>{{ data_get($transaction->meta, 'betting_label', $transaction->provider ?? '—') }}</td>
                            <td class="font-mono text-sm">{{ $transaction->recipient ?? '—' }}</td>
                            <td class="text-right font-medium tabular-nums">
                                &#8358;{{ number_format((float) $transaction->amount, 2) }}
                            </td>
                            <td>
                                <span class="badge {{ $transaction->status_badge }}">
                                    {{ ucfirst($transaction->status ?? 'unknown') }}
                                </span>
                                @if($transaction->status === 'failed' && $transaction->status_message)
                                    <p class="mt-1 max-w-[16rem] text-xs text-muted-foreground">
                                        {{ \Illuminate\Support\Str::limit($transaction->status_message, 70) }}
                                    </p>
                                @endif
                            </td>
                            <td class="font-mono text-xs text-muted-foreground">{{ $transaction->reference }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <x-icon name="wallet" class="h-8 w-8 text-ink-300" />
                                    <h2 class="mt-3 text-base font-semibold">No top-ups yet</h2>
                                    <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                                        Betting wallet funding will appear here as soon as you make one.
                                    </p>
                                    <a href="{{ route('betting.index') }}" class="btn btn-primary mt-5">
                                        <x-icon name="plus" class="h-4 w-4" />
                                        Fund a betting wallet
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
