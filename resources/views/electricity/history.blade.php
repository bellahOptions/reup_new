@extends('layouts.app')
@section('title', 'Electricity history')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Electricity payment history
    |--------------------------------------------------------------------------
    | ElectricityController::history() passes a LengthAwarePaginator of
    | App\Models\Transactions (service_type "electricity"), already scoped to
    | the signed-in customer. Badge classes come from the model accessors
    | `status_badge` and `service_type_badge`; `balance_after`, `created_at`
    | and `completed_at` are all nullable and are guarded below.
    */
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-10">

        {{-- Page heading --}}
        <header class="mb-6 md:mb-8">
            <a href="{{ route('electricity.index') }}" class="link inline-flex items-center gap-1.5 text-sm font-medium">
                <x-icon name="arrow-left" class="h-4 w-4" />
                Back to Electricity
            </a>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight">Payment history</h1>
            <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                Every prepaid meter token and postpaid bill you have paid for.
            </p>
        </header>

        {{-- Ledger --}}
        <section class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <h2 class="card-title">Transactions</h2>
                    <p class="card-description tabular-nums">
                        {{ number_format($transactions->total()) }}
                        {{ \Illuminate\Support\Str::plural('record', $transactions->total()) }}
                    </p>
                </div>
                <a href="{{ route('transactions.index') }}" class="btn btn-outline btn-sm shrink-0">
                    Full statement
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
            </div>

            @if($transactions->isEmpty())
                <div class="empty-state">
                    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-ink-100 text-ink-500">
                        <x-icon name="bolt" class="h-6 w-6" />
                    </span>
                    <h3 class="mt-4 text-base font-semibold">No electricity payments yet</h3>
                    <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                        Your meter payments and tokens will be listed here.
                    </p>
                    <a href="{{ route('electricity.index') }}" class="btn btn-primary btn-sm mt-5">
                        <x-icon name="bolt" class="h-4 w-4" />
                        Pay for electricity
                    </a>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Description</th>
                                <th scope="col">Recipient</th>
                                <th scope="col" class="text-right">Amount</th>
                                <th scope="col">Status</th>
                                <th scope="col">Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transactions as $transaction)
                                <tr>
                                    <td class="whitespace-nowrap">
                                        @if($transaction->created_at)
                                            <span class="block text-sm font-medium tabular-nums">{{ $transaction->created_at->format('M j, Y') }}</span>
                                            <span class="block text-xs tabular-nums text-muted-foreground">{{ $transaction->created_at->format('g:i A') }}</span>
                                        @else
                                            <span class="block text-sm text-muted-foreground">&mdash;</span>
                                        @endif
                                        @if($transaction->completed_at)
                                            <span class="mt-0.5 block text-xs tabular-nums text-muted-foreground">
                                                Completed {{ $transaction->completed_at->format('M j, g:i A') }}
                                            </span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="block text-sm font-medium">
                                            {{ $transaction->description ?: 'Electricity payment' }}
                                        </span>
                                        <span class="badge {{ $transaction->service_type_badge }} mt-1">
                                            {{ ucwords(str_replace('-', ' ', (string) $transaction->service_type)) }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($transaction->recipient)
                                            <span class="block text-sm tabular-nums">{{ $transaction->recipient }}</span>
                                        @else
                                            <span class="block text-sm text-muted-foreground">&mdash;</span>
                                        @endif
                                        @if($transaction->provider)
                                            <span class="block text-xs text-muted-foreground">{{ $transaction->provider }}</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap text-right">
                                        <span class="text-sm font-semibold tabular-nums">
                                            &#8358;{{ number_format((float) $transaction->amount, 2) }}
                                        </span>
                                        @if((float) $transaction->service_fee > 0)
                                            <span class="block text-xs tabular-nums text-muted-foreground">
                                                Fee &#8358;{{ number_format((float) $transaction->service_fee, 2) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <span class="badge {{ $transaction->status_badge }}">
                                            {{ ucfirst((string) $transaction->status) }}
                                        </span>
                                        @if($transaction->status_message)
                                            <span class="mt-1 block max-w-xs truncate text-xs text-muted-foreground">{{ $transaction->status_message }}</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <span class="font-mono text-xs text-muted-foreground">{{ $transaction->reference }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($transactions->hasPages())
                    <div class="card-footer justify-center">
                        {{ $transactions->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</main>
@endsection
