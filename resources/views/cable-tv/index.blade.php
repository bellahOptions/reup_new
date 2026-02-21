@extends('layouts.app')
@section('title', 'Recharge your DSTV, GOTV, Startimes & Showmax - Fast Cable TV Subscription on ReUp')
@section('content')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8 md:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Page Header -->
            <div class="mb-8 md:mb-12">
                <div class="flex items-center space-x-3 mb-4">
                    <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg">
                        <span class="text-2xl">📺</span>
                    </div>
                    <div>
                        <h1 class="text-2xl md:text-4xl font-bold text-gray-900">Cable TV Subscription</h1>
                        <p class="text-gray-600 text-sm md:text-base mt-1">Pay for DStv, GOtv, StarTimes & Showmax - Instant Activation ⚡</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">
                <!-- Main Form Section -->
                <div class="lg:col-span-2">
                    <!-- Purchase Form Card -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 overflow-hidden">
                        <div class="p-6 md:p-8">
                            <form id="cableTvForm" action="{{ route('cable-tv.purchase') }}" method="POST" class="space-y-6">
                                @csrf
                                
                                <!-- Cable Provider Selection -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">Select Provider</label>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                        <label class="provider-card cursor-pointer">
                                            <input type="radio" name="provider" value="DStv" class="hidden provider-radio" required>
                                            <div class="provider-option p-4 border-2 border-gray-200 rounded-xl hover:border-green-400 transition-all duration-300 text-center">
                                                <div class="w-12 h-12 bg-green-100 rounded-full mx-auto mb-2 flex items-center justify-center">
                                                    <span class="text-2xl"><img src="https://westafricaweekly.com/wp-content/uploads/2025/06/1718354329392.png" class="w-6 h-6"/> </span>
                                                </div>
                                                <span class="font-semibold text-gray-900 text-sm">DStv</span>
                                            </div>
                                        </label>
                                        <label class="provider-card cursor-pointer">
                                            <input type="radio" name="provider" value="GOtv" class="hidden provider-radio" required>
                                            <div class="provider-option p-4 border-2 border-gray-200 rounded-xl hover:border-green-400 transition-all duration-300 text-center">
                                                <div class="w-12 h-12 bg-green-100 rounded-full mx-auto mb-2 flex items-center justify-center">
                                                    <span class="text-2xl"><img src="https://yt3.googleusercontent.com/t5tj2_aVphUlbgLG4VVxmTEmgF4x2wGhL3DuTvTpC4wTJlFu1zW714Z1ZFQ14OUYWAMh5UPd=s900-c-k-c0x00ffffff-no-rj" class="w-6 h-6"/> </span>
                                                </div>
                                                <span class="font-semibold text-gray-900 text-sm">GOtv</span>
                                            </div>
                                        </label>
                                        <label class="provider-card cursor-pointer">
                                            <input type="radio" name="provider" value="StarTimes" class="hidden provider-radio" required>
                                            <div class="provider-option p-4 border-2 border-gray-200 rounded-xl hover:border-green-400 transition-all duration-300 text-center">
                                                <div class="w-12 h-12 bg-green-100 rounded-full mx-auto mb-2 flex items-center justify-center">
                                                    <span class="text-2xl"><img src="https://images.seeklogo.com/logo-png/52/1/startimes-logo-png_seeklogo-527209.png" class="w-6 h-6"/> </span>
                                                </div>
                                                <span class="font-semibold text-gray-900 text-sm">StarTimes</span>
                                            </div>
                                        </label>
                                        <label class="provider-card cursor-pointer">
                                            <input type="radio" name="provider" value="Showmax" class="hidden provider-radio" required>
                                            <div class="provider-option p-4 border-2 border-gray-200 rounded-xl hover:border-green-400 transition-all duration-300 text-center">
                                                <div class="w-12 h-12 bg-green-100 rounded-full mx-auto mb-2 flex items-center justify-center">
                                                    <span class="text-2xl"><img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQeQuTMeyqCz_BKYYCuXi8WUepbhXAcL5qLFg&s" class="w-6 h-6"/> </span>
                                                </div>
                                                <span class="font-semibold text-gray-900 text-sm">Showmax</span>
                                            </div>
                                        </label>
                                    </div>
                                </div>

                                <!-- SmartCard/IUC Number -->
                                <div>
                                    <label for="smartcard_number" class="block text-sm font-semibold text-gray-700 mb-2">
                                        SmartCard/IUC Number
                                        <span class="text-xs font-normal text-gray-500">(10-digit number on your decoder)</span>
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-400">🔢</span>
                                        </div>
                                        <input type="text" id="smartcard_number" name="smartcard_number" placeholder="1234567890" 
                                               class="block w-full pl-12 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                                               required maxlength="10" pattern="[0-9]{10}">
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">💡 You can find this on your decoder or smart card</p>
                                </div>

                                <!-- Verify Button -->
                                <div>
                                    <button type="button" id="verifyBtn" class="w-full bg-green-500 hover:bg-green-600 text-white font-semibold py-3 px-6 rounded-xl shadow-md hover:shadow-lg transform hover:scale-[1.02] transition-all duration-200 flex items-center justify-center space-x-2">
                                        <span>🔍 Verify SmartCard Number</span>
                                    </button>
                                </div>

                                <!-- Customer Info Display (Hidden until verified) -->
                                <div id="customerInfo" class="hidden bg-green-50 border border-green-200 rounded-xl p-4">
                                    <div class="flex items-start space-x-3">
                                        <span class="text-2xl">✓</span>
                                        <div class="flex-1">
                                            <p class="font-semibold text-green-900 mb-2">Account Verified</p>
                                            <div class="space-y-1 text-sm text-gray-700">
                                                <p><span class="font-medium">Customer Name:</span> <span id="customerName">Loading...</span></p>
                                                <p><span class="font-medium">Current Package:</span> <span id="currentPackage">Loading...</span></p>
                                                <p><span class="font-medium">Status:</span> <span id="accountStatus" class="text-green-600 font-medium">Loading...</span></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Bouquet/Package Selection -->
                                <div>
                                    <label for="package" class="block text-sm font-semibold text-gray-700 mb-2">Select Package/Bouquet</label>
                                    <div id="packageLoader" class="text-center py-4 hidden">
                                        <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-green-600"></div>
                                        <p class="text-gray-600 mt-2 text-sm">Loading packages...</p>
                                    </div>
                                    <select name="package" id="package" class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" required disabled>
                                        <option value="" disabled selected>Select a provider first</option>
                                    </select>
                                </div>

                                <!-- Subscription Type -->
                                <div>
                                    <label for="subscription_type" class="block text-sm font-semibold text-gray-700 mb-2">Subscription Type</label>
                                    <select name="subscription_type" id="subscription_type" class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" required>
                                        <option value="renew">Renew Package</option>
                                        <option value="change">Change Package</option>
                                        <option value="new">New Subscription</option>
                                    </select>
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
                                    <p class="text-xs text-gray-500 mt-1">For confirmation SMS</p>
                                </div>

                                <!-- Auto Renew -->
                                <div>
                                    <label for="auto_renew" class="block text-sm font-semibold text-gray-700 mb-2">Auto Renewal</label>
                                    <select name="auto_renew" id="auto_renew" class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200">
                                        <option value="no">No (One-time payment)</option>
                                        <option value="monthly">Yes (Auto-renew monthly)</option>
                                    </select>
                                    <p class="text-xs text-gray-500 mt-1">💡 Auto-renewal ensures uninterrupted service</p>
                                </div>

                                <!-- Amount Display -->
                                <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-sm font-semibold text-gray-700">Package Amount:</span>
                                        <span class="text-2xl font-bold text-green-600" id="packageAmount">₦0.00</span>
                                    </div>
                                    <div class="flex items-center justify-between text-sm text-gray-600">
                                        <span>Service Fee (2%):</span>
                                        <span id="serviceFee">₦0.00</span>
                                    </div>
                                    <div class="border-t border-green-200 mt-2 pt-2 flex items-center justify-between">
                                        <span class="font-bold text-gray-900">Total Amount:</span>
                                        <span class="text-xl font-bold text-green-600" id="totalAmount">₦0.00</span>
                                    </div>
                                </div>

                                <!-- Submit Button -->
                                <button type="submit" class="w-full bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-bold py-4 px-6 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-200 flex items-center justify-center space-x-2">
                                    <span>Pay Now</span>
                                    <span>🚀</span>
                                </button>

                                <!-- Terms Notice -->
                                <p class="text-xs text-gray-500 text-center">
                                    By proceeding, you agree to our 
                                    <a href="#" class="text-green-600 hover:text-green-700 font-medium underline">Terms of Service</a>
                                </p>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Sidebar - Info & Recent -->
                <div class="space-y-6">
                    <!-- Wallet Balance Card -->
                    <div class="bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-lg p-6 text-white">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-sm opacity-90">Wallet Balance</span>
                            <span class="text-2xl">💰</span>
                        </div>
                        <div class="text-3xl font-bold mb-4">₦{{ number_format(auth()->user()->wallet_balance ?? 0, 2) }}</div>
                        <a href="{{ route('wallet.fund') }}" class="block w-full bg-white/20 hover:bg-white/30 text-center py-2 rounded-lg font-semibold text-sm transition-all duration-200">
                            + Fund Wallet
                        </a>
                    </div>

                    <!-- Supported Providers -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">📺</span>
                            Supported Providers
                        </h3>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="text-center p-3 bg-green-50 rounded-lg">
                                <span class="text-2xl text-center mb-1 block"><img src="https://westafricaweekly.com/wp-content/uploads/2025/06/1718354329392.png" class="w-full"/> </span>
                            </div>
                            <div class="text-center p-3 bg-green-50 rounded-lg">
                                <span class="text-2xl text-center mb-1 block"><img src="https://yt3.googleusercontent.com/t5tj2_aVphUlbgLG4VVxmTEmgF4x2wGhL3DuTvTpC4wTJlFu1zW714Z1ZFQ14OUYWAMh5UPd=s900-c-k-c0x00ffffff-no-rj" class="w-full"/> </span>
                            </div>
                            <div class="text-center p-3 bg-green-50 rounded-lg">
                                <span class="text-2xl text-center mb-1 block"><img src="https://images.seeklogo.com/logo-png/52/1/startimes-logo-png_seeklogo-527209.png" class="w-full"/> </span>
                            </div>
                            <div class="text-center p-3 bg-green-50 rounded-lg">
                                <span class="text-2xl text-center mb-1 block"><img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQeQuTMeyqCz_BKYYCuXi8WUepbhXAcL5qLFg&s" class="w-full"/> </span>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Info -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">💡</span>
                            Important Info
                        </h3>
                        <ul class="space-y-3 text-sm text-gray-600">
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">✓</span>
                                <span>Instant activation within 5 minutes</span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">✓</span>
                                <span>Verify your smartcard before payment</span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">✓</span>
                                <span>2% service fee applies</span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">✓</span>
                                <span>SMS confirmation sent immediately</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
