@extends('layouts.app')

@section('content')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-red-50/30 flex items-center justify-center py-12 px-4">
    <div class="max-w-md w-full">
        <!-- Failed Animation -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-24 h-24 bg-red-100 rounded-full mb-4">
                <svg class="w-12 h-12 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Transaction Failed</h1>
            <p class="text-gray-600">We couldn't complete your transaction</p>
        </div>

        <!-- Error Message -->
        @if($transaction->failure_reason)
        <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-r-xl">
            <div class="flex">
                <div class="flex-shrink-0">
                    <span class="text-red-500 text-xl">⚠️</span>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-semibold text-red-800 mb-1">Error Details:</h3>
                    <p class="text-sm text-red-700">{{ $transaction->failure_reason }}</p>
                </div>
            </div>
        </div>
        @endif

        <!-- Transaction Details Card -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 overflow-hidden mb-6">
            <div class="bg-gradient-to-r from-red-500 to-red-600 px-6 py-4">
                <h2 class="text-white font-semibold text-lg">Transaction Details</h2>
            </div>
            
            <div class="p-6 space-y-4">
                <!-- Status -->
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Status</span>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                        Failed
                    </span>
                </div>

                <!-- Reference -->
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Reference</span>
                    <span class="font-mono text-sm text-gray-900">{{ $transaction->reference }}</span>
                </div>

                <!-- Service Type -->
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Service Type</span>
                    <span class="font-semibold text-gray-900">{{ ucfirst($transaction->service_type) }}</span>
                </div>

                <!-- Recipient -->
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Recipient</span>
                    <span class="font-semibold text-gray-900">{{ $transaction->recipient }}</span>
                </div>

                <!-- Network -->
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Network</span>
                    <span class="font-semibold text-gray-900">{{ $transaction->provider }}</span>
                </div>

                @if($transaction->plan_name)
                <!-- Plan Name -->
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Plan</span>
                    <span class="font-semibold text-gray-900 text-right">{{ $transaction->plan_name }}</span>
                </div>
                @endif

                <!-- Attempted Amount -->
                <div class="flex justify-between items-center pt-2">
                    <span class="text-gray-900 font-semibold">Attempted Amount</span>
                    <span class="font-bold text-gray-900 text-xl">₦{{ number_format($transaction->total_amount, 2) }}</span>
                </div>

                <!-- Balance Info -->
                <div class="bg-blue-50 rounded-xl p-4 mt-4">
                    <div class="flex justify-between items-center">
                        <span class="text-blue-800 font-medium">Your Wallet Balance</span>
                        <span class="font-bold text-blue-700 text-lg">₦{{ number_format(auth()->user()->wallet_balance, 2) }}</span>
                    </div>
                    <p class="text-xs text-blue-600 mt-2">No amount was deducted from your wallet</p>
                </div>

                <!-- Date & Time -->
                <div class="text-center text-xs text-gray-500 pt-4 border-t border-gray-100">
                    {{ $transaction->created_at->format('M d, Y • h:i A') }}
                </div>
            </div>
        </div>

        <!-- Common Issues -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6">
            <h3 class="font-semibold text-yellow-900 mb-2 flex items-center">
                <span class="text-lg mr-2">💡</span>
                Common Issues:
            </h3>
            <ul class="text-sm text-yellow-800 space-y-1">
                <li>• Check if the phone number is correct</li>
                <li>• Ensure sufficient wallet balance</li>
                <li>• Verify network selection matches phone number</li>
                <li>• Try again in a few moments</li>
            </ul>
        </div>

        <!-- Action Buttons -->
        <div class="space-y-3">
            <a href="{{ route('airtime-data.index') }}" 
               class="block w-full bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-bold py-4 px-6 rounded-xl text-center shadow-lg hover:shadow-xl transition-all duration-200">
                Try Again
            </a>
            
            <a href="{{ route('dashboard') }}" 
               class="block w-full bg-white hover:bg-gray-50 text-gray-700 font-semibold py-4 px-6 rounded-xl text-center border-2 border-gray-200 transition-all duration-200">
                Back to Dashboard
            </a>

            <a href="#" 
               class="block w-full text-center text-gray-600 hover:text-gray-900 font-medium text-sm py-2 transition-colors duration-200">
                Contact Support →
            </a>
        </div>
    </div>
</main>
@endsection