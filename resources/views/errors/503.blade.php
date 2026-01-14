@extends('errors.layout')

@section('title', 'Maintenance Mode')

@section('content')
<div class="text-center max-w-2xl mx-auto">
    <!-- Animated Icon -->
    <div class="relative mb-8">
        <div class="w-32 h-32 mx-auto bg-gradient-to-br from-purple-400 to-purple-500 rounded-3xl flex items-center justify-center shadow-2xl animate-pulse">
            <div class="text-white text-5xl">🔧</div>
        </div>
        <div class="absolute -top-2 -right-2 w-10 h-10 bg-purple-600 rounded-full flex items-center justify-center text-white font-bold">
            503
        </div>
        <div class="absolute -bottom-2 -left-2 w-12 h-12 bg-gradient-to-br from-gray-700 to-gray-900 rounded-full flex items-center justify-center text-white text-2xl">
            ⚡
        </div>
    </div>

    <!-- Maintenance Message -->
    <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">
        We'll Be Right Back!
    </h1>
    
    <p class="text-lg text-gray-600 mb-6 max-w-md mx-auto">
        We're performing scheduled maintenance to improve your experience. The site will be back shortly.
    </p>

    <!-- Maintenance Progress -->
    <div class="mb-8 max-w-md mx-auto">
        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-900">Maintenance Progress</h3>
                <span class="px-3 py-1 bg-purple-100 text-purple-800 text-xs font-semibold rounded-full">
                    In Progress
                </span>
            </div>
            
            <!-- Progress Bar -->
            <div class="mb-6">
                <div class="flex justify-between text-sm text-gray-600 mb-2">
                    <span>Estimated completion</span>
                    <span>65%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2.5">
                    <div class="bg-gradient-to-r from-purple-500 to-purple-600 h-2.5 rounded-full" style="width: 65%"></div>
                </div>
            </div>
            
            <!-- Maintenance Tasks -->
            <div class="space-y-3">
                <div class="flex items-center space-x-3">
                    <span class="w-6 h-6 bg-green-100 rounded-full flex items-center justify-center">
                        <span class="text-green-600 text-sm">✓</span>
                    </span>
                    <span class="text-sm text-gray-700">Database optimization</span>
                </div>
                
                <div class="flex items-center space-x-3">
                    <span class="w-6 h-6 bg-green-100 rounded-full flex items-center justify-center">
                        <span class="text-green-600 text-sm">✓</span>
                    </span>
                    <span class="text-sm text-gray-700">Security updates</span>
                </div>
                
                <div class="flex items-center space-x-3">
                    <span class="w-6 h-6 bg-yellow-100 rounded-full flex items-center justify-center">
                        <span class="text-yellow-600 text-sm">⚡</span>
                    </span>
                    <span class="text-sm text-gray-700">Performance enhancements</span>
                </div>
                
                <div class="flex items-center space-x-3">
                    <span class="w-6 h-6 bg-gray-100 rounded-full flex items-center justify-center">
                        <span class="text-gray-400 text-sm">⏳</span>
                    </span>
                    <span class="text-sm text-gray-700">New features deployment</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Countdown Timer -->
    <div class="mb-8 max-w-md mx-auto">
        <div class="bg-gradient-to-r from-purple-50 to-pink-50 border border-purple-200 rounded-2xl p-6">
            <h3 class="font-semibold text-gray-900 mb-4 text-center">Estimated Time Remaining</h3>
            <div class="grid grid-cols-3 gap-4 text-center">
                <div class="bg-white rounded-xl p-4 shadow-sm">
                    <div class="text-2xl font-bold text-purple-600" id="hours">00</div>
                    <div class="text-xs text-gray-600">Hours</div>
                </div>
                <div class="bg-white rounded-xl p-4 shadow-sm">
                    <div class="text-2xl font-bold text-purple-600" id="minutes">30</div>
                    <div class="text-xs text-gray-600">Minutes</div>
                </div>
                <div class="bg-white rounded-xl p-4 shadow-sm">
                    <div class="text-2xl font-bold text-purple-600" id="seconds">00</div>
                    <div class="text-xs text-gray-600">Seconds</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Updates -->
    <div class="mb-8 max-w-md mx-auto">
        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center space-x-3 mb-4">
                <div class="w-12 h-12 bg-gradient-to-br from-green-100 to-green-200 rounded-xl flex items-center justify-center">
                    <span class="text-2xl">📢</span>
                </div>
                <div class="text-left">
                    <h4 class="font-semibold text-gray-900">Latest Update</h4>
                    <p class="text-sm text-gray-600">Posted 15 minutes ago</p>
                </div>
            </div>
            <p class="text-gray-700 text-sm">
                We're currently deploying new security features and performance improvements. 
                All user data is safe and will be available once maintenance is complete.
            </p>
        </div>
    </div>

    <!-- Contact Information -->
    <div class="bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-2xl p-6 max-w-md mx-auto">
        <div class="flex items-start space-x-3">
            <div class="w-12 h-12 bg-gradient-to-br from-green-400 to-green-500 rounded-xl flex items-center justify-center flex-shrink-0">
                <span class="text-2xl">📞</span>
            </div>
            <div class="text-left">
                <h4 class="font-semibold text-gray-900 mb-1">Need Immediate Assistance?</h4>
                <p class="text-sm text-gray-600 mb-3">
                    Our support team is available for urgent inquiries.
                </p>
                <div class="space-y-2">
                    <a href="mailto:reup.bellahoptions@gmail.com" 
                       class="flex items-center space-x-2 text-sm text-gray-700 hover:text-green-700">
                        <span>📧</span>
                        <span>reup.bellahoptions@gmail.com</span>
                    </a>
                    <a href="tel:+2349031412354" 
                       class="flex items-center space-x-2 text-sm text-gray-700 hover:text-green-700">
                        <span>📱</span>
                        <span>+234 903 141 2354</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Social Media -->
    <div class="mt-8">
        <p class="text-sm text-gray-600 mb-4">Follow us for updates</p>
        <div class="flex items-center justify-center space-x-4">
            <a href="#" class="w-10 h-10 bg-gray-100 hover:bg-gray-200 rounded-full flex items-center justify-center transition-colors">
                <span class="text-gray-700">🐦</span>
            </a>
            <a href="#" class="w-10 h-10 bg-gray-100 hover:bg-gray-200 rounded-full flex items-center justify-center transition-colors">
                <span class="text-gray-700">📘</span>
            </a>
            <a href="#" class="w-10 h-10 bg-gray-100 hover:bg-gray-200 rounded-full flex items-center justify-center transition-colors">
                <span class="text-gray-700">💼</span>
            </a>
            <a href="#" class="w-10 h-10 bg-gray-100 hover:bg-gray-200 rounded-full flex items-center justify-center transition-colors">
                <span class="text-gray-700">📸</span>
            </a>
        </div>
    </div>
</div>

<script>
// Countdown Timer
function startCountdown() {
    let totalSeconds = 30 * 60; // 30 minutes in seconds
    
    function updateTimer() {
        if (totalSeconds <= 0) {
            // Check if site is back
            checkSiteStatus();
            return;
        }
        
        totalSeconds--;
        
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        
        document.getElementById('hours').textContent = hours.toString().padStart(2, '0');
        document.getElementById('minutes').textContent = minutes.toString().padStart(2, '0');
        document.getElementById('seconds').textContent = seconds.toString().padStart(2, '0');
    }
    
    // Update every second
    setInterval(updateTimer, 1000);
    updateTimer(); // Initial call
}

// Check if site is back online
function checkSiteStatus() {
    fetch(window.location.href)
        .then(response => {
            if (response.status === 200) {
                window.location.reload();
            }
        })
        .catch(() => {
            // Site still down, check again in 30 seconds
            setTimeout(checkSiteStatus, 30000);
        });
}

// Start countdown when page loads
document.addEventListener('DOMContentLoaded', startCountdown);

// Auto-check every minute
setInterval(checkSiteStatus, 60000);
</script>
@endsection