/* Provider Selection */
.provider-radio:checked + .provider-option {
    @apply border-green-500 bg-green-50 shadow-md;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const packageSelect = document.getElementById('package');
    const packageAmountEl = document.getElementById('packageAmount');
    const serviceFeeEl = document.getElementById('serviceFee');
    const totalAmountEl = document.getElementById('totalAmount');
    const verifyBtn = document.getElementById('verifyBtn');
    const customerInfo = document.getElementById('customerInfo');
    const smartcardInput = document.getElementById('smartcard_number');
    const customerNameEl = document.getElementById('customerName');
    const currentPackageEl = document.getElementById('currentPackage');
    const accountStatusEl = document.getElementById('accountStatus');
    const packageLoader = document.getElementById('packageLoader');
    const providerRadios = document.querySelectorAll('.provider-radio');
    
    // API Configuration - Using your existing .env variables
    const API_CONFIG = {
        USER_ID: '{{ env("CLUBKONNECT_CLIENT_ID") }}', // Your CLUBKONNECT_CLIENT_ID
        API_KEY: '{{ env("CLUBKONNECT_API_KEY") }}',    // Your CLUBKONNECT_API_KEY
        BASE_URL: 'https://www.nellobytesystems.com'
    };

    // Check if API credentials are available
    if (!API_CONFIG.USER_ID || !API_CONFIG.API_KEY) {
        console.error('API credentials not configured. Please check your .env file.');
        alert('Service configuration error. Please contact administrator.');
        document.querySelectorAll('input, select, button').forEach(el => {
            el.disabled = true;
        });
        return;
    }

    // Calculate and display amounts when package is selected
    packageSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const price = parseFloat(selectedOption.getAttribute('data-price')) || 0;
        updateAmounts(price);
    });

    // Function to update amount display
    function updateAmounts(price) {
        const serviceFee = price * 0.02; // 2% service fee
        const total = price + serviceFee;

        packageAmountEl.textContent = `₦${price.toLocaleString('en-NG', {minimumFractionDigits: 2})}`;
        serviceFeeEl.textContent = `₦${serviceFee.toLocaleString('en-NG', {minimumFractionDigits: 2})}`;
        totalAmountEl.textContent = `₦${total.toLocaleString('en-NG', {minimumFractionDigits: 2})}`;
    }

    // Function to categorize packages by type
    function getPackageType(packageName) {
        const name = packageName.toLowerCase();
        
        if (name.includes('weekly') || name.includes('1 week')) return 'Weekly';
        if (name.includes('monthly') || name.includes('1 month')) return 'Monthly';
        if (name.includes('quarterly') || name.includes('3 months')) return 'Quarterly';
        if (name.includes('yearly') || name.includes('1 year')) return 'Yearly';
        if (name.includes('6 months')) return '6 Months';
        if (name.includes('add-on') || name.includes('addon')) return 'Add-ons';
        if (name.includes('standalone')) return 'Standalone';
        if (name.includes('dish') || name.includes('antenna')) return 'Device Specific';
        
        return 'Regular';
    }

    // Function to display packages with grouping
    function displayPackages(packages, providerName) {
        packageSelect.innerHTML = '<option value="" disabled selected>Select a package</option>';
        
        // Group packages by type
        const groupedPackages = {};
        packages.forEach(pkg => {
            const type = getPackageType(pkg.name);
            if (!groupedPackages[type]) {
                groupedPackages[type] = [];
            }
            groupedPackages[type].push(pkg);
        });
        
        // Sort types for consistent display
        const typeOrder = ['Monthly', 'Weekly', 'Quarterly', 'Yearly', '6 Months', 'Regular', 'Device Specific', 'Add-ons', 'Standalone'];
        
        typeOrder.forEach(type => {
            if (groupedPackages[type]) {
                // Sort packages within each type by price
                groupedPackages[type].sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
                
                // Create optgroup for this type
                const optgroup = document.createElement('optgroup');
                optgroup.label = `${type} Packages`;
                
                groupedPackages[type].forEach(pkg => {
                    const option = document.createElement('option');
                    option.value = pkg.code;
                    
                    // Format display name
                    let displayName = pkg.name.replace(providerName + ' ', '').replace('Dstv', 'DStv');
                    
                    option.textContent = `${displayName} - ₦${parseFloat(pkg.price).toFixed(2)}`;
                    option.setAttribute('data-price', pkg.price);
                    option.setAttribute('data-name', pkg.name);
                    option.setAttribute('data-discount-price', pkg.discount_price);
                    
                    optgroup.appendChild(option);
                });
                
                packageSelect.appendChild(optgroup);
            }
        });
        
        packageSelect.disabled = false;
    }

