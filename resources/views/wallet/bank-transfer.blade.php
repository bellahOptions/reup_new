@extends('layouts.app')
@section('title', 'Bank transfer')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Bank transfer instructions
    |--------------------------------------------------------------------------
    | Reached two ways, both supplying $transaction, $bank_details, $narration and
    | $amount:
    |   - WalletController::initiateBankTransfer()  (POST wallet.process-funding)
    |   - WalletController::showBankTransferDetails() (GET wallet.bank-transfer.details)
    |
    | Everything that used to be a hand-inlined raw <svg> is now an <x-icon>,
    | the onclick="copyToClipboard(...)" handlers are gone (Alpine owns copying),
    | and the status poller is Alpine + fetch against wallet.payment.check-status.
    | No new third-party script is introduced.
    */
    $fundingReference = $transaction->reference;
    $totalToTransfer = (float) $transaction->total_amount;

    /*
     * Preferred path: a Paystack dedicated virtual account (DVA), generated and
     * resolved through PaystackController::webhook() on `channel =
     * dedicated_nuban`. Because the account number itself identifies the
     * customer, the narration is no longer what triggers verification — so the
     * copy below switches to account-number matching when a DVA is present.
     *
     * Fallback: the static company account from config('wallet.bank'), used when
     * Paystack could not issue a DVA ($dva_error is set). That path still needs
     * the narration, because the shared account cannot identify the payer.
     */
    $hasDva = !empty($virtual_account['account_number'] ?? null);
    $dva_error = $dva_error ?? null;

    $transferDetails = $hasDva
        ? [
            ['label' => 'Account name', 'value' => $virtual_account['account_name'] ?: $bank_details['account_name'], 'copyable' => true],
            ['label' => 'Account number', 'value' => $virtual_account['account_number'], 'copyable' => true, 'mono' => true],
            ['label' => 'Bank', 'value' => $virtual_account['bank_name'] ?: 'Paystack partner bank', 'copyable' => false],
            ['label' => 'Amount', 'value' => '₦' . number_format($totalToTransfer, 2), 'copyable' => true, 'copyValue' => number_format($totalToTransfer, 2), 'mono' => true],
        ]
        : [
            ['label' => 'Account name', 'value' => $bank_details['account_name'], 'copyable' => true],
            ['label' => 'Account number', 'value' => $bank_details['account_number'], 'copyable' => true, 'mono' => true],
            ['label' => 'Bank', 'value' => $bank_details['bank_name'], 'copyable' => false],
            ['label' => 'Amount', 'value' => '₦' . number_format($totalToTransfer, 2), 'copyable' => true, 'copyValue' => number_format($totalToTransfer, 2), 'mono' => true],
            ['label' => 'Narration / reference', 'value' => $narration, 'copyable' => true, 'mono' => true],
        ];

    // Serialised once for Alpine; every clipboard write is built from this so
    // no PHP string is interpolated into a JS template literal.
    $clipboardPayload = [
        'accountName' => $hasDva ? $virtual_account['account_name'] : $bank_details['account_name'],
        'accountNumber' => $hasDva ? $virtual_account['account_number'] : $bank_details['account_number'],
        'bankName' => $hasDva ? $virtual_account['bank_name'] : $bank_details['bank_name'],
        'narration' => $hasDva ? '' : $narration,
        'amount' => number_format($totalToTransfer, 2),
    ];
@endphp

