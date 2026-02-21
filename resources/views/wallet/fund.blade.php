@extends('layouts.app')
@section('content')
<!-- SweetAlert2 for modals -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Check for success modal
    @if(session('modal_success'))
        Swal.fire({
            icon: 'success',
            title: 'Payment Successful!',
            html: `
                <div class="text-left">
                    <p class="mb-3">{{ session('modal_success') }}</p>
                    <div class="bg-green-50 border border-green-200 rounded-lg p-3 mt-2">
                        <p class="text-sm font-medium text-green-800">Current Balance: ₦{{ number_format($wallet->balance, 2) }}</p>
                    </div>
                </div>
            `,
            confirmButtonText: 'Great!',
            confirmButtonColor: '#10B981',
            allowOutsideClick: false,
            allowEscapeKey: false,
            willOpen: () => {
                // Update balance display if needed
                const balanceEl = document.querySelector('.balance-display');
                if (balanceEl) {
                    balanceEl.textContent = '₦{{ number_format($wallet->balance, 2) }}';
                }
            }
        });
    @endif
    
    // Check for error modal
    @if(session('modal_error'))
        Swal.fire({
            icon: 'error',
            title: 'Payment Failed',
            html: `
                <div class="text-left">
                    <p class="mb-3">{{ session('modal_error') }}</p>
                    <div class="bg-red-50 border border-red-200 rounded-lg p-3 mt-2">
                        <p class="text-sm text-red-700">If you were debited, please contact support immediately.</p>
                    </div>
                </div>
            `,
            confirmButtonText: 'Try Again',
            confirmButtonColor: '#EF4444',
            showCancelButton: true,
            cancelButtonText: 'View History',
            cancelButtonColor: '#6B7280',
            showDenyButton: true,
            denyButtonText: 'Contact Support',
            denyButtonColor: '#3B82F6',
            allowOutsideClick: false
        }).then((result) => {
            if (result.isDenied) {
                window.location.href = '/support';
            } else if (result.isDismissed || result.isCanceled) {
                window.location.href = '{{ route('wallet.history') }}';
            }
            // If confirmed (Try Again), just close the modal and stay on page
        });
    @endif
    
    // Check for regular success messages
    @if(session('success'))
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '{{ session('success') }}',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });
    @endif
    
    // Check for regular error messages
    @if(session('error'))
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: '{{ session('error') }}',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true
        });
    @endif
});
</script>
<main class="min-h-screen w-full overflow-hidden bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8 md:py-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Page Header -->
            <div class="mb-8 md:mb-12">
                <div class="flex flex-col md:flex-row space-y-5 text-center md:text-left items-center space-x-3 mb-4">
                    <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center shadow-lg">
                        <span class="text-2xl">💰</span>
                    </div>
                    <div>
                        <h1 class="text-2xl md:text-4xl font-bold text-gray-900">Fund Your Wallet</h1>
                        <p class="text-gray-600 text-sm md:text-base mt-1">Add money securely using multiple payment methods</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">
                <!-- Main Form Section -->
                <div class="lg:col-span-2">
                    <!-- Fund Wallet Card -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 overflow-hidden">
                        <div class="p-6 md:p-8">
                            <form id="fundWalletForm" method="POST" action="{{ route('wallet.process-funding') }}" class="space-y-6">
                                @csrf
                                
                                <!-- Show validation errors -->
                                @if($errors->any())
                                <div class="bg-red-50 border border-red-200 rounded-xl p-4">
                                    <div class="flex items-start">
                                        <span class="text-red-600 mr-2">⚠️</span>
                                        <div class="text-sm text-red-800">
                                            @foreach($errors->all() as $error)
                                                <p>{{ $error }}</p>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                @endif
                                
                                <!-- Current Balance -->
                                <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                                    <div class="flex flex-col md:flex-row items-center justify-between">
                                        <div class="flex flex-col md:flex-row space-y-4 items-center space-x-3">
                                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                                <span class="text-xl">💰</span>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-gray-900">Current Balance</p>
                                                <p class="text-sm text-gray-600">Available for spending</p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-2xl font-bold text-green-600">₦{{ number_format($wallet->balance ?? 0, 2) }}</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Amount Selection -->
                                <div>
                                    <label for="amount" class="block text-sm font-semibold text-gray-700 mb-2">Amount to Add (₦)</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-700 font-semibold">₦</span>
                                        </div>
                                        <input type="number" id="amount" name="amount" placeholder="5000" 
                                               min="{{ $min_amount }}" 
                                               max="{{ $max_amount }}"
                                               value="{{ old('amount') }}"
                                               class="block w-full pl-12 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                                               required>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">
                                        💡 Minimum: ₦{{ number_format($min_amount) }} • Maximum: ₦{{ number_format($max_amount) }}
                                    </p>
                                    
                                    <!-- Quick Amount Buttons -->
                                    <div class="mt-3">
                                        <label class="block text-sm font-medium text-gray-600 mb-2">Quick Select</label>
                                        <div class="grid grid-cols-3 md:grid-cols-5 gap-2">
                                            <button type="button" class="quick-amount py-2 px-3 border-2 border-gray-200 rounded-lg hover:border-green-500 hover:bg-green-50 transition-all duration-200 text-sm font-semibold" data-amount="500">₦500</button>
                                            <button type="button" class="quick-amount py-2 px-3 border-2 border-gray-200 rounded-lg hover:border-green-500 hover:bg-green-50 transition-all duration-200 text-sm font-semibold" data-amount="1000">₦1K</button>
                                            <button type="button" class="quick-amount py-2 px-3 border-2 border-gray-200 rounded-lg hover:border-green-500 hover:bg-green-50 transition-all duration-200 text-sm font-semibold" data-amount="2000">₦2K</button>
                                            <button type="button" class="quick-amount py-2 px-3 border-2 border-gray-200 rounded-lg hover:border-green-500 hover:bg-green-50 transition-all duration-200 text-sm font-semibold" data-amount="5000">₦5K</button>
                                            <button type="button" class="quick-amount py-2 px-3 border-2 border-gray-200 rounded-lg hover:border-green-500 hover:bg-green-50 transition-all duration-200 text-sm font-semibold" data-amount="10000">₦10K</button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Payment Method Selection -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">Select Payment Method</label>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <!-- Paystack Payment -->
                                        <label class="payment-method-card cursor-pointer">
                                            <input type="radio" name="payment_method" value="paystack" class="hidden payment-method-radio" required checked>
                                            <div class="payment-method-option p-4 border-2 border-green-500 bg-green-50 rounded-xl hover:border-green-400 transition-all duration-300">
                                                <div class="flex items-center space-x-3">
                                                    <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center">
                                                        <span class="text-xl text-orange-600">⚡</span>
                                                    </div>
                                                    <div class="flex-1">
                                                        <p class="font-semibold text-gray-900">Paystack</p>
                                                        <p class="text-xs text-gray-600">Card/Bank/USSD • Instant</p>
                                                    </div>
                                                    <span class="text-green-500 text-xl">✓</span>
                                                </div>
                                            </div>
                                        </label>
                                        
                                        <!-- Bank Transfer -->
                                        <label class="payment-method-card cursor-pointer">
                                            <input type="radio" name="payment_method" value="bank_transfer" class="hidden payment-method-radio" required>
                                            <div class="payment-method-option p-4 border-2 border-gray-200 rounded-xl hover:border-blue-400 transition-all duration-300">
                                                <div class="flex items-center space-x-3">
                                                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                                        <span class="text-xl text-blue-600">🏦</span>
                                                    </div>
                                                    <div class="flex-1">
                                                        <p class="font-semibold text-gray-900">Bank Transfer</p>
                                                        <p class="text-xs text-gray-600">Manual • Free • 24hr verification</p>
                                                    </div>
                                                    <span class="text-green-500 text-xl hidden">✓</span>
                                                </div>
                                            </div>
                                        </label>
                                    </div>
                                </div>

                                <!-- Summary -->
                                <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between">
                                            <span class="text-gray-700">Amount to Fund:</span>
                                            <span class="font-semibold text-gray-900" id="displayAmount">₦0.00</span>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <span class="text-gray-700">Processing Fee:</span>
                                            <span class="font-semibold text-gray-900" id="processingFee">₦0.00</span>
                                        </div>
                                        <div class="border-t border-gray-200 pt-2 mt-2 flex items-center justify-between">
                                            <span class="font-bold text-gray-900">Total to Pay:</span>
                                            <span class="text-xl font-bold text-green-600" id="totalToPay">₦0.00</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Submit Button -->
                                <button type="submit" id="proceedBtn" class="w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-bold py-4 px-6 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-200 flex items-center justify-center space-x-2">
                                    <span id="btnText">Proceed to Payment</span>
                                    <span>🚀</span>
                                </button>

                                <!-- Security Notice -->
                                <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
                                    <div class="flex items-start space-x-2">
                                        <span class="text-blue-600 text-xl">🔒</span>
                                        <div class="flex-1">
                                            <p class="font-semibold text-blue-900 mb-1">Secure Payment</p>
                                            <p class="text-sm text-blue-800">Your payment is protected with bank-level security. We never store your card details.</p>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Payment Guidelines -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">📋</span>
                            Payment Guidelines
                        </h3>
                        <div class="space-y-3 text-sm text-gray-600">
                            <div class="flex items-start space-x-2">
                                <div class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs font-bold mt-0.5">1</div>
                                <div>
                                    <p class="font-medium text-gray-900">Select Amount</p>
                                    <p class="text-xs">Choose how much to add (₦{{ number_format($min_amount) }} minimum)</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-2">
                                <div class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs font-bold mt-0.5">2</div>
                                <div>
                                    <p class="font-medium text-gray-900">Choose Method</p>
                                    <p class="text-xs">Paystack (instant) or Bank Transfer (24hr)</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-2">
                                <div class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs font-bold mt-0.5">3</div>
                                <div>
                                    <p class="font-medium text-gray-900">Complete Payment</p>
                                    <p class="text-xs">Follow instructions for your method</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-2">
                                <div class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs font-bold mt-0.5">4</div>
                                <div>
                                    <p class="font-medium text-gray-900">Wallet Credited</p>
                                    <p class="text-xs">Instant for Paystack, 24hr for bank transfer</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Processing Fees -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">💰</span>
                            Processing Fees
                        </h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="text-gray-700">Paystack</span>
                                <span class="font-medium text-gray-900">{{ $paystack_fee['percentage'] }}% + ₦{{ number_format($paystack_fee['additional']) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-gray-700">Bank Transfer</span>
                                <span class="font-medium text-green-600">₦{{ number_format($bank_fee['fixed'] ?? 0) }}</span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-3">*Fees are automatically calculated</p>
                    </div>

                    <!-- Recent Funding -->
                    @if($recent_funding && $recent_funding->count() > 0)
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">📊</span>
                            Recent Funding
                        </h3>
                        <div class="space-y-2 text-sm">
                            @foreach($recent_funding as $funding)
                            <div class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                                <div>
                                    <p class="font-medium text-gray-900">₦{{ number_format($funding->amount, 2) }}</p>
                                    <p class="text-xs text-gray-500">{{ $funding->created_at->format('M d, Y') }}</p>
                                </div>
                                <span class="text-xs px-2 py-1 rounded-full 
                                    {{ $funding->status === 'success' ? 'bg-green-100 text-green-800' : '' }}
                                    {{ $funding->status === 'pending' ? 'bg-yellow-100 text-yellow-800' : '' }}
                                    {{ $funding->status === 'verifying' ? 'bg-blue-100 text-blue-800' : '' }}
                                    {{ $funding->status === 'failed' ? 'bg-red-100 text-red-800' : '' }}">
                                    {{ ucfirst($funding->status) }}
                                </span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    <!-- Support -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">🛟</span>
                            Need Help?
                        </h3>
                        <div class="space-y-2 text-sm text-gray-600">
                            <p>For payment issues or questions:</p>
                            <div class="bg-green-50 rounded-lg p-3">
                                <p class="font-medium text-green-900 mb-1">Support Team</p>
                                <p class="text-xs">📞 {{ $siteSettings['contact_phone'] ?? '' }}</p>
                                <p class="text-xs">✉️ {{ $siteSettings['support_email'] ?? '' }}</p>
                                <p class="text-xs">🕐 24/7 Support</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
/* Payment Method Selection */
.payment-method-radio:checked + .payment-method-option {
    @apply border-green-500 bg-green-50 shadow-md;
}

.payment-method-radio:checked + .payment-method-option .hidden {
    @apply inline-block;
}

/* Quick Amount Selection */
.quick-amount.active {
    @apply border-green-500 bg-green-50 text-green-700;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Elements
    const form = document.getElementById('fundWalletForm');
    const amountInput = document.getElementById('amount');
    const quickAmounts = document.querySelectorAll('.quick-amount');
    const displayAmountEl = document.getElementById('displayAmount');
    const processingFeeEl = document.getElementById('processingFee');
    const totalToPayEl = document.getElementById('totalToPay');
    const paymentMethodRadios = document.querySelectorAll('.payment-method-radio');
    const proceedBtn = document.getElementById('proceedBtn');
    const btnText = document.getElementById('btnText');
    
    // Configuration from backend
    const CONFIG = {
        minAmount: {{ $min_amount }},
        maxAmount: {{ $max_amount }},
        fees: {
            paystack: {
                percentage: {{ $paystack_fee['percentage'] }},
                additional: {{ $paystack_fee['additional'] }},
                cap: {{ $paystack_fee['cap'] ?? 2000 }}
            },
            bank_transfer: {
                fixed: {{ $bank_fee['fixed'] ?? 0 }}
            }
        }
    };
    
    let selectedPaymentMethod = 'paystack';
    
    // Quick amount selection
    quickAmounts.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const amount = this.getAttribute('data-amount');
            amountInput.value = amount;
            
            // Update active state
            quickAmounts.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            updateCalculations();
        });
    });

    // Amount input change
    amountInput.addEventListener('input', function() {
        // Remove active state from quick amounts
        quickAmounts.forEach(b => b.classList.remove('active'));
        updateCalculations();
    });

    // Payment method selection
    paymentMethodRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            selectedPaymentMethod = this.value;
            updateCalculations();
            
            // Update button text based on method
            if (selectedPaymentMethod === 'bank_transfer') {
                btnText.textContent = 'Get Bank Details';
            } else {
                btnText.textContent = 'Proceed to Payment';
            }
        });
    });

    // Calculate and update amounts
    function updateCalculations() {
        const amount = parseFloat(amountInput.value) || 0;
        let fee = 0;
        
        if (amount > 0) {
            if (selectedPaymentMethod === 'paystack') {
                // Calculate Paystack fee
                fee = (amount * CONFIG.fees.paystack.percentage / 100) + CONFIG.fees.paystack.additional;
                
                // Apply cap
                if (fee > CONFIG.fees.paystack.cap) {
                    fee = CONFIG.fees.paystack.cap;
                }
                
                fee = Math.round(fee * 100) / 100; // Round to 2 decimals
            } else if (selectedPaymentMethod === 'bank_transfer') {
                fee = CONFIG.fees.bank_transfer.fixed;
            }
        }
        
        const total = amount + fee;
        
        // Update display
        displayAmountEl.textContent = formatCurrency(amount);
        processingFeeEl.textContent = formatCurrency(fee);
        totalToPayEl.textContent = formatCurrency(total);
    }

    // Format currency
    function formatCurrency(amount) {
        return '₦' + amount.toLocaleString('en-NG', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // Form validation before submit
    form.addEventListener('submit', function(e) {
        const amount = parseFloat(amountInput.value) || 0;
        
        if (amount < CONFIG.minAmount) {
            e.preventDefault();
            alert(`Minimum funding amount is ₦${CONFIG.minAmount.toLocaleString()}`);
            amountInput.focus();
            return false;
        }
        
        if (amount > CONFIG.maxAmount) {
            e.preventDefault();
            alert(`Maximum funding amount is ₦${CONFIG.maxAmount.toLocaleString()}`);
            amountInput.focus();
            return false;
        }
        
        // Disable button to prevent double submission
        proceedBtn.disabled = true;
        btnText.textContent = 'Processing...';
        proceedBtn.classList.add('opacity-75', 'cursor-not-allowed');
        
        return true;
    });

    // Initialize calculations
    updateCalculations();
    
    // Set initial value if available (from old input)
    @if(old('amount'))
        amountInput.value = {{ old('amount') }};
        updateCalculations();
    @endif
});

// Function to update wallet balance display
function updateBalanceDisplay(newBalance) {
    const balanceElements = document.querySelectorAll('.balance-display, .wallet-balance');
    balanceElements.forEach(el => {
        el.textContent = '₦' + parseFloat(newBalance).toLocaleString('en-NG', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    });
    
    // Also update any quick stats
    const quickBalance = document.querySelector('[data-balance]');
    if (quickBalance) {
        quickBalance.textContent = '₦' + parseFloat(newBalance).toLocaleString('en-NG', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
}
</script>

@endsection