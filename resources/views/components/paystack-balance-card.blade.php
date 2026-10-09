{{-- resources/views/components/paystack-balance-card.blade.php --}}
{{-- Simple Paystack Balance Card - Updates on page refresh only --}}

<div class="bg-surface rounded-2xl shadow-sm border border-gray-200 p-6">
    <div class="flex items-center space-x-3">
        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
            </svg>
        </div>
        <div class="flex-1">
            @if(isset($balance) && is_numeric($balance))
                <p class="text-2xl font-bold text-gray-900">
                    {{ $currency ?? 'NGN' }} {{ number_format($balance, 2) }}
                </p>
                <p class="text-sm text-gray-600">Paystack Balance</p>
            @else
                <p class="text-sm text-red-600 font-semibold">
                    Unable to fetch balance
                </p>
                <p class="text-xs text-gray-500">
                    @if(isset($stats['error']))
                        {{ Str::limit($stats['error'], 40) }}
                    @else
                        Refresh page to retry
                    @endif
                </p>
            @endif
        </div>
        
        {{-- Manual refresh button --}}
        <button onclick="window.location.reload()" 
                class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
                title="Refresh page to update balance">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
        </button>
    </div>
    
    {{-- Optional: Show stats if available --}}
    @if(isset($stats) && is_array($stats) && isset($stats['total_transactions']))
    <div class="mt-3 pt-3 border-t sm:hidden border-gray-100 grid grid-cols-2 gap-2 text-xs">
        <div>
            <span class="text-gray-500">Today's Transactions:</span>
            <span class="font-semibold text-gray-900 ml-1">{{ $stats['total_transactions'] }}</span>
        </div>
        <div>
            <span class="text-gray-500">Volume:</span>
            <span class="font-semibold text-gray-900 ml-1">₦{{ number_format($stats['total_volume'] ?? 0, 2) }}</span>
        </div>
    </div>
    @endif
    
    {{-- Cache notice --}}
    <p class="text-xs text-gray-400 mt-2">
        <svg class="w-3 h-3 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        Cached for 5 minutes • Refresh page for latest
    </p>
</div>