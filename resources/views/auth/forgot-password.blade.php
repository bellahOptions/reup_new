<x-guest-layout>
    @section('title', 'Reset your password - Secure Sign In')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8 md:py-12">
        <div class="max-w-md mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Logo/Header -->
            <div class="text-center mb-8 md:mb-12">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-lg mb-4">
                    <span class="text-3xl text-white">🔐</span>
                </div>
                <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">Reset Password</h1>
                <p class="text-gray-600 text-sm md:text-base">Enter your email to receive a reset link</p>
                <div class="w-24 h-1 bg-gradient-to-r from-green-500 to-emerald-600 mx-auto mt-4 rounded-full"></div>
            </div>

            <!-- Status Messages -->
            @if (session('status'))
                <div class="mb-6 bg-gradient-to-r from-green-50 to-emerald-50 border-l-4 border-green-500 p-4 rounded-r-xl">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <span class="text-green-500 text-xl">✅</span>
                        </div>
                        <div class="ml-3">
                            <p class="text-green-800 font-medium">{{ session('status') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 bg-gradient-to-r from-red-50 to-pink-50 border-l-4 border-red-500 p-4 rounded-r-xl">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <span class="text-red-500 text-xl">⚠️</span>
                        </div>
                        <div class="ml-3">
                            <p class="text-red-800 font-medium mb-2">Please fix the following:</p>
                            <ul class="text-red-700 text-sm list-disc pl-5 space-y-1">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Form Card -->
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 overflow-hidden">
                <div class="p-6 md:p-8">
                    <form method="POST" action="{{ route('password.email') }}" class="space-y-6">
                        @csrf

                        <!-- Email Input -->
                        <div>
                            <label for="email" class="block text-sm font-semibold text-gray-700 mb-2">Email Address</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <span class="text-gray-400">📧</span>
                                </div>
                                <input type="email" id="email" name="email" 
                                       value="{{ old('email') }}"
                                       placeholder="you@example.com"
                                       class="block w-full pl-12 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                                       required autofocus autocomplete="email">
                            </div>
                            <p class="text-xs text-gray-500 mt-2">
                                We'll send a password reset link to this email
                            </p>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" 
                                class="w-full bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-bold py-4 px-6 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-200 flex items-center justify-center space-x-2">
                            <span>Send Reset Link</span>
                            <span>➡️</span>
                        </button>
                    </form>

                    <!-- Back to Login -->
                    <div class="mt-6 pt-6 border-t border-gray-200 text-center">
                        <a href="{{ route('login') }}" 
                           class="inline-flex items-center text-green-600 hover:text-green-700 font-medium text-sm transition-all duration-200">
                            <span class="mr-2">←</span>
                            Back to Login
                        </a>
                    </div>
                </div>
            </div>

            <!-- Help Section -->
            <div class="mt-8 bg-white rounded-2xl shadow-sm border border-gray-200/60 p-6">
                <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                    <span class="text-xl mr-2">💡</span>
                    Need Help?
                </h3>
                <div class="space-y-3 text-sm text-gray-600">
                    <div class="flex items-start">
                        <span class="text-green-500 mr-2">✓</span>
                        <span>Check your spam folder if you don't receive the email</span>
                    </div>
                    <div class="flex items-start">
                        <span class="text-green-500 mr-2">✓</span>
                        <span>The reset link expires in 60 minutes</span>
                    </div>
                    <div class="flex items-start">
                        <span class="text-green-500 mr-2">✓</span>
                        <span>Contact support if you need assistance</span>
                    </div>
                </div>
                <div class="mt-4">
                    <a href="{{ route('contact') }}" class="text-green-600 hover:text-green-700 font-medium text-sm flex items-center">
                        <span class="mr-2">📞</span>
                        Contact Support
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>
</x-guest-layout>