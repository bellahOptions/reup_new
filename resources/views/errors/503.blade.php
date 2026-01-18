@extends('errors.layout')

@section('title', 'Maintenance Mode')

@section('content')
<div class="text-center max-w-2xl mx-auto">
    <!-- Animated Icon -->
    <div class="relative mb-8">
        <div class="w-32 h-32 mx-auto bg-gradient-to-br from-green-400 to-green-500 rounded-3xl flex items-center justify-center shadow-2xl animate-pulse">
            <div class="text-white text-5xl">🔧</div>
        </div>
        <div class="absolute -top-2 -right-2 w-10 h-10 bg-green-600 rounded-full flex items-center justify-center text-white font-bold">
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
    
    <!-- Custom Maintenance Message from Settings -->
    @if(session('maintenance_message'))
        <div class="bg-blue-50 border border-blue-200 rounded-2xl p-6 mb-8 max-w-md mx-auto">
            <div class="flex items-start space-x-3">
                <span class="text-2xl flex-shrink-0">ℹ️</span>
                <p class="text-left text-gray-700">
                    {{ session('maintenance_message') }}
                </p>
            </div>
        </div>
    @else
        <p class="text-lg text-gray-600 mb-6 max-w-md mx-auto">
            We're performing scheduled maintenance to improve your experience. The site will be back shortly.
        </p>
    @endif

    <!-- Maintenance Progress -->
    <div class="mb-8 max-w-md mx-auto">
        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-900">Maintenance Progress</h3>
                <span class="px-3 py-1 bg-green-100 text-green-800 text-xs font-semibold rounded-full">
                    In Progress
                </span>
            </div>
            
            <!-- Progress Bar -->
            <div class="mb-6">
                <div class="flex justify-between text-sm text-gray-600 mb-2">
                    <span>Estimated completion</span>
                    <span id="progress-percent">0%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2.5">
                    <div id="progress-bar" class="bg-gradient-to-r from-green-500 to-green-600 h-2.5 rounded-full transition-all duration-500" style="width: 0%"></div>
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
                
                <div class="flex items-center space-x-3" id="task-3">
                    <span class="w-6 h-6 bg-yellow-100 rounded-full flex items-center justify-center">
                        <span class="text-yellow-600 text-sm">⚡</span>
                    </span>
                    <span class="text-sm text-gray-700">Performance enhancements</span>
                </div>
                
                <div class="flex items-center space-x-3" id="task-4">
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
        <div class="bg-gradient-to-r from-green-50 to-pink-50 border border-green-200 rounded-2xl p-6">
            <h3 class="font-semibold text-gray-900 mb-4 text-center">Estimated Time Remaining</h3>
            <div class="grid grid-cols-3 gap-4 text-center">
                <div class="bg-white rounded-xl p-4 shadow-sm">
                    <div class="text-2xl font-bold text-green-600" id="hours">00</div>
                    <div class="text-xs text-gray-600">Hours</div>
                </div>
                <div class="bg-white rounded-xl p-4 shadow-sm">
                    <div class="text-2xl font-bold text-green-600" id="minutes">30</div>
                    <div class="text-xs text-gray-600">Minutes</div>
                </div>
                <div class="bg-white rounded-xl p-4 shadow-sm">
                    <div class="text-2xl font-bold text-green-600" id="seconds">00</div>
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
                    <p class="text-sm text-gray-600" id="update-time">Posted just now</p>
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
                    <a href="mailto:{{ session('contact_email', 'reup.bellahoptions@gmail.com') }}" 
                       class="flex items-center space-x-2 text-sm text-gray-700 hover:text-green-700 transition-colors">
                        <span>📧</span>
                        <span>{{ session('contact_email', 'reup.bellahoptions@gmail.com') }}</span>
                    </a>
                    <a href="tel:{{ str_replace(' ', '', session('contact_phone', '+2349031412354')) }}" 
                       class="flex items-center space-x-2 text-sm text-gray-700 hover:text-green-700 transition-colors">
                        <span>📱</span>
                        <span>{{ session('contact_phone', '+234 903 141 2354') }}</span>
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

    <!-- Refresh Button -->
    <div class="mt-8">
        <button onclick="checkSiteStatus()" 
                class="px-6 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-xl font-semibold hover:from-green-600 hover:to-green-700 transition-all transform hover:scale-105 shadow-lg">
            🔄 Check if Site is Back
        </button>
    </div>

    <!-- Site Name -->
    <div class="mt-8 text-sm text-gray-500">
        &copy; {{ date('Y') }} {{ session('site_name', 'ReUp Digital Services') }}. All rights reserved.
    </div>
