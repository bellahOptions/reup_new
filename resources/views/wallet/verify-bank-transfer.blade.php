@extends('layouts.app')
@section('content')
<div class="min-h-screen bg-gradient-to-br from-gray-50 to-blue-50 py-12">
    <div class="max-w-3xl mx-auto px-4">
        <div class="bg-white rounded-2xl shadow-xl border border-gray-200 overflow-hidden">
            <!-- Header -->
            <div class="bg-gradient-to-r from-green-500 to-green-600 p-8 text-center">
                <div class="w-20 h-20 bg-white/20 rounded-full mx-auto mb-4 flex items-center justify-center backdrop-blur-sm">
                    <span class="text-3xl text-white">🏦</span>
                </div>
                <h1 class="text-3xl font-bold text-white">Bank Transfer Instructions</h1>
                <p class="text-white/90 mt-2">Transfer to Paystack virtual account for instant verification</p>
            </div>
            
            <div class="p-8">
                <div class="space-y-8">
                    <!-- Transfer Now Button -->
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-6 border border-blue-200">
                        <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                            <div>
                                <h3 class="font-bold text-blue-900 text-lg">Ready to Transfer?</h3>
                                <p class="text-blue-700 text-sm mt-1">Use your bank app/website to make the transfer</p>
                            </div>
                            <button onclick="copyAllDetails()" 
                                    class="px-6 py-3 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-lg hover:from-blue-600 hover:to-blue-700 transition-all flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                </svg>
                                Copy All Details
                            </button>
                        </div>
                    </div>

                    <!-- Bank Details Card -->
                    <div class="bg-gradient-to-br from-gray-50 to-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="bg-gradient-to-r from-gray-100 to-gray-200 px-6 py-4 border-b border-gray-300">
                            <h2 class="text-xl font-bold text-gray-900">Transfer Details</h2>
                            <p class="text-gray-600 text-sm">Use these details exactly as shown</p>
                        </div>
                        
                        <div class="p-6 space-y-6">
                            <!-- Account Details -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-gray-500">Account Name</label>
                                    <div class="flex items-center justify-between bg-white border border-gray-300 rounded-lg p-4">
                                        <span class="text-lg font-bold text-gray-900">{{ config('wallet.bank.account_name') }}</span>
                                        <button onclick="copyToClipboard('{{ config('wallet.bank.account_name') }}')" 
                                                class="text-green-600 hover:text-green-700">
                                            Copy
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-gray-500">Account Number</label>
                                    <div class="flex items-center justify-between bg-white border border-gray-300 rounded-lg p-4">
                                        <span class="text-lg font-bold text-gray-900">{{ config('wallet.bank.account_number') }}</span>
                                        <button onclick="copyToClipboard('{{ config('wallet.bank.account_number') }}')" 
                                                class="text-green-600 hover:text-green-700">
                                            Copy
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Bank Info -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-500">Bank Name</label>
                                <div class="flex items-center justify-between bg-white border border-gray-300 rounded-lg p-4">
                                    <span class="text-lg font-bold text-gray-900">{{ config('wallet.bank.bank_name') }}</span>
                                </div>
                            </div>
                            
                            <!-- Narration -->
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-500">Payment Reference / Narration</label>
                                <div class="bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-lg p-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-lg font-bold text-green-900 font-mono">{{ $narration }}</span>
                                        <button onclick="copyToClipboard('{{ $narration }}')" 
                                                class="text-green-600 hover:text-green-700">
                                            Copy
                                        </button>
                                    </div>
                                    <p class="text-sm text-green-700">
                                        ⚠️ Important: Use this exact reference for auto-verification
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Summary -->
                    <div class="bg-gradient-to-br from-gray-50 to-white rounded-xl border border-gray-200 p-6">
                        <h3 class="text-xl font-bold text-gray-900 mb-4">Payment Summary</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                <span class="text-gray-600">Amount to Fund</span>
                                <span class="font-semibold text-gray-900">₦{{ number_format($transaction->amount, 2) }}</span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                <span class="text-gray-600">Processing Fee</span>
                                <span class="font-semibold text-gray-900">₦{{ number_format($transaction->service_fee, 2) }}</span>
                            </div>
                            <div class="flex justify-between items-center py-2">
                                <span class="text-lg font-bold text-gray-900">Total to Transfer</span>
                                <span class="text-2xl font-bold text-green-600">₦{{ number_format($transaction->total_amount, 2) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Important Instructions -->
                    <div class="bg-gradient-to-br from-yellow-50 to-amber-50 border border-yellow-200 rounded-xl p-6">
                        <h3 class="text-lg font-bold text-yellow-900 mb-4 flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.998-.833-2.732 0L4.342 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                            </svg>
                            Important Instructions
                        </h3>
                        <div class="space-y-3 text-sm text-yellow-800">
                            <div class="flex items-start gap-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-yellow-100 text-yellow-800 rounded-full flex items-center justify-center text-xs font-bold">1</span>
                                <div>
                                    <p class="font-medium">Transfer exactly: <strong class="text-green-700">₦{{ number_format($transaction->total_amount, 2) }}</strong></p>
                                    <p class="text-xs opacity-75">Any difference will delay verification</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-yellow-100 text-yellow-800 rounded-full flex items-center justify-center text-xs font-bold">2</span>
                                <div>
                                    <p class="font-medium">Use <strong class="font-mono">{{ $narration }}</strong> as narration</p>
                                    <p class="text-xs opacity-75">This triggers auto-verification</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-yellow-100 text-yellow-800 rounded-full flex items-center justify-center text-xs font-bold">3</span>
                                <div>
                                    <p class="font-medium">Auto-verification within minutes</p>
                                    <p class="text-xs opacity-75">Paystack will notify us instantly</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-yellow-100 text-yellow-800 rounded-full flex items-center justify-center text-xs font-bold">4</span>
                                <div>
                                    <p class="font-medium">No proof upload required</p>
                                    <p class="text-xs opacity-75">System verifies automatically via Paystack</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Status Tracker -->
                    <div class="bg-gradient-to-br from-green-50 to-emerald-50 border border-green-200 rounded-xl p-6">
                        <h3 class="text-lg font-bold text-green-900 mb-6 text-center">What Happens Next</h3>
                        <div class="relative">
                            <!-- Progress Line -->
                            <div class="absolute left-0 right-0 top-1/2 h-1 bg-gray-200 -translate-y-1/2"></div>
                            <div class="relative flex justify-between">
                                <!-- Step 1 -->
                                <div class="flex flex-col items-center z-10">
                                    <div class="w-10 h-10 rounded-full bg-green-500 border-4 border-white flex items-center justify-center mb-2">
                                        <span class="text-white font-bold">1</span>
                                    </div>
                                    <span class="text-xs font-medium text-green-900">Transfer</span>
                                    <span class="text-xs text-green-700">Make payment</span>
                                </div>
                                
                                <!-- Step 2 -->
                                <div class="flex flex-col items-center z-10">
                                    <div class="w-10 h-10 rounded-full bg-green-100 border-4 border-white flex items-center justify-center mb-2">
                                        <span class="text-green-600 font-bold">2</span>
                                    </div>
                                    <span class="text-xs font-medium text-gray-900">Verifying</span>
                                    <span class="text-xs text-gray-600">Auto-check by Paystack</span>
                                </div>
                                
                                <!-- Step 3 -->
                                <div class="flex flex-col items-center z-10">
                                    <div class="w-10 h-10 rounded-full bg-gray-100 border-4 border-white flex items-center justify-center mb-2">
                                        <span class="text-gray-400 font-bold">3</span>
                                    </div>
                                    <span class="text-xs font-medium text-gray-900">Completed</span>
                                    <span class="text-xs text-gray-600">Wallet credited</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <a href="{{ route('wallet.fund') }}" 
                           class="px-6 py-3 bg-gradient-to-r from-gray-200 to-gray-300 text-gray-800 rounded-lg hover:from-gray-300 hover:to-gray-400 transition-all flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                            </svg>
                            Back to Funding
                        </a>
                        <a href="{{ route('wallet.history') }}" 
                           class="px-6 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg hover:from-green-600 hover:to-green-700 transition-all flex items-center justify-center gap-2">
                            View Transactions
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </a>
                    </div>

                    <!-- Note -->
                    <div class="text-center">
                        <div class="inline-flex items-center bg-blue-100 text-blue-800 text-sm font-medium px-4 py-2 rounded-full">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Status: Waiting for Transfer
                        </div>
                        <p class="text-sm text-gray-600 mt-2">
                            Your wallet will be credited automatically once Paystack confirms your transfer.
                            <br>No manual verification needed!
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Copy individual field
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('Copied to clipboard!', 'success');
    }).catch(err => {
        console.error('Failed to copy: ', err);
        showToast('Failed to copy', 'error');
    });
}