// Verify SmartCard Number with API
verifyBtn.addEventListener('click', async function() {
    const smartcardNumber = smartcardInput.value.trim();
    
    if (!smartcardNumber || smartcardNumber.length !== 10) {
        alert('Please enter a valid 10-digit smartcard number');
        return;
    }

    // Get selected provider
    const selectedProvider = document.querySelector('input[name="provider"]:checked');
    if (!selectedProvider) {
        alert('Please select a TV provider first');
        return;
    }

    const providerName = selectedProvider.value;
    const cableTVCode = providerName.toLowerCase(); // 'dstv', 'gotv', etc.

    // Show loading state
    verifyBtn.innerHTML = '<span>🔄 Verifying...</span>';
    verifyBtn.disabled = true;
    customerInfo.classList.add('hidden');

    try {
        // Call verification API with proper URL encoding
        const verifyUrl = `${API_CONFIG.BASE_URL}/APIVerifyCableTVV1.0.asp?` +
                         `UserID=${encodeURIComponent(API_CONFIG.USER_ID)}&` +
                         `APIKey=${encodeURIComponent(API_CONFIG.API_KEY)}&` +
                         `CableTV=${encodeURIComponent(cableTVCode)}&` +
                         `SmartCardNo=${encodeURIComponent(smartcardNumber)}`;
        
        console.log('Verification URL:', verifyUrl);
        
        const response = await fetch(verifyUrl);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const responseText = await response.text();
        console.log('Raw API Response:', responseText);
        
        // Try to parse as JSON first
        let data;
        try {
            data = JSON.parse(responseText);
            console.log('Parsed JSON:', data);
        } catch (jsonError) {
            // If not JSON, try to parse as XML
            console.log('Response is not JSON, trying XML...');
            const parser = new DOMParser();
            const xmlDoc = parser.parseFromString(responseText, "text/xml");
            
            const status = xmlDoc.querySelector('status')?.textContent;
            const name = xmlDoc.querySelector('name')?.textContent || 'N/A';
            const packageName = xmlDoc.querySelector('package')?.textContent || 'N/A';
            const message = xmlDoc.querySelector('message')?.textContent || '';
            
            data = {
                status: status,
                customer_name: name,
                package: packageName,
                message: message
            };
        }
        
        // Handle both JSON and XML responses
        console.log('Processed data:', data);
        
        // Check for successful verification
        // For JSON response: status "00" means success
        // For XML response: status "ORDER_RECEIVED", "SUCCESS", or "ACTIVE" means success
        const isSuccess = 
            (data.status === '00') || 
            (data.status === 'ORDER_RECEIVED') || 
            (data.status === 'SUCCESS') || 
            (data.status === 'ACTIVE');
        
        if (isSuccess) {
            // Show customer info
            const customerName = data.customer_name || data.name || 'N/A';
            const currentPackage = data.package || 'N/A';
            
            customerNameEl.textContent = customerName;
            currentPackageEl.textContent = currentPackage;
            accountStatusEl.textContent = 'Active';
            customerInfo.classList.remove('hidden');
            
            // Update button to show verified
            verifyBtn.innerHTML = '<span>✓ Verified</span>';
            verifyBtn.classList.remove('bg-green-500', 'hover:bg-green-600');
            verifyBtn.classList.add('bg-green-600', 'hover:bg-green-700');
            
            console.log('Verification successful:', customerName);
            
        } else {
            // Show error message
            const errorMessage = data.message || data.error || 'Please check your SmartCard number';
            console.log('Verification failed:', errorMessage);
            alert(`Verification failed: ${errorMessage}`);
            verifyBtn.innerHTML = '<span>🔍 Verify SmartCard Number</span>';
        }
        
    } catch (error) {
        console.error('Verification error:', error);
        alert('Error connecting to verification service. Please try again.');
        verifyBtn.innerHTML = '<span>🔍 Verify SmartCard Number</span>';
    } finally {
        verifyBtn.disabled = false;
    }
});

    // Load packages when provider is selected
    providerRadios.forEach(radio => {
        radio.addEventListener('change', async function() {
            const providerName = this.value;
            
            // Reset package select
            packageSelect.disabled = true;
            packageSelect.innerHTML = '<option value="" disabled selected>Loading packages...</option>';
            packageLoader.classList.remove('hidden');
            
            // Reset amounts
            updateAmounts(0);
            
            // Reset verification
            verifyBtn.innerHTML = '<span>🔍 Verify SmartCard Number</span>';
            verifyBtn.classList.remove('bg-green-600', 'hover:bg-green-700');
            verifyBtn.classList.add('bg-green-500', 'hover:bg-green-600');
            customerInfo.classList.add('hidden');
            
            try {
                // Fetch packages from API with proper URL encoding
                const packagesUrl = `${API_CONFIG.BASE_URL}/APICableTVPackagesV2.asp?UserID=${encodeURIComponent(API_CONFIG.USER_ID)}`;
                const response = await fetch(packagesUrl);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                // Access the TV_ID object
                const tvData = data.TV_ID;
                
                if (!tvData || !tvData[providerName]) {
                    packageSelect.innerHTML = '<option value="" disabled selected>No packages available for this provider</option>';
                    return;
                }
                
                // Get products for the selected provider
                const providerData = tvData[providerName];
                fetchedPackages = [];
                
                if (providerData && providerData[0] && providerData[0].PRODUCT) {
                    const products = providerData[0].PRODUCT;
                    
                    products.forEach(product => {
                        fetchedPackages.push({
                            code: product.PACKAGE_ID,
                            name: product.PACKAGE_NAME,
                            price: parseFloat(product.PACKAGE_AMOUNT),
                            discount_price: parseFloat(product.PRODUCT_DISCOUNT_AMOUNT),
                            discount: parseFloat(product.PRODUCT_DISCOUNT),
                            min_amount: parseFloat(product.MINAMOUNT),
                            max_amount: parseFloat(product.MAXAMOUNT),
                            provider: providerName
                        });
                    });
                    
                    // Display packages with grouping
                    displayPackages(fetchedPackages, providerName);
                    
                    console.log(`Loaded ${fetchedPackages.length} packages for ${providerName}`);
                } else {
                    packageSelect.innerHTML = '<option value="" disabled selected>No packages found</option>';
                }
                
            } catch (error) {
                console.error('Error loading packages:', error);
                packageSelect.innerHTML = '<option value="" disabled selected>Error loading packages. Please try again.</option>';
            } finally {
                packageLoader.classList.add('hidden');
            }
        });
    });

    // Phone number validation
    const phoneInput = document.getElementById('phone');
    phoneInput.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
        if (this.value.length > 11) {
            this.value = this.value.slice(0, 11);
        }
    });

    // Smartcard number validation
    smartcardInput.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
        if (this.value.length > 10) {
            this.value = this.value.slice(0, 10);
        }
    });

    // Form submission validation
    document.getElementById('cableTvForm').addEventListener('submit', function(e) {
        if (!packageSelect.value || packageSelect.disabled) {
            e.preventDefault();
            alert('Please select a package');
            return;
        }
        
        if (!smartcardInput.value || smartcardInput.value.length !== 10) {
            e.preventDefault();
            alert('Please enter a valid 10-digit SmartCard number');
            return;
        }
        
        // Check if provider is selected
        const selectedProvider = document.querySelector('input[name="provider"]:checked');
        if (!selectedProvider) {
            e.preventDefault();
            alert('Please select a TV provider');
            return;
        }
        
        const selectedOption = packageSelect.options[packageSelect.selectedIndex];
        const packageName = selectedOption.dataset.name;
        const packagePrice = selectedOption.dataset.price;
        const discountPrice = selectedOption.dataset.discountPrice;
        
        if (packageName && packagePrice) {
            const confirmMessage = `You are about to purchase:\n\n` +
                                 `Provider: ${selectedProvider.value}\n` +
                                 `Package: ${packageName}\n` +
                                 `Amount: ₦${parseFloat(packagePrice).toFixed(2)}\n` +
                                 `Discounted Price: ₦${parseFloat(discountPrice).toFixed(2)}\n` +
                                 `SmartCard: ${smartcardInput.value}\n\n` +
                                 `Proceed with payment?`;
            
            if (!confirm(confirmMessage)) {
                e.preventDefault();
            }
        }
    });

    // Update form to use discounted price instead of regular price
    packageSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const regularPrice = parseFloat(selectedOption.getAttribute('data-price')) || 0;
        const discountPrice = parseFloat(selectedOption.getAttribute('data-discount-price')) || regularPrice;
        
        // Use the discounted price for calculations
        updateAmounts(discountPrice);
    });

    // Initialize with first provider selected if any
    const firstProvider = document.querySelector('input[name="provider"]:checked');
    if (firstProvider) {
        firstProvider.dispatchEvent(new Event('change'));
    }
});
</script>
@endsection