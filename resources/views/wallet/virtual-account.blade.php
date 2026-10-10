@extends('layouts.app')
@section('title', 'Your personal account')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Dedicated virtual account (pay-in account)
    |--------------------------------------------------------------------------
    | Rendered by WalletController::renderVirtualAccount(), which supplies:
    |   $wallet, $virtual_account (nullable), $bank_details, $paystack_enabled,
    |   $dva_warning (nullable), $recentTransfers
    |
    | The account is issued by the controller on first visit, so there is no
    | "generate" button in the happy path — the page either has an account to
    | show or an explanation of why it could not be issued.
    |
    | Funding is automatic: Paystack sends a `charge.success` webhook with
    | `channel = dedicated_nuban` and the wallet is credited by
    | PaystackService::creditDedicatedAccountTransfer(). Nothing on this page
    | has to be submitted, and no amount is fixed in advance — the customer
    | transfers whatever they want.
    */
    $hasAccount = !empty($virtual_account['account_number'] ?? null);

    $details = $hasAccount
        ? [
            ['label' => 'Account number', 'value' => $virtual_account['account_number'], 'mono' => true],
            ['label' => 'Bank', 'value' => $virtual_account['bank_name'] ?: 'Paystack partner bank', 'mono' => false],
            ['label' => 'Account name', 'value' => $virtual_account['account_name'] ?: $bank_details['account_name'], 'mono' => false],
        ]
        : [];

    // Serialised once, so no PHP string is interpolated into a JS template
    // literal and every clipboard write is built from the same payload.
    $clipboardPayload = $hasAccount
        ? implode("\n", [
            'Account number: ' . $virtual_account['account_number'],
            'Bank: ' . ($virtual_account['bank_name'] ?: 'Paystack partner bank'),
            'Account name: ' . ($virtual_account['account_name'] ?: $bank_details['account_name']),
        ])
        : '';

    $balance = (float) ($wallet->balance ?? 0);
@endphp

