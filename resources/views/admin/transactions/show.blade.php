
{{-- `data-transaction-reference` is read by viewTransactionDetails() to fill the
     shell's subtitle. Without it the header stayed on "Loadingâ€¦" forever,
     because nothing replaced it once the content arrived. --}}
<div class="space-y-6" data-transaction-reference="{{ $transaction->reference }}">
    <!-- Header -->
    {{-- Stacks on a phone. Side by side, the four action buttons squeezed the
         heading into a few characters per line and then overflowed the sheet. --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-xl font-bold text-gray-900 sm:text-2xl">Transaction Details</h1>
            <p class="mt-1 break-all text-sm text-gray-600">Reference: {{ $transaction->reference }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if($transaction->status === 'pending')
                <button onclick="updateTransactionStatus({{ $transaction->id }}, 'success')" 
                        class="flex-1 rounded-lg bg-green-600 px-4 py-2 font-medium text-white transition-colors hover:bg-green-700 sm:flex-none">
                    Mark as Success
                </button>
                <button onclick="updateTransactionStatus({{ $transaction->id }}, 'failed')" 
                        class="flex-1 rounded-lg bg-red-600 px-4 py-2 font-medium text-white transition-colors hover:bg-red-700 sm:flex-none">
                    Mark as Failed
                </button>
            @endif
            <button onclick="closeTransactionModal()" 
                    class="flex-1 rounded-lg border border-gray-300 px-4 py-2 font-medium text-gray-700 transition-colors hover:bg-gray-50 sm:flex-none">
                Close
            </button>
        </div>
    </div>

    <!-- Status Badge -->
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        @if($transaction->status === 'pending')
            <span class="inline-flex items-center rounded-full bg-yellow-100 px-3 py-1.5 text-sm font-semibold text-yellow-800 sm:px-4 sm:py-2">
                <span class="mr-2 h-2 w-2 rounded-full bg-yellow-500"></span>
                Pending
            </span>
        @elseif($transaction->status === 'processing')
            <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1.5 text-sm font-semibold text-blue-800 sm:px-4 sm:py-2">
                <svg class="mr-2 h-3 w-3 animate-spin" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/>
                </svg>
                Processing
            </span>
        @elseif($transaction->status === 'success')
            <span class="inline-flex items-center rounded-full bg-green-100 px-3 py-1.5 text-sm font-semibold text-green-800 sm:px-4 sm:py-2">
                <svg class="mr-2 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                </svg>
                Success
            </span>
        @elseif($transaction->status === 'failed')
            <span class="inline-flex items-center rounded-full bg-red-100 px-3 py-1.5 text-sm font-semibold text-red-800 sm:px-4 sm:py-2">
                <svg class="mr-2 h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
                Failed
            </span>
        @else
            <span class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1.5 text-sm font-semibold text-gray-800 sm:px-4 sm:py-2">
                Cancelled
            </span>
        @endif
        @if($transaction->status_message)
        <p class="w-full text-sm text-gray-600 sm:w-auto">{{ $transaction->status_message }}</p>
        @endif
    </div>

    <!-- Main Details -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Transaction Details -->
        <div class="rounded-xl border border-gray-200 bg-surface p-4 shadow-sm sm:p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Transaction Information</h3>
            <div class="space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <span class="text-sm font-medium text-gray-600">Transaction ID</span>
                    <span class="font-mono text-sm font-bold text-gray-900">#{{ $transaction->id }}</span>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <span class="text-sm font-medium text-gray-600">Reference</span>
                    <span class="break-all text-right font-mono text-sm font-bold text-gray-900">{{ $transaction->reference }}</span>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <span class="text-sm font-medium text-gray-600">Service Type</span>
                    <span class="text-sm font-bold text-gray-900">{{ ucwords(str_replace('-', ' ', $transaction->service_type)) }}</span>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <span class="text-sm font-medium text-gray-600">Type</span>
                    <span class="text-sm font-bold text-gray-900">{{ ucfirst($transaction->type) }}</span>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <span class="text-sm font-medium text-gray-600">Description</span>
                    <span class="text-sm text-gray-900 text-right">{{ $transaction->description }}</span>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <span class="text-sm font-medium text-gray-600">Payment Method</span>
                    <span class="text-sm font-bold text-gray-900">{{ ucfirst(str_replace('_', ' ', $transaction->payment_method)) }}</span>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <span class="text-sm font-medium text-gray-600">Provider</span>
                    <span class="text-sm font-bold text-gray-900">{{ $transaction->provider }}</span>
                </div>
                <div class="flex items-start justify-between gap-3">
                    <span class="text-sm font-medium text-gray-600">Created</span>
                    <span class="text-sm text-gray-900">{{ $transaction->created_at->format('M d, Y h:i A') }}</span>
                </div>
                @if($transaction->completed_at)
                <div class="flex items-start justify-between gap-3">
                    <span class="text-sm font-medium text-gray-600">Completed</span>
                    <span class="text-sm text-gray-900">{{ $transaction->completed_at->format('M d, Y h:i A') }}</span>
                </div>
                @endif
            </div>
        </div>

        <!-- Financial Details -->
        <div class="rounded-xl border border-gray-200 bg-surface p-4 shadow-sm sm:p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Financial Information</h3>
            <div class="space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <span class="text-sm font-medium text-gray-600">Amount</span>
                    <span class="text-xl font-bold text-gray-900">â‚¦{{ number_format($transaction->amount, 2) }}</span>
                </div>
                @if($transaction->service_fee > 0)
                <div class="flex items-start justify-between gap-3">
                    <span class="text-sm font-medium text-gray-600">Service Fee</span>
                    <span class="text-sm font-bold text-gray-900">â‚¦{{ number_format($transaction->service_fee, 2) }}</span>
                </div>
                @endif
                <div class="flex items-start justify-between gap-3">
                    <span class="text-sm font-medium text-gray-600">Total Amount</span>
                    <span class="text-lg font-bold text-gray-900">â‚¦{{ number_format($transaction->total_amount, 2) }}</span>
                </div>
                @if($transaction->balance_before)
                <div class="flex items-start justify-between gap-3">
                    <span class="text-sm font-medium text-gray-600">Balance Before</span>
                    <span class="text-sm font-bold text-gray-900">â‚¦{{ number_format($transaction->balance_before, 2) }}</span>
                </div>
                @endif
                @if($transaction->balance_after)
                <div class="flex items-start justify-between gap-3">
                    <span class="text-sm font-medium text-gray-600">Balance After</span>
                    <span class="text-sm font-bold text-gray-900">â‚¦{{ number_format($transaction->balance_after, 2) }}</span>
                </div>
                @endif
                <div class="pt-4 border-t border-gray-200">
                    <h4 class="text-sm font-medium text-gray-900 mb-2">Payment Status</h4>
                    <div class="flex items-start justify-between gap-3">
                        <span class="text-sm text-gray-600">Status</span>
                        <span class="text-sm font-bold {{ $transaction->payment_status === 'success' ? 'text-success-soft-foreground' : 'text-red-600' }}">
                            {{ ucfirst($transaction->payment_status) }}
                        </span>
                    </div>
                    @if($transaction->paid_at)
                    <div class="mt-2 flex items-start justify-between gap-3">
                        <span class="text-sm text-gray-600">Paid At</span>
                        <span class="text-sm text-gray-900">{{ $transaction->paid_at->format('M d, Y h:i A') }}</span>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- User Information -->
        <div class="rounded-xl border border-gray-200 bg-surface p-4 shadow-sm sm:p-6 lg:col-span-2">
            <h3 class="text-lg font-bold text-gray-900 mb-4">User Information</h3>
            <div class="flex items-center space-x-4">
                @if($transaction->user)
                <div class="w-16 h-16 bg-gradient-to-br from-blue-400 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-xl">
                    {{ substr($transaction->user->name, 0, 1) }}
                </div>
                <div>
                    <h4 class="text-lg font-bold text-gray-900">{{ $transaction->user->name }}</h4>
                    <p class="text-sm text-gray-600">{{ $transaction->user->email }}</p>
                    <p class="text-sm text-gray-600 mt-1">User ID: {{ $transaction->user->id }}</p>
                    @if($transaction->user->phone)
                    <p class="text-sm text-gray-600 mt-1">Phone: {{ $transaction->user->phone }}</p>
                    @endif
                </div>
                @else
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                </div>
                <div>
                    <h4 class="text-lg font-bold text-gray-900">User Deleted</h4>
                    <p class="text-sm text-gray-600">User account no longer exists</p>
                </div>
                @endif
            </div>
        </div>

        <!-- Additional Information -->
        @if($transaction->recipient || $transaction->meta)
        <div class="rounded-xl border border-gray-200 bg-surface p-4 shadow-sm sm:p-6 lg:col-span-2">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Additional Information</h3>
            <div class="space-y-4">
                @if($transaction->recipient)
                <div>
                    <h4 class="text-sm font-medium text-gray-900 mb-1">Recipient</h4>
                    <p class="text-sm text-gray-600">{{ $transaction->recipient }}</p>
                </div>
                @endif
                
                @if($transaction->api_reference)
                <div>
                    <h4 class="text-sm font-medium text-gray-900 mb-1">API Reference</h4>
                    <p class="font-mono text-sm text-gray-600">{{ $transaction->api_reference }}</p>
                </div>
                @endif
                
                @if($transaction->meta)
                <div>
                    <h4 class="text-sm font-medium text-gray-900 mb-2">Metadata</h4>
                    <div class="bg-gray-50 rounded-lg p-4">
                        {{-- Already an array: Transactions casts meta to 'array', so
                             json_decode() here threw a TypeError. --}}
                        <pre class="text-xs text-gray-700 whitespace-pre-wrap">{{ json_encode($transaction->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                </div>
                @endif
                
                @if($transaction->api_response)
                <div>
                    <h4 class="text-sm font-medium text-gray-900 mb-2">API Response</h4>
                    <div class="bg-gray-50 rounded-lg p-4 max-h-40 overflow-y-auto">
                        {{-- `api_response` is cast to 'array'. Echoing it directly made
                             Blade call htmlspecialchars() on an array, which threw a
                             TypeError â€” and only for transactions that actually had a
                             gateway response, so it looked intermittent. --}}
                        <pre class="text-xs text-gray-700 whitespace-pre-wrap">{{ json_encode($transaction->api_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                </div>
                @endif
            </div>
        </div>
        @endif
    </div>

    <!-- Action Buttons -->
    {{-- Wraps, and goes full width on a phone. A single right-aligned row of
         these was wider than the sheet and pushed the first button off screen. --}}
    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 pt-4">
        {{-- Asks the gateway what actually happened and settles or fails the row
             accordingly. Safe to press repeatedly: it credits at most once, and
             a gateway that cannot be reached changes nothing. --}}
        <button type="button" id="refreshStatusButton" onclick="refreshTransactionStatus({{ $transaction->id }})"
                class="flex-1 rounded-lg border border-gray-300 px-4 py-2 font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 sm:flex-none">
            <span data-refresh-label>Refresh status</span>
        </button>
        @if($transaction->status === 'pending')
        <button onclick="forceSuccess({{ $transaction->id }})" 
                class="flex-1 rounded-lg bg-green-600 px-4 py-2 font-medium text-white transition-colors hover:bg-green-700 sm:flex-none">
            Force Success
        </button>
        <button onclick="forceFailed({{ $transaction->id }})" 
                class="flex-1 rounded-lg bg-red-600 px-4 py-2 font-medium text-white transition-colors hover:bg-red-700 sm:flex-none">
            Force Failed
        </button>
        <button onclick="cancelTransaction({{ $transaction->id }})" 
                class="flex-1 rounded-lg bg-gray-600 px-4 py-2 font-medium text-white transition-colors hover:bg-gray-700 sm:flex-none">
            Cancel Transaction
        </button>
        @endif
        <button onclick="closeTransactionModal()" 
                class="flex-1 rounded-lg border border-gray-300 px-4 py-2 font-medium text-gray-700 transition-colors hover:bg-gray-50 sm:flex-none">
            Close
        </button>
    </div>
</div>