// Copy all details at once
function copyAllDetails() {
    const details = [
        `Account Name: ${"{{ config('wallet.bank.account_name') }}"}`,
        `Account Number: ${"{{ config('wallet.bank.account_number') }}"}`,
        `Bank: ${"{{ config('wallet.bank.bank_name') }}"}`,
        `Amount: ₦${"{{ number_format($transaction->total_amount, 2) }}"}`,
        `Narration: ${"{{ $narration }}"}`
    ].join('\n');
    
    navigator.clipboard.writeText(details).then(() => {
        showToast('All details copied!', 'success');
    }).catch(err => {
        console.error('Failed to copy: ', err);
        showToast('Failed to copy details', 'error');
    });
}

// Show toast notification
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `fixed bottom-4 right-4 z-50 px-4 py-2 rounded-lg shadow-lg text-white ${
        type === 'success' ? 'bg-green-500' : 'bg-red-500'
    }`;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

// Auto-check payment status every 30 seconds
function checkPaymentStatus() {
    fetch(`{{ route('wallet.payment.check-status') }}`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            reference: '{{ $transaction->reference }}'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            // Redirect to success page
            window.location.href = data.redirect_url;
        }
    })
    .catch(error => console.error('Error checking status:', error));
}

// Start checking after 30 seconds
setTimeout(() => {
    checkPaymentStatus();
    // Check every 30 seconds
    setInterval(checkPaymentStatus, 30000);
}, 30000);
</script>
@endsection