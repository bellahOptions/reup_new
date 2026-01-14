@extends('errors.layout')

@section('title', 'Server Error')

@section('content')
<div class="text-center max-w-2xl mx-auto">
    <!-- Animated Icon -->
    <div class="relative mb-8">
        <div class="w-32 h-32 mx-auto bg-gradient-to-br from-red-400 to-red-500 rounded-3xl flex items-center justify-center shadow-2xl animate-pulse">
            <div class="text-white text-5xl">⚙️</div>
        </div>
        <div class="absolute -top-2 -right-2 w-10 h-10 bg-red-600 rounded-full flex items-center justify-center text-white font-bold">
            500
        </div>
        <div class="absolute -bottom-2 -left-2 w-8 h-8 bg-yellow-500 rounded-full flex items-center justify-center text-white text-xs">
            ⚠️
        </div>
    </div>

    <!-- Error Message -->
    <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">
        Server Error
    </h1>
    
    <p class="text-lg text-gray-600 mb-6 max-w-md mx-auto">
        Something went wrong on our end. Our team has been notified and is working to fix it.
    </p>

    <!-- Status Updates -->
    <div class="mb-8 max-w-md mx-auto">
        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-900">System Status</h3>
                <span class="px-3 py-1 bg-red-100 text-red-800 text-xs font-semibold rounded-full">
                    Investigating
                </span>
            </div>
            
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-700">API Services</span>
                    <span class="flex items-center space-x-1">
                        <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                        <span class="text-xs text-green-600 font-semibold">Operational</span>
                    </span>
                </div>
                
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-700">Database</span>
                    <span class="flex items-center space-x-1">
                        <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                        <span class="text-xs text-green-600 font-semibold">Operational</span>
                    </span>
                </div>
                
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-700">Payment Gateway</span>
                    <span class="flex items-center space-x-1">
                        <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                        <span class="text-xs text-green-600 font-semibold">Operational</span>
                    </span>
                </div>
                
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-700">Current Issue</span>
                    <span class="flex items-center space-x-1">
                        <span class="w-2 h-2 bg-yellow-500 rounded-full animate-pulse"></span>
                        <span class="text-xs text-yellow-600 font-semibold">Under Investigation</span>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="flex flex-col sm:flex-row items-center justify-center space-y-4 sm:space-y-0 sm:space-x-4 mb-10">
        <button onclick="window.location.reload()" 
                class="w-full sm:w-auto bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-semibold px-8 py-3 rounded-xl shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center space-x-2">
            <span>🔄</span>
            <span>Try Again</span>
        </button>
        
        <a href="{{ url('/') }}" 
           class="w-full sm:w-auto bg-white border border-gray-300 hover:border-green-500 text-gray-800 hover:text-green-700 font-semibold px-8 py-3 rounded-xl shadow-sm hover:shadow transition-all duration-200 flex items-center justify-center space-x-2">
            <span>🏠</span>
            <span>Go to Homepage</span>
        </a>
    </div>

    <!-- Support Info -->
    <div class="bg-gradient-to-r from-green-50 to-cyan-50 border border-green-200 rounded-2xl p-6 max-w-md mx-auto">
        <div class="flex items-start space-x-3">
            <div class="w-12 h-12 bg-gradient-to-br from-green-400 to-green-500 rounded-xl flex items-center justify-center flex-shrink-0">
                <span class="text-2xl">💬</span>
            </div>
            <div class="text-left">
                <h4 class="font-semibold text-gray-900 mb-1">Need Immediate Help?</h4>
                <p class="text-sm text-gray-600 mb-3">
                    Our support team is available 24/7 to assist you.
                </p>
                <div class="flex flex-col sm:flex-row sm:items-center space-y-2 sm:space-y-0 sm:space-x-3">
                    <a href="mailto:reup.bellahoptions@gmail.com" 
                       class="inline-flex items-center justify-center bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-colors">
                        📧 Email Support
                    </a>
                    <a href="tel:+2349031412354" 
                       class="inline-flex items-center justify-center bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-colors">
                        📞 Call Us
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Details (For Developers) -->
    @if(app()->environment('local'))
    <div class="mt-8 max-w-3xl mx-auto">
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 text-left">
            <div class="flex items-center justify-between mb-4">
                <h4 class="font-semibold text-gray-200 flex items-center">
                    <span class="text-xl mr-2">🐛</span>
                    Error Details (Development Mode)
                </h4>
                <button onclick="copyErrorDetails()" 
                        class="text-xs text-gray-400 hover:text-white bg-gray-800 hover:bg-gray-700 px-3 py-1 rounded-lg transition-colors">
                    📋 Copy
                </button>
            </div>
            <pre id="errorDetails" class="text-xs text-gray-300 overflow-auto max-h-64 bg-gray-800 p-4 rounded-lg">
{{ $exception->getMessage() }}

Stack Trace:
{{ $exception->getTraceAsString() }}
</pre>
        </div>
    </div>
    @endif
</div>

<script>
function copyErrorDetails() {
    const errorDetails = document.getElementById('errorDetails').textContent;
    navigator.clipboard.writeText(errorDetails).then(() => {
        alert('Error details copied to clipboard');
    });
}

// Auto-retry after 10 seconds
setTimeout(() => {
    const retryBtn = document.querySelector('button[onclick*="reload"]');
    if (retryBtn) {
        retryBtn.innerHTML = '<span>🔄</span><span>Auto-retrying...</span>';
        window.location.reload();
    }
}, 10000);
</script>
@endsection