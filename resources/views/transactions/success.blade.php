@extends('layouts.app')

@section('content')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30 flex items-center justify-center py-12 px-4">
    <div class="max-w-md w-full">
        <!-- Success Animation -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-24 h-24 bg-green-100 rounded-full mb-4 animate-bounce">
                <svg class="w-12 h-12 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-gray-900 mb-2">Transaction Successful! 🎉</h1>
            <p class="text-gray-600">Your transaction has been completed successfully</p>
        </div>

        <!-- Transaction Details Card -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 overflow-hidden mb-6">
            <div class="bg-gradient-to-r from-green-500 to-emerald-600 px-6 py-4">
                <h2 class="text-white font-semibold text-lg">Transaction Details</h2>
            </div>
            
            <div class="p-6 space-y-4">
                <!-- Transaction Type -->
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Service Type</span>
                    <span class="font-semibold text-gray-900">{{ ucfirst($transaction->service_type) }}</span>
                </div>

                <!-- Reference -->
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Reference</span>
                    <span class="font-mono text-sm text-gray-900">{{ $transaction->reference }}</span>
                </div>

                @if($transaction->api_reference)
                <!-- Order ID -->
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Order ID</span>
                    <span class="font-mono text-sm text-gray-900">{{ $transaction->api_reference }}</span>
                </div>
                @endif

                <!-- Recipient -->
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Recipient</span>
                    <span class="font-semibold text-gray-900">{{ $transaction->recipient }}</span>
                </div>

                <!-- Provider -->
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Network</span>
                    <span class="font-semibold text-gray-900">{{ $transaction->provider }}</span>
                </div>

                @if($transaction->plan_name)
                <!-- Plan Name (for data) -->
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Plan</span>
                    <span class="font-semibold text-gray-900 text-right">{{ $transaction->plan_name }}</span>
                </div>
                @endif

                <!-- Amount -->
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Amount</span>
                    <span class="font-semibold text-gray-900">₦{{ number_format($transaction->amount, 2) }}</span>
                </div>

                <!-- Service Fee -->
                <div class="flex justify-between items-center pb-3 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Service Fee</span>
                    <span class="font-semibold text-gray-900">₦{{ number_format($transaction->service_fee, 2) }}</span>
                </div>

                <!-- Total Amount -->
                <div class="flex justify-between items-center pt-2">
                    <span class="text-gray-900 font-semibold">Total Paid</span>
                    <span class="font-bold text-green-600 text-xl">₦{{ number_format($transaction->total_amount, 2) }}</span>
                </div>

                <!-- New Balance -->
                <div class="bg-green-50 rounded-xl p-4 mt-4">
                    <div class="flex justify-between items-center">
                        <span class="text-green-800 font-medium">New Wallet Balance</span>
                        <span class="font-bold text-green-700 text-lg">₦{{ number_format($transaction->balance_after, 2) }}</span>
                    </div>
                </div>

                <!-- Date & Time -->
                <div class="text-center text-xs text-gray-500 pt-4 border-t border-gray-100">
                    {{ $transaction->created_at->format('M d, Y • h:i A') }}
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="space-y-3">
            <a href="{{ route('airtime-data.index') }}" 
               class="block w-full bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-bold py-4 px-6 rounded-xl text-center shadow-lg hover:shadow-xl transition-all duration-200">
                Make Another Purchase
            </a>
            
            <a href="{{ route('dashboard') }}" 
               class="block w-full bg-white hover:bg-gray-50 text-gray-700 font-semibold py-4 px-6 rounded-xl text-center border-2 border-gray-200 transition-all duration-200">
                Back to Dashboard
            </a>

            <a href="{{ route('transactions.index') }}" 
               class="block w-full text-center text-gray-600 hover:text-gray-900 font-medium text-sm py-2 transition-colors duration-200">
                View Transaction History →
            </a>
        </div>
    </div>
</main>
@endsection