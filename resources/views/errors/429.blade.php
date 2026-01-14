@extends('errors.layout')

@section('title', 'Too Many Requests')

@section('content')
<div class="text-center max-w-2xl mx-auto">
    <!-- Animated Icon -->
    <div class="relative mb-8">
        <div class="w-32 h-32 mx-auto bg-gradient-to-br from-red-400 to-red-500 rounded-3xl flex items-center justify-center shadow-2xl">
            <div class="text-white text-5xl">🚦</div>
        </div>
        <div class="absolute -top-2 -right-2 w-10 h-10 bg-red-600 rounded-full flex items-center justify-center text-white font-bold">
            429
        </div>
        <div class="absolute -bottom-2 -left-2 w-12 h-12 bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-full flex items-center justify-center text-white text-2xl">
            ⏱️
        </div>
    </div>

    <!-- Error Message -->
    <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">
        Too Many Requests
    </h1>
    
    <p class="text-lg text-gray-600 mb-6 max-w-md mx-auto">
        You've made too many requests to our server. Please wait a moment before trying again.
    </p>

    <!-- Rate Limit Info -->
    <div class="mb-8 max-w-md mx-auto">
        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center space-x-3 mb-4">
                <div class="w-12 h-12 bg-gradient-to-br from-green-100 to-green-200 rounded-xl flex items-center justify-center">
                    <span class="text-2xl">⚙️</span>
                </div>
                <div class="text-left">
                    <h4 class="font-semibold text-gray-900">Rate Limits</h4>
                    <p class="text-sm text-gray-600">For security and fair usage</p>
                </div>
            </div>
            
            <div class="space-y-4">
                <div>
                    <div class="flex justify-between text-sm text-gray-700 mb-1">
                        <span>API Requests</span>
                        <span class="font-semibold">60/minute</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-gradient-to-r from-red-500 to-red-600 h-2 rounded-full" style="width: 100%"></div>
                    </div>
                </div>
                
                <div>
                    <div class="flex justify-between text-sm text-gray-700 mb-1">
                        <span>Login Attempts</span>
                        <span class="font-semibold">5/minute</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-gradient-to-r from-red-500 to-red-600 h-2 rounded-full" style="width: 100%"></div>
                    </div>
                </div>
                
                <div>
                    <div class="flex justify-between text-sm text-gray-700 mb-1">
                        <span>Form Submissions</span>
                        <span class="font-semibold">10/minute</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-gradient-to-r from-red-500 to-red-600 h-2 rounded-full" style="width: 100%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Wait Timer -->
    <div class="mb-8 max-w-md mx-auto">
        <div class="bg-gradient-to-r from-yellow-50 to-amber-50 border border-yellow-200 rounded-2xl p-6">
            <h3 class="font-semibold text-gray-900 mb-4 text-center">Please Wait</h3>
            <div class="text-center">
                <div class="text-5xl font-bold text-yellow-600 mb-2" id="countdown">60</div>
                <div class="text-sm text-gray-600">seconds before you can retry</div>
            </div>
            <div class="mt-4 text-xs text-gray-500 text-center">
                This limit resets automatically
            </div>
        </div>
    </div>

    <!-- Troubleshooting Tips -->
    <div class="mb-8 max-w-md mx-auto">
        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
            <h4 class="font-semibold text-gray-900 mb-4">Troubleshooting Tips</h4>
            
            <div class="space-y-4">
                <div class="flex items-start space-x-3">
                    <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <span class="text-green-600">1</span>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900">Wait a Moment</p>
                        <p class="text-xs text-gray-600">Rate limits reset automatically after a short period</p>
                    </div>
                </div>
                
                <div class="flex items-start space-x-3">
                    <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <span class="text-green-600">2</span>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900">Clear Browser Cache</p>
                        <p class="text-xs text-gray-600">Sometimes cached requests can cause issues</p>
                    </div>
                </div>
                
                <div class="flex items-start space-x-3">
                    <div class="w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <span class="text-purple-600">3</span>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900">Check for Scripts/Extensions</p>
                        <p class="text-xs text-gray-600">Browser extensions may be making automatic requests</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="flex flex-col sm:flex-row items-center justify-center space-y-4 sm:space-y-0 sm:space-x-4 mb-10">
        <button onclick="window.location.reload()" 
                class="w-full sm:w-auto bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-semibold px-8 py-3 rounded-xl shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center space-x-2">
            <span>🔄</span>
            <span>Try Again Now</span>
        </button>
        
        <a href="{{ url('/') }}" 
           class="w-full sm:w-auto bg-white border border-gray-300 hover:border-green-500 text-gray-800 hover:text-green-700 font-semibold px-8 py-3 rounded-xl shadow-sm hover:shadow transition-all duration-200 flex items-center justify-center space-x-2">
            <span>🏠</span>
            <span>Go to Homepage</span>
        </a>
    </div>

    <!-- Contact Support -->
    <div class="bg-gradient-to-r from-green-50 to-cyan-50 border border-green-200 rounded-2xl p-6 max-w-md mx-auto">
        <div class="flex items-start space-x-3">
            <div class="w-12 h-12 bg-gradient-to-br from-green-400 to-green-500 rounded-xl flex items-center justify-center flex-shrink-0">
                <span class="text-2xl">👨‍💼</span>
            </div>
            <div class="text-left">
                <h4 class="font-semibold text-gray-900 mb-1">Need Higher Limits?</h4>
                <p class="text-sm text-gray-600 mb-3">
                    Business users can request higher rate limits for their accounts.
                </p>
                <a href="mailto:reup.bellahoptions@gmail.com?subject=Rate%20Limit%20Increase%20Request" 
                   class="inline-flex items-center justify-center bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-colors">
                    📧 Request Limit Increase
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Countdown timer
let seconds = 60;
const countdownElement = document.getElementById('countdown');

function updateCountdown() {
    if (seconds > 0) {
        countdownElement.textContent = seconds;
        seconds--;
        setTimeout(updateCountdown, 1000);
    } else {
        countdownElement.textContent = 'Ready!';
        document.querySelector('button').innerHTML = '<span>🔄</span><span>Try Again Now</span>';
    }
}

// Start countdown
updateCountdown();

// Auto-retry after countdown
setTimeout(() => {
    const tryAgainBtn = document.querySelector('button[onclick*="reload"]');
    if (tryAgainBtn) {
        tryAgainBtn.click();
    }
}, 61000);
</script>
@endsection