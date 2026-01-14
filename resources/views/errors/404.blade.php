@extends('errors.layout')

@section('title', 'Page Not Found')

@section('content')
<div class="text-center max-w-2xl mx-auto">
    <!-- Animated Icon -->
    <div class="relative mb-8">
        <div class="w-32 h-32 mx-auto bg-gradient-to-br from-green-400 to-emerald-500 rounded-3xl flex items-center justify-center shadow-2xl animate-pulse-slow">
            <div class="text-white text-5xl">🔍</div>
        </div>
        <div class="absolute -top-2 -right-2 w-10 h-10 bg-red-500 rounded-full flex items-center justify-center text-white font-bold animate-bounce">
            404
        </div>
    </div>

    <!-- Error Message -->
    <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">
        Page Not Found
    </h1>
    
    <p class="text-lg text-gray-600 mb-8 max-w-md mx-auto">
        Oops! The page you're looking for seems to have wandered off. Let's get you back on track.
    </p>

    <!-- Search Bar -->
    <div class="mb-8 max-w-md mx-auto">
        <div class="relative">
            <input type="text" 
                   id="searchInput" 
                   placeholder="Search for pages..." 
                   class="w-full pl-12 pr-4 py-4 border border-gray-300 rounded-2xl focus:ring-2 focus:ring-green-500 focus:border-green-500 shadow-lg"
                   onkeypress="if(event.keyCode==13) searchPage()">
            <div class="absolute left-4 top-4 text-gray-400">
                🔍
            </div>
            <button onclick="searchPage()" 
                    class="absolute right-2 top-2 bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-xl font-semibold transition-colors">
                Search
            </button>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-lg mx-auto mb-10">
        <a href="{{ url('/') }}" 
           class="bg-white hover:bg-green-50 border border-gray-200 hover:border-green-300 p-4 rounded-2xl shadow-sm hover:shadow-md transition-all duration-200 group">
            <div class="flex items-center justify-center space-x-3">
                <div class="w-10 h-10 bg-gradient-to-br from-green-100 to-green-200 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                    <span class="text-xl">🏠</span>
                </div>
                <div class="text-left">
                    <p class="font-semibold text-gray-900">Homepage</p>
                    <p class="text-xs text-gray-500">Back to dashboard</p>
                </div>
            </div>
        </a>
        
        <a href="{{ route('airtime-data.index') }}" 
           class="bg-white hover:bg-green-50 border border-gray-200 hover:border-green-300 p-4 rounded-2xl shadow-sm hover:shadow-md transition-all duration-200 group">
            <div class="flex items-center justify-center space-x-3">
                <div class="w-10 h-10 bg-gradient-to-br from-green-100 to-green-200 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                    <span class="text-xl">📞</span>
                </div>
                <div class="text-left">
                    <p class="font-semibold text-gray-900">Buy Airtime</p>
                    <p class="text-xs text-gray-500">Recharge instantly</p>
                </div>
            </div>
        </a>
        
        <a href="{{ route('wallet.fund') }}" 
           class="bg-white hover:bg-green-50 border border-gray-200 hover:border-green-300 p-4 rounded-2xl shadow-sm hover:shadow-md transition-all duration-200 group">
            <div class="flex items-center justify-center space-x-3">
                <div class="w-10 h-10 bg-gradient-to-br from-purple-100 to-purple-200 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                    <span class="text-xl">💰</span>
                </div>
                <div class="text-left">
                    <p class="font-semibold text-gray-900">Fund Wallet</p>
                    <p class="text-xs text-gray-500">Add money to account</p>
                </div>
            </div>
        </a>
        
        <a href="{{ route('contact') }}" 
           class="bg-white hover:bg-green-50 border border-gray-200 hover:border-green-300 p-4 rounded-2xl shadow-sm hover:shadow-md transition-all duration-200 group">
            <div class="flex items-center justify-center space-x-3">
                <div class="w-10 h-10 bg-gradient-to-br from-yellow-100 to-yellow-200 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                    <span class="text-xl">💬</span>
                </div>
                <div class="text-left">
                    <p class="font-semibold text-gray-900">Contact Support</p>
                    <p class="text-xs text-gray-500">Get help from our team</p>
                </div>
            </div>
        </a>
    </div>

    <!-- Technical Info (Hidden by default) -->
    <div class="mt-8">
        <button onclick="toggleTechInfo()" 
                class="text-sm text-gray-500 hover:text-gray-700 flex items-center space-x-1 mx-auto">
            <span>Technical Details</span>
            <span id="techArrow">▼</span>
        </button>
        <div id="techInfo" class="mt-4 hidden max-w-md mx-auto">
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 text-left">
                <p class="text-sm text-gray-700 mb-2">
                    <strong>URL:</strong> <span class="font-mono">{{ request()->fullUrl() }}</span>
                </p>
                <p class="text-sm text-gray-700">
                    <strong>Time:</strong> {{ now()->format('Y-m-d H:i:s') }}
                </p>
                @if(app()->bound('sentry') && app('sentry')->getLastEventId())
                <p class="text-sm text-gray-700 mt-2">
                    <strong>Error ID:</strong> <span class="font-mono">{{ app('sentry')->getLastEventId() }}</span>
                </p>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
function searchPage() {
    const searchTerm = document.getElementById('searchInput').value;
    if (searchTerm.trim()) {
        // You could implement search functionality here
        window.location.href = `{{ url('/') }}?search=${encodeURIComponent(searchTerm)}`;
    }
}

function toggleTechInfo() {
    const info = document.getElementById('techInfo');
    const arrow = document.getElementById('techArrow');
    if (info.classList.contains('hidden')) {
        info.classList.remove('hidden');
        arrow.textContent = '▲';
    } else {
        info.classList.add('hidden');
        arrow.textContent = '▼';
    }
}

// Focus search input on load
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('searchInput').focus();
});
</script>
@endsection