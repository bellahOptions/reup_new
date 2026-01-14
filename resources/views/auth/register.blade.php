<x-guest-layout>
    <div class="min-h-screen flex">
        <!-- Left Side - Image/Brand (Hidden on mobile) -->
        <div class="hidden lg:flex lg:flex-1 bg-gradient-to-br from-emerald-500 to-green-600 relative overflow-hidden">
            <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCA2MCA2MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZyBmaWxsPSJub25lIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiPjxnIGZpbGw9IiNmZmZmZmYiIGZpbGwtb3BhY2l0eT0iMC4xIj48cGF0aCBkPSJNMzYgMzRjMC0yLjIxIDEuNzktNCA0LTRzNCAxLjc5IDQgNC0xLjc5IDQtNCA0LTQtMS43OS00LTR6bTAgMTBjMC0yLjIxIDEuNzktNCA0LTRzNCAxLjc5IDQgNC0xLjc5IDQtNCA0LTQtMS43OS00LTR6TTE2IDM0YzAtMi4yMSAxLjc5LTQgNC00czQgMS43OSA0IDQtMS43OSA0LTQgNC00LTEuNzktNC00em0wIDEwYzAtMi4yMSAxLjc5LTQgNC00czQgMS43OSA0IDQtMS43OSA0LTQgNC00LTEuNzktNC00eiIvPjwvZz48L2c+PC9zdmc+')] opacity-50"></div>
            <div class="relative z-10 flex flex-col items-center justify-center text-center text-white p-12">
                <div class="mb-8">
                    <div class="text-7xl mb-4">🚀</div>
                    <h3 class="text-4xl font-bold mb-4">Join ReUp Today</h3>
                    <p class="text-xl text-green-100 max-w-md">
                        Over 50,000+ users trust ReUp for their daily transactions.
                    </p>
                </div>
                <div class="space-y-4 mt-8 text-left max-w-sm">
                    <div class="flex items-center space-x-3">
                        <div class="flex-shrink-0 w-10 h-10 bg-white/20 rounded-full flex items-center justify-center">
                            <span class="text-xl">✓</span>
                        </div>
                        <p class="text-green-100">Instant transactions</p>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="flex-shrink-0 w-10 h-10 bg-white/20 rounded-full flex items-center justify-center">
                            <span class="text-xl">✓</span>
                        </div>
                        <p class="text-green-100">Bank-level security</p>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="flex-shrink-0 w-10 h-10 bg-white/20 rounded-full flex items-center justify-center">
                            <span class="text-xl">✓</span>
                        </div>
                        <p class="text-green-100">24/7 support</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side - Form -->
        <div class="flex-1 flex items-center justify-center px-4 sm:px-6 lg:px-8 bg-white">
            <div class="w-full max-w-md space-y-8 py-12">
                <!-- Logo & Header -->
                <div class="text-center">
                    <a href="/">
                        <img src="{{ asset('images/reup-03.svg') }}" alt="ReUp Logo" class="h-12 mx-auto mb-8">
                    </a>
                    <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">
                        Create your account ✨
                    </h2>
                    <p class="text-gray-600 text-sm md:text-base">
                        Start paying bills faster today
                    </p>
                </div>

                <form method="POST" action="{{ route('register') }}" class="space-y-5">
                    @csrf

                    <!-- Name -->
                    <div>
                        <x-label for="name" :value="__('Full Name')" class="text-gray-700 font-semibold mb-2" />
                        <x-input 
                            id="name" 
                            class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                            type="text" 
                            name="name" 
                            :value="old('name')" 
                            required 
                            autofocus 
                            autocomplete="name"
                            placeholder="John Doe" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <!-- Email Address -->
                    <div>
                        <x-label for="email" :value="__('Email')" class="text-gray-700 font-semibold mb-2" />
                        <x-input 
                            id="email" 
                            class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                            type="email" 
                            name="email" 
                            :value="old('email')" 
                            required 
                            autocomplete="username"
                            placeholder="your@email.com" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <!-- Password -->
                    <div>
                        <x-label for="password" :value="__('Password')" class="text-gray-700 font-semibold mb-2" />
                        <x-input 
                            id="password" 
                            class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                            type="password"
                            name="password"
                            required 
                            autocomplete="new-password"
                            placeholder="Min. 8 characters" />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>

                    <!-- Confirm Password -->
                    <div>
                        <x-label for="password_confirmation" :value="__('Confirm Password')" class="text-gray-700 font-semibold mb-2" />
                        <x-input 
                            id="password_confirmation" 
                            class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                            type="password"
                            name="password_confirmation"
                            required 
                            autocomplete="new-password"
                            placeholder="Re-enter password" />
                        <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                    </div>

                    <!-- Terms & Conditions -->
                    <div class="flex items-start">
                        <input 
                            id="terms" 
                            type="checkbox" 
                            class="w-4 h-4 mt-1 text-green-600 border-gray-300 rounded focus:ring-green-500 transition-all duration-200" 
                            required>
                        <label for="terms" class="ml-2 block text-sm text-gray-700">
                            I agree to the 
                            <a href="#" class="text-green-600 hover:text-green-700 font-medium">Terms of Service</a> 
                            and 
                            <a href="#" class="text-green-600 hover:text-green-700 font-medium">Privacy Policy</a>
                        </label>
                    </div>

                    <!-- Submit Button -->
                    <div>
                        <x-button class="w-full justify-center bg-green-600 hover:bg-green-700 text-white font-semibold py-3 px-4 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-200">
                            {{ __('Create Account') }} 🚀
                        </x-button>
                    </div>

                    <!-- Divider -->
                    <div class="relative">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-gray-300"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-2 bg-white text-gray-500">Already have an account?</span>
                        </div>
                    </div>

                    <!-- Sign In Link -->
                    <div class="text-center">
                        <a href="{{ route('login') }}" class="font-semibold text-green-600 hover:text-green-700 transition-colors duration-200">
                            Sign in instead
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-guest-layout>