</div>

<script>
// Configuration
let totalSeconds = 30 * 60; // 30 minutes in seconds
let progress = 0;
let startTime = Date.now();

// Countdown Timer
function updateCountdown() {
    if (totalSeconds <= 0) {
        totalSeconds = 0;
        checkSiteStatus();
    } else {
        totalSeconds--;
    }
    
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    
    document.getElementById('hours').textContent = hours.toString().padStart(2, '0');
    document.getElementById('minutes').textContent = minutes.toString().padStart(2, '0');
    document.getElementById('seconds').textContent = seconds.toString().padStart(2, '0');
}

// Update Progress Bar
function updateProgress() {
    // Simulate progress
    const elapsed = (Date.now() - startTime) / 1000; // seconds elapsed
    progress = Math.min(85, (elapsed / (30 * 60)) * 85); // Cap at 85%
    
    document.getElementById('progress-bar').style.width = progress + '%';
    document.getElementById('progress-percent').textContent = Math.round(progress) + '%';
    
    // Update task status based on progress
    if (progress > 50) {
        const task3 = document.getElementById('task-3');
        task3.querySelector('.w-6').innerHTML = '<span class="text-green-600 text-sm">✓</span>';
        task3.querySelector('.w-6').classList.remove('bg-yellow-100');
        task3.querySelector('.w-6').classList.add('bg-green-100');
    }
    
    if (progress > 70) {
        const task4 = document.getElementById('task-4');
        task4.querySelector('.w-6').innerHTML = '<span class="text-yellow-600 text-sm">⚡</span>';
        task4.querySelector('.w-6').classList.remove('bg-gray-100');
        task4.querySelector('.w-6').classList.add('bg-yellow-100');
    }
}

// Update time posted
function updateTimePosted() {
    const elapsed = Math.floor((Date.now() - startTime) / 1000 / 60); // minutes
    
    let timeText;
    if (elapsed < 1) {
        timeText = 'Posted just now';
    } else if (elapsed < 60) {
        timeText = `Posted ${elapsed} minute${elapsed > 1 ? 's' : ''} ago`;
    } else {
        const hours = Math.floor(elapsed / 60);
        timeText = `Posted ${hours} hour${hours > 1 ? 's' : ''} ago`;
    }
    
    document.getElementById('update-time').textContent = timeText;
}

// Check if site is back online
function checkSiteStatus() {
    console.log('Checking site status...');
    
    // Show loading state
    const btn = event?.target;
    if (btn) {
        btn.textContent = '⏳ Checking...';
        btn.disabled = true;
    }
    
    fetch(window.location.href, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (response.status === 200) {
            // Site is back!
            if (btn) {
                btn.textContent = '✅ Site is Back!';
                btn.classList.remove('from-green-500', 'to-green-600');
                btn.classList.add('from-green-500', 'to-green-600');
            }
            
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else if (response.status === 503) {
            // Still in maintenance
            if (btn) {
                btn.textContent = '⏳ Still Maintaining...';
                btn.disabled = false;
                setTimeout(() => {
                    btn.textContent = '🔄 Check if Site is Back';
                }, 2000);
            }
        }
    })
    .catch(error => {
        console.error('Status check error:', error);
        if (btn) {
            btn.textContent = '❌ Check Failed';
            btn.disabled = false;
            setTimeout(() => {
                btn.textContent = '🔄 Check if Site is Back';
            }, 2000);
        }
    });
}

// Initialize
function init() {
    // Start timers
    setInterval(updateCountdown, 1000);
    setInterval(updateProgress, 2000);
    setInterval(updateTimePosted, 60000);
    
    // Initial calls
    updateCountdown();
    updateProgress();
    updateTimePosted();
    
    // Auto-check every 2 minutes
    setInterval(checkSiteStatus, 120000);
}

// Start when page loads
document.addEventListener('DOMContentLoaded', init);

// Add keyboard shortcut for refresh (Ctrl/Cmd + R is default, add F5)
document.addEventListener('keydown', (e) => {
    if (e.key === 'F5') {
        e.preventDefault();
        checkSiteStatus();
    }
});
</script>
@endsection