<main class="min-h-screen bg-surface"
      x-data="{
        copied: null,
        status: @js($transaction->status),
        checking: false,
        payload: @js($clipboardPayload),
        reference: @js($fundingReference),
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
            const p = this.payload;
            const lines = [
                'Account name: ' + p.accountName,
                'Account number: ' + p.accountNumber,
                'Bank: ' + p.bankName,
                'Amount: \u20A6' + p.amount
            ];
            if (p.narration) lines.push('Narration: ' + p.narration);
            this.write(lines.join('\n'), 'all');
        },
        async checkStatus() {
            if (this.status === 'success') return;
            this.checking = true;
            try {
                const response = await fetch(@js(route('wallet.payment.check-status')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': @js(csrf_token())
                    },
                    body: JSON.stringify({ reference: this.reference })
                });
                const data = await response.json();
                if (data.status) this.status = data.status;
                if (data.status === 'success') {
                    window.location.href = @js(route('wallet.history'));
                }
            } catch (e) {
                // A failed poll is not an error the customer needs to see; the
                // next tick retries and the page still shows the full details.
            } finally {
                this.checking = false;
            }
        },
        init() {
            this.checkTimer = setInterval(() => this.checkStatus(), 30000);
        },
        destroy() {
            clearInterval(this.checkTimer);
        },
        get statusBadge() {
            const map = {
                success: 'badge-success',
                pending: 'badge-warning',
                processing: 'badge-info',
                verifying: 'badge-info',
                failed: 'badge-destructive',
                cancelled: 'badge-neutral'
            };
            return map[this.status] || 'badge-neutral';
        },
        get statusLabel() {
            return this.status ? this.status.charAt(0).toUpperCase() + this.status.slice(1) : 'Pending';
        }
      }">
    <div class="container-page py-8 md:py-12">

        {{-- Page heading --}}
        <header class="mb-8 flex flex-wrap items-end justify-between gap-4 md:mb-10">
            <div>
                <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Bank transfer instructions</h1>
                <p class="mt-2 max-w-2xl text-sm text-muted-foreground md:text-base">
                    @if($hasDva)
                        This account number was issued for your wallet only. Transfer the exact total below and we
                        credit you automatically — no proof upload and no narration needed.
                    @else
                        Transfer the exact total below to the account shown. We match it automatically using the
                        narration, so your wallet is credited without any proof upload.
                    @endif
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('wallet.fund') }}" class="btn btn-outline">
                    <x-icon name="arrow-left" class="h-4 w-4" />
                    Back to funding
                </a>
                <a href="{{ route('wallet.history') }}" class="btn btn-primary">
                    View transactions
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
            </div>
        </header>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">

                @if($dva_error)
                    {{-- Paystack could not issue a personal account; the shared
                         company account below still works but needs the narration. --}}
                    <div class="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
                         role="status">
                        <x-icon name="exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0" />
                        <div class="min-w-0">
                            <p class="font-medium">We could not issue your personal account number</p>
                            <p class="mt-1 text-amber-800">{{ $dva_error }}</p>
                        </div>
                    </div>
                @endif

                {{-- Transfer details --}}
                <section class="card">
                    <div class="card-header flex-row items-start justify-between gap-3">
                        <div>
                            <h2 class="card-title">Transfer details</h2>
                            <p class="card-description">
                                @if($hasDva)
                                    This account is reserved for you.
                                @else
                                    Use these exactly as shown.
                                @endif
                            </p>
                        </div>
                        <button type="button" @click="copyAll()" class="btn btn-outline btn-sm shrink-0">
                            <x-icon name="document-duplicate" class="h-4 w-4" />
                            <span x-show="copied !== 'all'">Copy all details</span>
                            <span x-show="copied === 'all'" x-cloak>Copied</span>
                        </button>
                    </div>

                    <dl class="divide-y divide-border">
                        @foreach($transferDetails as $index => $detail)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                <div class="min-w-0">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                        {{ $detail['label'] }}
                                    </dt>
                                    <dd class="mt-1 text-base font-semibold {{ !empty($detail['mono']) ? 'font-mono' : '' }}">
                                        {{ $detail['value'] }}
                                    </dd>
                                </div>
                                @if($detail['copyable'])
                                    <button type="button"
                                            data-copy="{{ $detail['copyValue'] ?? $detail['value'] }}"
                                            @click="copyField($el.dataset.copy, 'field-{{ $index }}')"
                                            class="btn btn-ghost btn-sm shrink-0">
                                        <x-icon name="document-duplicate" class="h-4 w-4" />
                                        <span x-show="copied !== 'field-{{ $index }}'">Copy</span>
                                        <span x-show="copied === 'field-{{ $index }}'" x-cloak>Copied</span>
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </dl>
                </section>

                {{-- Payment summary --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Payment summary</h2>
                    </div>
                    <dl class="card-content space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-muted-foreground">Amount to fund</dt>
                            <dd class="font-medium tabular-nums">&#8358;{{ number_format((float) $transaction->amount, 2) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-muted-foreground">Processing fee</dt>
                            <dd class="font-medium tabular-nums">&#8358;{{ number_format((float) $transaction->service_fee, 2) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 border-t border-border pt-3">
                            <dt class="font-medium">Total to transfer</dt>
                            <dd class="text-lg font-semibold tabular-nums text-brand-700">
                                &#8358;{{ number_format($totalToTransfer, 2) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                {{-- Instructions --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Important instructions</h2>
                    </div>
                    <ol class="card-content space-y-4 text-sm">
                        @php
                            $instructions = $hasDva
                                ? [
                                    ['Transfer exactly &#8358;' . number_format($totalToTransfer, 2), 'Any difference delays automatic verification.'],
                                    ['Use the account number <span class="font-mono">' . e($virtual_account['account_number']) . '</span>', 'It is unique to your wallet, so no narration is needed.'],
                                    ['Verification happens within minutes', 'Paystack notifies us the moment the transfer lands.'],
                                    ['No proof upload needed', 'The inbound transfer is credited to your wallet automatically.'],
                                ]
                                : [
                                    ['Transfer exactly &#8358;' . number_format($totalToTransfer, 2), 'Any difference delays automatic verification.'],
                                    ['Use <span class="font-mono">' . e($narration) . '</span> as the narration', 'This is what triggers auto-verification.'],
                                    ['Verification happens within minutes', 'Our provider notifies us as soon as the transfer lands.'],
                                    ['No proof upload needed', 'The transfer is matched automatically against this reference.'],
                                ];
                        @endphp
                        @foreach($instructions as $index => $instruction)
                            <li class="flex items-start gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700">{{ $index + 1 }}</span>
                                <span class="min-w-0">
                                    <span class="block font-medium">{!! $instruction[0] !!}</span>
                                    <span class="block text-xs text-muted-foreground">{{ $instruction[1] }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </section>
            </div>

            {{-- Sidebar --}}
            <div class="space-y-6">

                {{-- Status --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">What happens next</h2>
                    </div>
                    <div class="card-content space-y-5">
                        <ol class="space-y-4 text-sm">
                            @foreach([
                                ['Transfer', 'You send the exact total.'],
                                ['Verifying', $hasDva ? 'Paystack matches the inbound transfer.' : 'The provider matches the narration.'],
                                ['Completed', 'Your wallet is credited.'],
                            ] as $index => $step)
                                <li class="flex items-start gap-3">
                                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold
                                                 {{ $index === 0 ? 'bg-brand-500 text-white' : 'bg-ink-100 text-ink-600' }}">
                                        {{ $index + 1 }}
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block font-medium">{{ $step[0] }}</span>
                                        <span class="block text-xs text-muted-foreground">{{ $step[1] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ol>

                        <div class="divider"></div>

                        <div class="flex flex-wrap items-center gap-2">
                            <span class="badge" :class="statusBadge">
                                <x-icon name="arrow-path" class="h-3.5 w-3.5" x-show="checking" x-cloak />
                                <x-icon name="clock" class="h-3.5 w-3.5" x-show="!checking" />
                                <span x-text="statusLabel"></span>
                            </span>
                            <button type="button" @click="checkStatus()" class="btn btn-ghost btn-sm">
                                Check now
                            </button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            This page re-checks every 30 seconds. You will be taken to your wallet history as soon
                            as the transfer is confirmed.
                        </p>
                    </div>
                </section>

                {{-- Reference --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Keep this safe</h2>
                    </div>
                    <div class="card-content space-y-3 text-sm">
                        <div class="rounded-lg border border-border bg-surface p-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Reference</p>
                            <p class="mt-1 break-all font-mono text-sm font-semibold">{{ $fundingReference }}</p>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Quote this reference if you need to contact support about the transfer.
                        </p>
                        <a href="{{ route('wallet.fund') }}" class="btn btn-outline btn-sm w-full">
                            <x-icon name="arrow-path" class="h-4 w-4" />
                            Start a different funding
                        </a>
                    </div>
                </section>
            </div>
        </div>
    </div>
</main>
@endsection