<main class="min-h-screen bg-surface"
      x-data="{
        copied: null,
        payload: @js($clipboardPayload),
        async write(value, key) {
            try {
                await navigator.clipboard.writeText(value);
                this.copied = key;
                setTimeout(() => { if (this.copied === key) this.copied = null; }, 2000);
            } catch (e) {
                this.copied = null;
            }
        },
        copyField(value, key) {
            this.write(value, key);
        },
        copyAll() {
            this.write(this.payload, 'all');
        }
      }">
    <div class="container-page py-8 md:py-12">

        {{-- Page heading --}}
        <header class="mb-8 flex flex-wrap items-end justify-between gap-4 md:mb-10">
            <div>
                <p class="text-sm font-medium text-brand-700">Funding</p>
                <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Your personal account</h1>
                <p class="mt-2 max-w-2xl text-sm text-muted-foreground md:text-base">
                    @if($hasAccount)
                        This account number belongs to your ReUp wallet only. Transfer any amount to it and
                        your wallet is credited automatically — no proof upload, no narration, no waiting for
                        an administrator.
                    @else
                        A personal account number lets you fund your wallet by ordinary bank transfer, from any
                        bank app, at any time.
                    @endif
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('wallet.index') }}" class="btn btn-outline">
                    <x-icon name="arrow-left" class="h-4 w-4" />
                    Back to wallet
                </a>
                <a href="{{ route('wallet.fund') }}" class="btn btn-primary">
                    <x-icon name="plus" class="h-4 w-4" />
                    Other funding options
                </a>
            </div>
        </header>

        @if($dva_warning)
            {{-- The account could not be issued. Not an error page: card funding
                 and the shared account still work, so the customer is told what
                 happened and pointed at what they can do instead. --}}
            <div class="mb-6 flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
                 role="status">
                <x-icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0" />
                <div class="min-w-0">
                    <p class="font-medium">We could not issue your personal account number</p>
                    <p class="mt-1 text-amber-800">{{ $dva_warning }}</p>
                    @if($paystack_enabled)
                        <form method="POST" action="{{ route('wallet.virtual-account.store', [], false) }}" class="mt-3">
                            @csrf
                            <button type="submit" class="btn btn-outline btn-sm">
                                <x-icon name="arrow-path" class="h-4 w-4" />
                                Try again
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">

                @if($hasAccount)
                    {{-- The account itself: the single thing this page exists for. --}}
                    <section class="card">
                        <div class="card-header flex-row items-start justify-between gap-3">
                            <div>
                                <h2 class="card-title">Transfer to this account</h2>
                                <p class="card-description">Reserved for you. It does not expire.</p>
                            </div>
                            <button type="button" @click="copyAll()" class="btn btn-outline btn-sm shrink-0">
                                <x-icon name="document-duplicate" class="h-4 w-4" />
                                <span x-show="copied !== 'all'">Copy all</span>
                                <span x-show="copied === 'all'" x-cloak>Copied</span>
                            </button>
                        </div>

                        <dl class="divide-y divide-border">
                            @foreach($details as $index => $detail)
                                <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                    <div class="min-w-0">
                                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                            {{ $detail['label'] }}
                                        </dt>
                                        <dd class="mt-1 text-base font-semibold {{ $detail['mono'] ? 'font-mono text-lg tabular-nums' : '' }}">
                                            {{ $detail['value'] }}
                                        </dd>
                                    </div>
                                    <button type="button"
                                            data-copy="{{ $detail['value'] }}"
                                            @click="copyField($el.dataset.copy, 'field-{{ $index }}')"
                                            class="btn btn-ghost btn-sm shrink-0"
                                            aria-label="Copy {{ strtolower($detail['label']) }}">
                                        <x-icon name="document-duplicate" class="h-4 w-4" />
                                        <span x-show="copied !== 'field-{{ $index }}'">Copy</span>
                                        <span x-show="copied === 'field-{{ $index }}'" x-cloak>Copied</span>
                                    </button>
                                </div>
                            @endforeach
                        </dl>

                        <div class="card-footer flex-wrap gap-2 text-xs text-muted-foreground">
                            <x-icon name="lock-closed" class="h-4 w-4 shrink-0 text-brand-600" />
                            <span>
                                The account number is the only thing that identifies your transfer, so there is
                                nothing else to get right. Any amount is accepted.
                            </span>
                        </div>
                    </section>

                    {{-- How it works --}}
                    <section class="card">
                        <div class="card-header">
                            <h2 class="card-title">How funding by transfer works</h2>
                        </div>
                        <ol class="card-content space-y-4 text-sm">
                            @foreach([
                                ['Open your bank app', 'Transfer any amount to the account number above, from any bank.'],
                                ['We are notified automatically', 'Paystack tells us the moment the transfer lands — usually within a minute or two.'],
                                ['Your wallet is credited', 'The full amount you sent is added to your Reup balance, with no fee deducted.'],
                            ] as $index => $step)
                                <li class="flex items-start gap-3">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700">
                                        {{ $index + 1 }}
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block font-medium">{{ $step[0] }}</span>
                                        <span class="block text-xs text-muted-foreground">{{ $step[1] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @else
                    {{-- No account yet, and nothing to show. --}}
                    <section class="card">
                        <div class="empty-state">
                            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-ink-100 text-ink-500">
                                <x-icon name="building-library" class="h-6 w-6" />
                            </span>
                            <h2 class="mt-4 text-base font-semibold">No personal account yet</h2>
                            <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                                @if($paystack_enabled)
                                    We could not issue one when you arrived. Try again, and if it still does not
                                    work, fund with a card or contact support.
                                @else
                                    Personal account numbers are not enabled on this deployment yet. You can still
                                    fund with a card, or by transfer to the account shown on the funding page.
                                @endif
                            </p>
                            <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                                @if($paystack_enabled)
                                    <form method="POST" action="{{ route('wallet.virtual-account.store', [], false) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <x-icon name="arrow-path" class="h-4 w-4" />
                                            Generate my account
                                        </button>
                                    </form>
                                @endif
                                <a href="{{ route('wallet.fund') }}" class="btn btn-outline btn-sm">
                                    <x-icon name="credit-card" class="h-4 w-4" />
                                    Fund another way
                                </a>
                            </div>
                        </div>
                    </section>
                @endif

                {{-- Transfers already received --}}
                <section class="card overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                        <div>
                            <h2 class="card-title">Transfers received</h2>
                            <p class="card-description">The last few deposits into this wallet.</p>
                        </div>
                        <a href="{{ route('wallet.history') }}" class="btn btn-outline btn-sm shrink-0">
                            Full history
                            <x-icon name="arrow-right" class="h-4 w-4" />
                        </a>
                    </div>

                    @if($recentTransfers->isEmpty())
                        <div class="px-5 py-10 text-center">
                            <p class="text-sm text-muted-foreground">
                                Nothing yet. Your first transfer will appear here the moment it lands.
                            </p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th scope="col">Received</th>
                                        <th scope="col">Reference</th>
                                        <th scope="col" class="text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentTransfers as $transfer)
                                        <tr>
                                            <td class="whitespace-nowrap">
                                                <span class="block text-sm">{{ $transfer->created_at->format('M j, Y') }}</span>
                                                <span class="block text-xs text-muted-foreground">{{ $transfer->created_at->format('g:i A') }}</span>
                                            </td>
                                            <td>
                                                <span class="block font-mono text-xs text-muted-foreground">
                                                    {{ $transfer->reference }}
                                                </span>
                                                @if($transfer->description)
                                                    <span class="mt-0.5 block text-xs text-muted-foreground">
                                                        {{ $transfer->description }}
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="whitespace-nowrap text-right">
                                                <span class="text-sm font-semibold tabular-nums text-green-700">
                                                    +&#8358;{{ number_format((float) $transfer->amount, 2) }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </div>

            {{-- Sidebar --}}
            <div class="space-y-6">

                {{-- Balance --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Wallet balance</h2>
                    </div>
                    <div class="card-content">
                        <p class="text-2xl font-semibold tabular-nums">&#8358;{{ number_format($balance, 2) }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Updated as soon as a transfer is credited.
                        </p>
                        <a href="{{ route('wallet.fund') }}" class="btn btn-outline btn-sm mt-4 w-full">
                            <x-icon name="credit-card" class="h-4 w-4" />
                            Fund with a card instead
                        </a>
                    </div>
                </section>

                {{-- Notes --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Good to know</h2>
                    </div>
                    <ul class="card-content space-y-3 text-sm text-muted-foreground">
                        <li class="flex items-start gap-2.5">
                            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                            <span>Bank transfers to your personal account carry no ReUp fee — you receive the
                                full amount you send.</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                            <span>There is no amount to specify up front and no reference to type. Send what you
                                like, when you like.</span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                            <span>Your account number is unique to your wallet. Sharing it lets someone else fund
                                your wallet, nothing more.</span>
                        </li>
                    </ul>
                </section>

                {{-- Support --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Transfer not showing?</h2>
                    </div>
                    <div class="card-content space-y-3 text-sm">
                        <p class="text-muted-foreground">
                            Credits normally appear within a minute or two. If it has been longer, contact support
                            with the reference from your bank's receipt and we will trace it.
                        </p>
                        <a href="{{ route('contact') }}" class="btn btn-outline btn-sm w-full">
                            <x-icon name="lifebuoy" class="h-4 w-4" />
                            Contact support
                        </a>
                    </div>
                </section>
            </div>
        </div>
    </div>
</main>
@endsection
