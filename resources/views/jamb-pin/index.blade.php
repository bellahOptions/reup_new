@extends('layouts.app')
@section('content')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8 md:py-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Page Header -->
            <div class="mb-8 md:mb-12">
                <div class="flex items-center space-x-3 mb-4">
                    <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg">
                        <span class="text-2xl">🎓</span>
                    </div>
                    <div>
                        <h1 class="text-2xl md:text-4xl font-bold text-gray-900">Purchase JAMB e-PIN</h1>
                        <p class="text-gray-600 text-sm md:text-base mt-1">Instant Activation • Official UTME/DE Registration PIN</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">
                <!-- Main Form Section -->
                <div class="lg:col-span-2">
                    <!-- Purchase Form Card -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 overflow-hidden">
                        <div class="p-6 md:p-8">
                            <form id="jambForm" action="{{ route('jamb.purchase') }}" method="POST" class="space-y-6">
                                @csrf
                                
                                <!-- Payment Option -->
                                <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                                <span class="text-xl">💰</span>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-gray-900">Payment Option</p>
                                                <p class="text-sm text-gray-600">Wallet Balance</p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-2xl font-bold text-green-600">₦{{ number_format(auth()->user()->wallet_balance ?? 0, 2) }}</p>
                                            <a href="{{ route('wallet.fund') }}" class="text-sm text-green-600 hover:text-green-700 font-medium">+ Fund Wallet</a>
                                        </div>
                                    </div>
                                </div>

                                <!-- Exam Type Selection -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">Exam Type</label>
                                    <div id="examTypeLoader" class="text-center py-4 hidden">
                                        <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-green-600"></div>
                                        <p class="text-gray-600 mt-2 text-sm">Loading exam types...</p>
                                    </div>
                                    <div id="examTypeOptions" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <!-- Will be populated by JavaScript -->
                                    </div>
                                </div>

                                <!-- JAMB Profile ID -->
                                <div>
                                    <label for="profile_id" class="block text-sm font-semibold text-gray-700 mb-2">
                                        JAMB Profile ID
                                        <span class="text-xs font-normal text-gray-500">(10-character code from JAMB registration)</span>
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-400">🆔</span>
                                        </div>
                                        <input type="text" id="profile_id" name="profile_id" placeholder="e.g., ABC1234567" 
                                               class="block w-full pl-12 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                                               required maxlength="10" pattern="[A-Z0-9]{10}">
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">💡 This is required for e-PIN generation</p>
                                </div>

                                <!-- Verify Profile ID Button -->
                                <div>
                                    <button type="button" id="verifyProfileBtn" class="w-full bg-green-500 hover:bg-green-600 text-white font-semibold py-3 px-6 rounded-xl shadow-md hover:shadow-lg transform hover:scale-[1.02] transition-all duration-200 flex items-center justify-center space-x-2">
                                        <span>🔍 Verify Profile ID</span>
                                    </button>
                                </div>

                                <!-- Profile Verification Result -->
                                <div id="profileInfo" class="hidden bg-green-50 border border-green-200 rounded-xl p-4">
                                    <div class="flex items-start space-x-3">
                                        <span class="text-2xl">✓</span>
                                        <div class="flex-1">
                                            <p class="font-semibold text-green-900 mb-2">Profile Verified</p>
                                            <div class="space-y-1 text-sm text-gray-700">
                                                <p><span class="font-medium">Candidate Name:</span> <span id="candidateName">Loading...</span></p>
                                                <p><span class="font-medium">Status:</span> <span id="profileStatus" class="text-green-600 font-medium">Verified</span></p>
                                                <p><span class="font-medium">Eligible for:</span> <span id="eligibleExam">UTME</span></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Phone Number -->
                                <div>
                                    <label for="phone" class="block text-sm font-semibold text-gray-700 mb-2">Phone Number</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-400">📱</span>
                                        </div>
                                        <input type="tel" id="phone" name="phone" placeholder="08012345678" 
                                               class="block w-full pl-12 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                                               required maxlength="11" pattern="[0-9]{11}">
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">For SMS delivery of e-PIN</p>
                                </div>

                                <!-- Amount Display -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">Amount</label>
                                    <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                                        <div class="space-y-2">
                                            <div class="flex items-center justify-between">
                                                <span class="text-gray-700">e-PIN Cost:</span>
                                                <span class="font-semibold text-gray-900" id="pinCost">₦0.00</span>
                                            </div>
                                            <div class="flex items-center justify-between">
                                                <span class="text-gray-700">Service Fee:</span>
                                                <span class="font-semibold text-gray-900" id="serviceFee">₦0.00</span>
                                            </div>
                                            <div class="border-t border-green-200 pt-2 mt-2 flex items-center justify-between">
                                                <span class="font-bold text-gray-900">Total Amount:</span>
                                                <span class="text-xl font-bold text-green-600" id="totalAmount">₦0.00</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Request ID (Hidden) -->
                                <input type="hidden" id="request_id" name="request_id" value="{{ uniqid('JAMB_') }}">

                                <!-- Submit Button -->
                                <button type="submit" class="w-full bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-bold py-4 px-6 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-200 flex items-center justify-center space-x-2">
                                    <span>Pay Now</span>
                                    <span>🚀</span>
                                </button>

                                <!-- Important Notes -->
                                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                                    <div class="flex items-start space-x-2">
                                        <span class="text-amber-600 text-xl">📌</span>
                                        <div class="flex-1">
                                            <p class="font-semibold text-amber-900 mb-2">Important Information</p>
                                            <ul class="space-y-1 text-sm text-amber-800">
                                                <li class="flex items-start">
                                                    <span class="mr-2">•</span>
                                                    <span>e-PIN will be delivered via SMS within 5 minutes</span>
                                                </li>
                                                <li class="flex items-start">
                                                    <span class="mr-2">•</span>
                                                    <span>Ensure JAMB Profile ID is correct before payment</span>
                                                </li>
                                                <li class="flex items-start">
                                                    <span class="mr-2">•</span>
                                                    <span>Use the e-PIN to complete registration on JAMB portal</span>
                                                </li>
                                                <li class="flex items-start">
                                                    <span class="mr-2">•</span>
                                                    <span>No refunds after e-PIN has been generated</span>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Sidebar - Info & Recent -->
                <div class="space-y-6">
                    <!-- Recent Transactions -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">📊</span>
                            Recent Transactions
                        </h3>
                        <div class="space-y-3 text-sm">
                            <div id="recentTransactions" class="max-h-60 overflow-y-auto">
                                <!-- Will be populated by JavaScript -->
                                <div class="text-center py-4 text-gray-500">
                                    No recent transactions
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- How It Works -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">⚡</span>
                            How It Works
                        </h3>
                        <div class="space-y-3">
                            <div class="flex items-start space-x-2">
                                <div class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs font-bold">
                                    1
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Verify Profile ID</p>
                                    <p class="text-xs text-gray-600">Validate with JAMB database</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-2">
                                <div class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs font-bold">
                                    2
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Make Payment</p>
                                    <p class="text-xs text-gray-600">Instant wallet deduction</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-2">
                                <div class="w-6 h-6 bg-green-100 text-green-600 rounded-full flex items-center justify-center text-xs font-bold">
                                    3
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">Receive e-PIN</p>
                                    <p class="text-xs text-gray-600">Via SMS within 5 minutes</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Support Info -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">💬</span>
                            API Information
                        </h3>
                        <div class="space-y-2 text-sm text-gray-600">
                            <div class="flex items-center justify-between">
                                <span>Status:</span>
                                <span id="apiStatus" class="text-green-600 font-medium">● Connected</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Provider:</span>
                                <span class="font-medium">Nellobytes</span>
                            </div>
                            <div class="text-xs text-gray-500 mt-2">
                                Powered by official JAMB e-PIN integration
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
/* Exam Type Selection */
.exam-radio:checked + .exam-option {
    @apply border-green-500 bg-green-50 shadow-md;
}

