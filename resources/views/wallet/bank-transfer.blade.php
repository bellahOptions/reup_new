@extends('layouts.app')
@section('content')
<div class="min-h-screen bg-gray-50 py-12">
    <div class="max-w-3xl mx-auto px-4">
        <div class="bg-white rounded-2xl shadow-lg p-8">
            <div class="text-center mb-8">
                <div class="w-20 h-20 bg-blue-100 rounded-full mx-auto mb-4 flex items-center justify-center">
                    <span class="text-3xl">🏦</span>
                </div>
                <h1 class="text-2xl font-bold text-gray-900">Bank Transfer Instructions</h1>
                <p class="text-gray-600 mt-2">Complete your payment using the details below</p>
            </div>
            
            <div class="space-y-6">
                <!-- Payment Info -->
                <div class="bg-blue-50 rounded-xl p-6">
                    <h2 class="font-bold text-blue-900 mb-4">Transfer Details</h2>
                    
                    <div class="space-y-4">
                        <div>
                            <p class="text-sm text-blue-700 mb-1">Account Name</p>
                            <p class="text-xl font-bold text-blue-900">{{ config('wallet.bank.account_name') }}</p>
                        </div>
                        
                        <div>
                            <p class="text-sm text-blue-700 mb-1">Account Number</p>
                            <div class="flex items-center justify-between">
                                <p class="text-xl font-bold text-blue-900">{{ config('wallet.bank.account_number') }}</p>
                                <button onclick="copyToClipboard('{{ config('wallet.bank.account_number') }}')" 
                                        class="text-blue-600 hover:text-blue-700">
                                    Copy
                                </button>
                            </div>
                        </div>
                        
                        <div>
                            <p class="text-sm text-blue-700 mb-1">Bank Name</p>
                            <p class="text-xl font-bold text-blue-900">{{ config('wallet.bank.bank_name') }}</p>
                        </div>
                        
                        <div class="bg-green-50 rounded-lg p-4 mt-4">
                            <p class="text-sm text-green-700 mb-1">Payment Reference / Narration</p>
                            <div class="flex items-center justify-between">
                                <p class="text-xl font-bold text-green-900">{{ $narration }}</p>
                                <button onclick="copyToClipboard('{{ $narration }}')" 
                                        class="text-green-600 hover:text-green-700">
                                    Copy
                                </button>
                            </div>
                            <p class="text-xs text-green-600 mt-2">Use this exact reference when transferring</p>
                        </div>
                    </div>
                </div>
                
                <!-- Transaction Info -->
                <div class="border border-gray-200 rounded-xl p-6">
                    <h2 class="font-bold text-gray-900 mb-4">Transaction Summary</h2>
                    
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Amount to Fund:</span>
                            <span class="font-semibold">₦{{ number_format($transaction->amount, 2) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Processing Fee:</span>
                            <span class="font-semibold">₦{{ number_format($transaction->fee, 2) }}</span>
                        </div>
                        <div class="border-t pt-3 flex justify-between">
                            <span class="font-bold text-gray-900">Total to Pay:</span>
                            <span class="text-xl font-bold text-green-600">₦{{ number_format($transaction->total_amount, 2) }}</span>
                        </div>
                    </div>
                </div>
                
                <!-- Instructions -->
                <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6">
                    <h3 class="font-bold text-yellow-900 mb-3">Important Instructions</h3>
                    <ul class="space-y-2 text-sm text-yellow-800">
                        <li class="flex items-start">
                            <span class="mr-2">1.</span>
                            <span>Transfer the exact amount: <strong>₦{{ number_format($transaction->total_amount, 2) }}</strong></span>
                        </li>
                        <li class="flex items-start">
                            <span class="mr-2">2.</span>
                            <span>Use <strong>{{ $narration }}</strong> as narration/payment reference</span>
                        </li>
                        <li class="flex items-start">
                            <span class="mr-2">3.</span>
                            <span>After transferring, upload proof below for verification</span>
                        </li>
                        <li class="flex items-start">
                            <span class="mr-2">4.</span>
                            <span>Your wallet will be credited within 24 hours after verification</span>
                        </li>
                    </ul>
                </div>
                
                <!-- Upload Proof Form -->
                <div class="border border-gray-200 rounded-xl p-6"> 
                    <h3 class="font-bold text-gray-900 mb-4">Upload Transfer Proof</h3>
                    
                    <form action="{{ route('wallet.bank-transfer.submit-proof') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="transaction_reference" value="{{ $transaction->reference }}">
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Screenshot/Receipt
                            </label>
                            <input type="file" name="proof" accept="image/*,.pdf" required
                                   class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                            <p class="text-xs text-gray-500 mt-1">Upload a screenshot or PDF of your transfer receipt</p>
                        </div>
                        
                        <button type="submit" 
                                class="w-full bg-green-500 hover:bg-green-600 text-white font-medium py-3 px-4 rounded-lg transition-all duration-200">
                            Submit Proof for Verification
                        </button>
                    </form>
                </div>
                
                <!-- Status Info -->
                <div class="text-center">
                    <div class="inline-flex items-center bg-blue-100 text-blue-800 text-sm font-medium px-4 py-2 rounded-full">
                        <span class="w-2 h-2 bg-blue-600 rounded-full mr-2"></span>
                        Status: Pending
                    </div>
                    <p class="text-sm text-gray-600 mt-2">Your transaction will appear as pending until verified</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('Copied to clipboard!');
    }).catch(err => {
        console.error('Failed to copy: ', err);
    });
}
</script>
@endsection