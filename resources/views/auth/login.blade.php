<x-guest-layout>
    <div class="min-h-screen flex">
        <!-- Left Side - Form -->
        <div class="flex-1 flex items-center justify-center px-4 sm:px-6 lg:px-8 bg-white">
            <div class="w-full max-w-md space-y-8">
                <!-- Logo & Header -->
                <div class="text-center">
                    <a href="/">
                        <img src="{{ asset('images/reup-03.svg') }}" alt="ReUp Logo" class="h-12 mx-auto mb-8">
                    </a>
                    <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-2">
                        Welcome back! 👋
                    </h2>
                    <p class="text-gray-600 text-sm md:text-base">
                        Sign in to continue to your account
                    </p>
                </div>

                <!-- Session Status -->
                <x-auth-session-status class="mb-4" :status="session('status')" />

                <form method="POST" action="{{ route('login') }}" class="space-y-6">
                    @csrf

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
                            autofocus 
                            autocomplete="username"
                            placeholder="your@email.com" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div> 

                    <!-- Password -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <x-label for="password" :value="__('Password')" class="text-gray-700 font-semibold" />
                            @if (Route::has('password.request'))
                                <a class="text-sm text-green-600 hover:text-green-700 font-medium transition-colors duration-200" href="{{ route('password.request') }}">
                                    {{ __('Forgot?') }}
                                </a>
                            @endif
                        </div>
                        <x-input 
                            id="password" 
                            class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                            type="password"
                            name="password"
                            required 
                            autocomplete="current-password" 
                            placeholder="Enter your password" />
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>

                    <!-- Remember Me -->
                    <div class="flex items-center">
                        <input 
                            id="remember_me" 
                            type="checkbox" 
                            class="w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500 transition-all duration-200" 
                            name="remember">
                        <label for="remember_me" class="ml-2 block text-sm text-gray-700">
                            {{ __('Remember me') }}
                        </label>
                    </div>

                    <!-- Submit Button -->
                    <div>
                        <x-button class="w-full justify-center bg-green-600 hover:bg-green-700 text-white font-semibold py-3 px-4 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-200">
                            {{ __('Sign in') }} 🚀
                        </x-button>
                    </div>

                    <!-- Divider -->
                    <div class="relative">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-gray-300"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-2 bg-white text-gray-500">Or</span>
                        </div>
                    </div>

                    <!-- Sign Up Link -->
                    <div class="text-center">
                        <p class="text-sm text-gray-600">
                            Don't have an account?
                            <a href="{{ route('register') }}" class="font-semibold text-green-600 hover:text-green-700 transition-colors duration-200">
                                Sign up for free
                            </a>
                        </p>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Side - Image/Brand (Hidden on mobile) -->
        <div class="hidden lg:flex lg:flex-1 bg-gradient-to-br from-green-500 to-green-800 relative overflow-hidden">            
            <div class="relative z-10 flex flex-col items-center justify-center text-center p-12">
                <img src="{{ asset('images/appcard.png')}}" alt="Reup Logo" class="h-auto mb-6">
                </div>
            </div>
        </div>
    </div>
</x-guest-layout>