/* Transaction Status Colors */
.status-success {
    @apply text-green-600 bg-green-50;
}
.status-pending {
    @apply text-amber-600 bg-amber-50;
}
.status-failed {
    @apply text-red-600 bg-red-50;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const jambForm = document.getElementById('jambForm');
    const verifyProfileBtn = document.getElementById('verifyProfileBtn');
    const profileInfo = document.getElementById('profileInfo');
    const profileIdInput = document.getElementById('profile_id');
    const candidateNameEl = document.getElementById('candidateName');
    const profileStatusEl = document.getElementById('profileStatus');
    const eligibleExamEl = document.getElementById('eligibleExam');
    const phoneInput = document.getElementById('phone');
    const pinCostEl = document.getElementById('pinCost');
    const serviceFeeEl = document.getElementById('serviceFee');
    const totalAmountEl = document.getElementById('totalAmount');
    const examTypeOptions = document.getElementById('examTypeOptions');
    const examTypeLoader = document.getElementById('examTypeLoader');
    const recentTransactions = document.getElementById('recentTransactions');
    const apiStatus = document.getElementById('apiStatus');
    const requestIdInput = document.getElementById('request_id');
    
    // API Configuration
    const API_CONFIG = {
        USER_ID: '{{ env("CLUBKONNECT_CLIENT_ID") }}',
        API_KEY: '{{ env("CLUBKONNECT_API_KEY") }}',
        BASE_URL: 'https://www.nellobytesystems.com'
    };

    // Check API credentials
    if (!API_CONFIG.USER_ID || !API_CONFIG.API_KEY) {
        alert('API credentials not configured. Please contact administrator.');
        document.querySelectorAll('input, select, button').forEach(el => {
            el.disabled = true;
        });
        apiStatus.textContent = '● Disconnected';
        apiStatus.className = 'text-red-600 font-medium';
        return;
    }

    // Store exam types and packages
    let examTypes = [];
    let selectedExamType = null;

    // Load exam types from API
    async function loadExamTypes() {
        examTypeLoader.classList.remove('hidden');
        examTypeOptions.innerHTML = '';

        try {
            const response = await fetch(
                `${API_CONFIG.BASE_URL}/APIJAMBPackagesV2.asp?UserID=${encodeURIComponent(API_CONFIG.USER_ID)}`
            );
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            console.log('Exam types response:', data);
            
            // Assuming the API returns an array of exam types
            // Adjust this based on actual API response structure
            if (data && data.JAMB_ID) {
                examTypes = Object.keys(data.JAMB_ID).map(key => {
                    const exam = data.JAMB_ID[key][0];
                    return {
                        code: key.toLowerCase(),
                        name: key,
                        price: parseFloat(exam.PACKAGE_AMOUNT) || 0,
                        discount_price: parseFloat(exam.PRODUCT_DISCOUNT_AMOUNT) || 0
                    };
                });
                
                // Display exam types
                displayExamTypes();
            } else {
                // Fallback to default exam types if API doesn't return expected format
                examTypes = [
                    { code: 'utme', name: 'UTME', price: 6200, discount_price: 6200 },
                    { code: 'de', name: 'Direct Entry', price: 15700, discount_price: 15700 }
                ];
                displayExamTypes();
            }
            
        } catch (error) {
            console.error('Error loading exam types:', error);
            // Use default exam types on error
            examTypes = [
                { code: 'utme', name: 'UTME', price: 6200, discount_price: 6200 },
                { code: 'de', name: 'Direct Entry', price: 15700, discount_price: 15700 }
            ];
            displayExamTypes();
        } finally {
            examTypeLoader.classList.add('hidden');
        }
    }

    // Display exam types
    function displayExamTypes() {
        examTypeOptions.innerHTML = '';
        
        examTypes.forEach(exam => {
            const label = document.createElement('label');
            label.className = 'exam-card cursor-pointer';
            
            const input = document.createElement('input');
            input.type = 'radio';
            input.name = 'exam_type';
            input.value = exam.code;
            input.className = 'hidden exam-radio';
            input.required = true;
            
            const optionDiv = document.createElement('div');
            optionDiv.className = 'exam-option p-4 border-2 border-gray-200 rounded-xl hover:border-green-400 transition-all duration-300';
            
            optionDiv.innerHTML = `
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                            <span class="text-xl">${exam.code === 'utme' ? '📝' : '🎯'}</span>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900">${exam.name}</p>
                            <p class="text-xs text-gray-600">₦${exam.price.toLocaleString('en-NG')}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold text-green-600">₦${exam.discount_price.toLocaleString('en-NG')}</p>
                        <p class="text-xs text-gray-500">Discounted</p>
                    </div>
                </div>
            `;
            
            label.appendChild(input);
            label.appendChild(optionDiv);
            examTypeOptions.appendChild(label);
            
            // Add event listener to the radio button
            input.addEventListener('change', function() {
                selectedExamType = exam;
                updateAmounts();
            });
        });
        
        // Select first exam type by default
        if (examTypes.length > 0) {
            const firstInput = examTypeOptions.querySelector('.exam-radio');
            if (firstInput) {
                firstInput.checked = true;
                selectedExamType = examTypes[0];
                updateAmounts();
            }
        }
    }

    // Update amounts based on selected exam
    function updateAmounts() {
        if (!selectedExamType) {
            pinCostEl.textContent = '₦0.00';
            serviceFeeEl.textContent = '₦0.00';
            totalAmountEl.textContent = '₦0.00';
            return;
        }
        
        const pinCost = selectedExamType.discount_price;
        const serviceFee = pinCost * 0.02; // 2% service fee
        const total = pinCost + serviceFee;

        pinCostEl.textContent = `₦${pinCost.toLocaleString('en-NG', {minimumFractionDigits: 2})}`;
        serviceFeeEl.textContent = `₦${serviceFee.toLocaleString('en-NG', {minimumFractionDigits: 2})}`;
        totalAmountEl.textContent = `₦${total.toLocaleString('en-NG', {minimumFractionDigits: 2})}`;
    }

// Verify Profile ID with API (SECURE - calls your Laravel backend)
verifyProfileBtn.addEventListener('click', async function() {
    const profileId = profileIdInput.value.trim().toUpperCase();
    
    if (!profileId || !/^[A-Z0-9]{10}$/.test(profileId)) {
        alert('Please enter a valid 10-character JAMB Profile ID (letters and numbers only)');
        return;
    }

    if (!selectedExamType) {
        alert('Please select an exam type first');
        return;
    }

    // Show loading state
    verifyProfileBtn.innerHTML = '<span>🔄 Verifying...</span>';
    verifyProfileBtn.disabled = true;
    profileInfo.classList.add('hidden');

    try {
        // Call YOUR Laravel backend endpoint, not the API directly
        const response = await fetch('{{ route("jamb.verify-profile") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({
                profile_id: profileId,
                exam_type: selectedExamType.code
            })
        });
        
        const data = await response.json();
        console.log('Verification response:', data);
        
        if (data.success && data.data.customer_name && data.data.customer_name !== 'INVALID_ACCOUNTNO') {
            // Success - Show candidate info
            candidateNameEl.textContent = data.data.customer_name;
            profileStatusEl.textContent = 'Verified';
            eligibleExamEl.textContent = selectedExamType.name;
            profileInfo.classList.remove('hidden');
            
            // Update button to show verified
            verifyProfileBtn.innerHTML = '<span>✓ Verified</span>';
            verifyProfileBtn.classList.remove('bg-green-500', 'hover:bg-green-600');
            verifyProfileBtn.classList.add('bg-green-600', 'hover:bg-green-700');
            
        } else {
            // Error - Invalid profile ID
            alert('Invalid JAMB Profile ID. Please check and try again.');
            verifyProfileBtn.innerHTML = '<span>🔍 Verify Profile ID</span>';
            profileInfo.classList.add('hidden');
        }
        
    } catch (error) {
        console.error('Verification error:', error);
        alert('Error verifying Profile ID. Please try again.');
        verifyProfileBtn.innerHTML = '<span>🔍 Verify Profile ID</span>';
    } finally {
        verifyProfileBtn.disabled = false;
    }
});

    // Load recent transactions
    async function loadRecentTransactions() {
        try {
            // In a real app, you would fetch from your backend
            // This is a simulation
            const transactions = [
                { id: '1', type: 'UTME', amount: 6200, phone: '08012345678', status: 'success', date: '2024-01-15' },
                { id: '2', type: 'Direct Entry', amount: 15700, phone: '08098765432', status: 'success', date: '2024-01-14' },
            ];
            
            if (transactions.length > 0) {
                recentTransactions.innerHTML = '';
                transactions.forEach(trans => {
                    const div = document.createElement('div');
                    div.className = 'flex items-center justify-between py-2 border-b border-gray-100';
                    div.innerHTML = `
                        <div>
                            <p class="font-medium text-gray-900">${trans.type} e-PIN</p>
                            <p class="text-xs text-gray-500">${trans.phone}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-green-600 font-semibold">₦${trans.amount.toLocaleString('en-NG')}</p>
                            <span class="text-xs px-2 py-1 rounded-full ${trans.status === 'success' ? 'status-success' : 'status-pending'}">
                                ${trans.status}
                            </span>
                        </div>
                    `;
                    recentTransactions.appendChild(div);
                });
            }
        } catch (error) {
            console.error('Error loading transactions:', error);
        }
    }

    // Phone number validation
    phoneInput.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
        if (this.value.length > 11) {
            this.value = this.value.slice(0, 11);
        }
    });

    // Profile ID validation
    profileIdInput.addEventListener('input', function() {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (this.value.length > 10) {
            this.value = this.value.slice(0, 10);
        }
    });

    // Form submission - handle via AJAX for better UX
    jambForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Validate inputs
        if (!selectedExamType) {
            alert('Please select an exam type');
            return;
        }

        const profileId = profileIdInput.value.trim();
        if (!profileId || !/^[A-Z0-9]{10}$/.test(profileId)) {
            alert('Please enter a valid 10-character JAMB Profile ID');
            profileIdInput.focus();
            return;
        }

        const phone = phoneInput.value.trim();
        if (!phone || !/^[0-9]{11}$/.test(phone)) {
            alert('Please enter a valid 11-digit phone number');
            phoneInput.focus();
            return;
        }

        // Get wallet balance from server (in real app)
        const walletBalance = {{ auth()->user()->wallet_balance ?? 0 }};
        const totalAmount = selectedExamType.discount_price + (selectedExamType.discount_price * 0.02);
        
        if (walletBalance < totalAmount) {
            alert('Insufficient wallet balance. Please fund your wallet.');
            return;
        }

        // Confirmation dialog
        const confirmMessage = `You are about to purchase:\n\n` +
                             `Exam Type: ${selectedExamType.name}\n` +
                             `Profile ID: ${profileId}\n` +
                             `Phone: ${phone}\n` +
                             `Amount: ₦${totalAmount.toLocaleString('en-NG', {minimumFractionDigits: 2})}\n\n` +
                             `Proceed with payment?`;

        if (!confirm(confirmMessage)) {
            return;
        }

        // Show loading state on submit button
        const submitBtn = jambForm.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span>🔄 Processing...</span>';
        submitBtn.disabled = true;

        try {
            // Call JAMB purchase API
            const purchaseUrl = `${API_CONFIG.BASE_URL}/APIJAMBV1.asp?` +
                               `UserID=${encodeURIComponent(API_CONFIG.USER_ID)}&` +
                               `APIKey=${encodeURIComponent(API_CONFIG.API_KEY)}&` +
                               `ExamType=${encodeURIComponent(selectedExamType.code)}&` +
                               `PhoneNo=${encodeURIComponent(phone)}&` +
                               `RequestID=${encodeURIComponent(requestIdInput.value)}&` +
                               `CallBackURL=${encodeURIComponent(window.location.origin + '/api/jamb/callback')}`;
            
            console.log('Purchase URL:', purchaseUrl);
            
            const response = await fetch(purchaseUrl);
            const data = await response.json();
            
            console.log('Purchase response:', data);
            
            if (data.status === 'ORDER_COMPLETED' || data.statuscode === '200') {
                // Success - show success message
                alert(`✅ e-PIN Purchase Successful!\n\nOrder ID: ${data.orderid}\nPIN: ${data.carddetails || 'Check SMS'}\nAmount: ₦${data.amountcharged}`);
                
                // Reset form
                jambForm.reset();
                profileInfo.classList.add('hidden');
                verifyProfileBtn.innerHTML = '<span>🔍 Verify Profile ID</span>';
                verifyProfileBtn.classList.remove('bg-green-600', 'hover:bg-green-700');
                verifyProfileBtn.classList.add('bg-green-500', 'hover:bg-green-600');
                
                // Reload transactions
                loadRecentTransactions();
                
                // Refresh wallet balance (in real app, you would update from server)
                window.location.reload();
                
            } else {
                // Error
                alert(`❌ Purchase failed: ${data.remark || data.status}`);
            }
            
        } catch (error) {
            console.error('Purchase error:', error);
            alert('Error processing payment. Please try again.');
        } finally {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    });

    // Initialize
    loadExamTypes();
    loadRecentTransactions();
    updateAmounts();
});
</script>
@endsection