@extends('errors.layout')

@section('title', 'Access Denied')

@section('content')
<div class="text-center max-w-2xl mx-auto">
    <!-- Animated Icon -->
    <div class="relative mb-8">
        <div class="w-32 h-32 mx-auto bg-gradient-to-br from-yellow-400 to-yellow-500 rounded-3xl flex items-center justify-center shadow-2xl">
            <div class="text-white text-5xl">🔒</div>
        </div>
        <div class="absolute -top-2 -right-2 w-10 h-10 bg-red-500 rounded-full flex items-center justify-center text-white font-bold">
            403
        </div>
        <div class="absolute -bottom-2 -left-2 w-12 h-12 bg-gradient-to-br from-gray-700 to-gray-900 rounded-full flex items-center justify-center text-white text-2xl">
            🚫
        </div>
    </div>

    <!-- Error Message -->
    <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">
        Access Denied
    </h1>
    
    <p class="text-lg text-gray-600 mb-6 max-w-md mx-auto">
        You don't have permission to access this page. This area is restricted to authorized users only.
    </p>

    <!-- User Info Card -->
    @auth
    <div class="mb-8 max-w-md mx-auto">
        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center space-x-4 mb-4">
                <div class="w-16 h-16 bg-gradient-to-br from-green-400 to-emerald-500 rounded-2xl flex items-center justify-center text-white font-bold text-2xl">
                    {{ substr(auth()->user()->name, 0, 1) }}
                </div>
                <div class="text-left">
                    <p class="font-semibold text-gray-900">{{ auth()->user()->name }}</p>
                    <p class="text-sm text-gray-600">{{ auth()->user()->email }}</p>
                    <p class="text-xs text-gray-500 mt-1">
                        {{ auth()->user()->is_admin ? '👑 Administrator' : '👤 Regular User' }}
                    </p>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="bg-gray-50 p-3 rounded-lg">
                    <p class="text-gray-600">Account Type</p>
                    <p class="font-semibold text-gray-900">
                        {{ auth()->user()->is_admin ? 'Admin' : 'User' }}
                    </p>
                </div>
                <div class="bg-gray-50 p-3 rounded-lg">
                    <p class="text-gray-600">Member Since</p>
                    <p class="font-semibold text-gray-900">
                        {{ auth()->user()->created_at->format('M Y') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
    @endauth

    <!-- Permission Levels -->
    <div class="mb-8 max-w-2xl mx-auto">
        <h3 class="font-semibold text-gray-900 mb-4">Available Access Levels</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white border border-gray-200 rounded-xl p-4 text-center hover:border-green-300 transition-colors">
                <div class="w-12 h-12 bg-gradient-to-br from-green-100 to-green-200 rounded-xl flex items-center justify-center mx-auto mb-3">
                    <span class="text-2xl">👤</span>
                </div>
                <h4 class="font-semibold text-gray-900 mb-1">Regular User</h4>
                <p class="text-xs text-gray-600">Access to basic services</p>
            </div>
            
            <div class="bg-white border border-gray-200 rounded-xl p-4 text-center hover:border-green-300 transition-colors">
                <div class="w-12 h-12 bg-gradient-to-br from-green-100 to-green-200 rounded-xl flex items-center justify-center mx-auto mb-3">
                    <span class="text-2xl">👑</span>
                </div>
                <h4 class="font-semibold text-gray-900 mb-1">Administrator</h4>
                <p class="text-xs text-gray-600">Full system access</p>
            </div>
            
            <div class="bg-white border border-gray-200 rounded-xl p-4 text-center hover:border-purple-300 transition-colors">
                <div class="w-12 h-12 bg-gradient-to-br from-purple-100 to-purple-200 rounded-xl flex items-center justify-center mx-auto mb-3">
                    <span class="text-2xl">⚡</span>
                </div>
                <h4 class="font-semibold text-gray-900 mb-1">Super Admin</h4>
                <p class="text-xs text-gray-600">Full system control</p>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="flex flex-col sm:flex-row items-center justify-center space-y-4 sm:space-y-0 sm:space-x-4 mb-10">
        @auth
        <a href="{{ url('/') }}" 
           class="w-full sm:w-auto bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-semibold px-8 py-3 rounded-xl shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center space-x-2">
            <span>🏠</span>
            <span>Go to Dashboard</span>
        </a>
        
        <form method="POST" action="{{ route('logout') }}" class="w-full sm:w-auto">
            @csrf
            <button type="submit" 
                    class="w-full sm:w-auto bg-white border border-gray-300 hover:border-red-500 text-gray-800 hover:text-red-700 font-semibold px-8 py-3 rounded-xl shadow-sm hover:shadow transition-all duration-200 flex items-center justify-center space-x-2">
                <span>🚪</span>
                <span>Logout</span>
            </button>
        </form>
        @else
        <a href="{{ route('login') }}" 
           class="w-full sm:w-auto bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-semibold px-8 py-3 rounded-xl shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center space-x-2">
            <span>🔑</span>
            <span>Login to Continue</span>
        </a>
        
        <a href="{{ route('register') }}" 
           class="w-full sm:w-auto bg-white border border-gray-300 hover:border-green-500 text-gray-800 hover:text-green-700 font-semibold px-8 py-3 rounded-xl shadow-sm hover:shadow transition-all duration-200 flex items-center justify-center space-x-2">
            <span>📝</span>
            <span>Create Account</span>
        </a>
        @endauth
    </div>

    <!-- Request Access -->
    <div class="bg-gradient-to-r from-yellow-50 to-amber-50 border border-yellow-200 rounded-2xl p-6 max-w-md mx-auto">
        <div class="flex items-start space-x-3">
            <div class="w-12 h-12 bg-gradient-to-br from-yellow-400 to-yellow-500 rounded-xl flex items-center justify-center flex-shrink-0">
                <span class="text-2xl">📋</span>
            </div>
            <div class="text-left">
                <h4 class="font-semibold text-gray-900 mb-1">Need Access?</h4>
                <p class="text-sm text-gray-600 mb-3">
                    Contact an administrator to request access to this page.
                </p>
                <a href="mailto:reup.bellahoptions@gmail.com?subject=Access%20Request%20for%20{{ urlencode(request()->fullUrl()) }}" 
                   class="inline-flex items-center justify-center bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-colors">
                    📧 Request Access
                </a>
            </div>
        </div>
    </div>
</div>
@endsection