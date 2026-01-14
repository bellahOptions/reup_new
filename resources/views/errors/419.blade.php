@extends('errors.layout')

@section('title', 'Session Expired')

@section('content')
<div class="text-center max-w-2xl mx-auto">
    <!-- Animated Icon -->
    <div class="relative mb-8">
        <div class="w-32 h-32 mx-auto bg-gradient-to-br from-orange-400 to-orange-500 rounded-3xl flex items-center justify-center shadow-2xl animate-pulse">
            <div class="text-white text-5xl">⏰</div>
        </div>
        <div class="absolute -top-2 -right-2 w-10 h-10 bg-red-500 rounded-full flex items-center justify-center text-white font-bold">
            419
        </div>
        <div class="absolute -bottom-2 -left-2 w-12 h-12 bg-gradient-to-br from-gray-700 to-gray-900 rounded-full flex items-center justify-center text-white text-2xl">
            ⌛
        </div>
    </div>

    <!-- Error Message -->
    <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">
        Session Expired
    </h1>
    
    <p class="text-lg text-gray-600 mb-6 max-w-md mx-auto">
        Your session has timed out for security reasons. Please refresh the page and try again.
    </p>

    <!-- Security Info -->
    <div class="mb-8 max-w-md mx-auto">
        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center space-x-3 mb-4">
                <div class="w-12 h-12 bg-gradient-to-br from-green-100 to-green-200 rounded-xl flex items-center justify-center">
                    <span class="text-2xl">🛡️</span>
                </div>
                <div class="text-left">
                    <h4 class="font-semibold text-gray-900">Security Feature</h4>
                    <p class="text-sm text-gray-600">This is a security measure to protect your account</p>
                </div>
            </div>
            
            <div class="space-y-3 text-sm">
                <div class="flex items-center space-x-2">
                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                    <span class="text-gray-700">Auto-logout after 30 minutes of inactivity</span>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                    <span class="text-gray-700">CSRF protection for secure transactions</span>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                    <span class="text-gray-700">Encrypted session data</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Solution Steps -->
    <div class="mb-8 max-w-2xl mx-auto">
        <h3 class="font-semibold text-gray-900 mb-4">Quick Solution</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white border border-gray-200 rounded-xl p-4 text-center hover:border-green-300 transition-colors">
                <div class="w-10 h-10 bg-gradient-to-br from-green-100 to-green-200 rounded-xl flex items-center justify-center mx-auto mb-3">
                    <span class="text-xl">1️⃣</span>
                </div>
                <h4 class="font-semibold text-gray-900 mb-1">Refresh Page</h4>
                <p class="text-xs text-gray-600">Click the button below</p>
            </div>
            
            <div class="bg-white border border-gray-200 rounded-xl p-4 text-center hover:border-green-300 transition-colors">
                <div class="w-10 h-10 bg-gradient-to-br from-green-100 to-green-200 rounded-xl flex items-center justify-center mx-auto mb-3">
                    <span class="text-xl">2️⃣</span>
                </div>
                <h4 class="font-semibold text-gray-900 mb-1">Re-enter Data</h4>
                <p class="text-xs text-gray-600">Fill form again if needed</p>
            </div>
            
            <div class="bg-white border border-gray-200 rounded-xl p-4 text-center hover:border-green-300 transition-colors">
                <div class="w-10 h-10 bg-gradient-to-br from-purple-100 to-purple-200 rounded-xl flex items-center justify-center mx-auto mb-3">
                    <span class="text-xl">3️⃣</span>
                </div>
                <h4 class="font-semibold text-gray-900 mb-1">Continue</h4>
                <p class="text-xs text-gray-600">Complete your action</p>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="flex flex-col sm:flex-row items-center justify-center space-y-4 sm:space-y-0 sm:space-x-4 mb-10">
        <button onclick="window.location.reload()" 
                class="w-full sm:w-auto bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-semibold px-8 py-3 rounded-xl shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center space-x-2">
            <span>🔄</span>
            <span>Refresh & Retry</span>
        </button>
        
        <a href="{{ url('/') }}" 
           class="w-full sm:w-auto bg-white border border-gray-300 hover:border-green-500 text-gray-800 hover:text-green-700 font-semibold px-8 py-3 rounded-xl shadow-sm hover:shadow transition-all duration-200 flex items-center justify-center space-x-2">
            <span>🏠</span>
            <span>Go to Homepage</span>
        </a>
    </div>

    <!-- Browser Compatibility Check -->
    <div class="bg-gradient-to-r from-green-50 to-cyan-50 border border-green-200 rounded-2xl p-6 max-w-md mx-auto">
        <div class="flex items-start space-x-3">
            <div class="w-12 h-12 bg-gradient-to-br from-green-400 to-green-500 rounded-xl flex items-center justify-center flex-shrink-0">
                <span class="text-2xl">🌐</span>
            </div>
            <div class="text-left">
                <h4 class="font-semibold text-gray-900 mb-1">Browser Issues?</h4>
                <p class="text-sm text-gray-600 mb-3">
                    Try these troubleshooting steps:
                </p>
                <ul class="text-xs text-gray-600 space-y-1">
                    <li class="flex items-start">
                        <span class="mr-2">•</span>
                        <span>Clear browser cache and cookies</span>
                    </li>
                    <li class="flex items-start">
                        <span class="mr-2">•</span>
                        <span>Update your browser to latest version</span>
                    </li>
                    <li class="flex items-start">
                        <span class="mr-2">•</span>
                        <span>Disable browser extensions temporarily</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-refresh countdown
let countdown = 10;
const countdownElement =