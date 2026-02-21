<x-guest-layout>
    @section('title', 'Reset your password')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8 md:py-12">
        <div class="max-w-md mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Logo/Header -->
            <div class="text-center mb-8 md:mb-12">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-lg mb-4">
                    <span class="text-3xl text-white">🔄</span>
                </div>
                <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">Create New Password</h1>
                <p class="text-gray-600 text-sm md:text-base">Enter your new password below</p>
                <div class="w-24 h-1 bg-gradient-to-r from-green-500 to-emerald-600 mx-auto mt-4 rounded-full"></div>
            </div>

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
                    <form method="POST" action="{{ route('password.update') }}" class="space-y-6">
                        @csrf
                        <input type="hidden" name="token" value="{{ $request->route('token') }}">

                        <!-- Email (hidden) -->
                        <input type="hidden" name="email" value="{{ old('email', $request->email) }}">

                        <!-- New Password -->
                        <div>
                            <label for="password" class="block text-sm font-semibold text-gray-700 mb-2">New Password</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <span class="text-gray-400">🔒</span>
                                </div>
                                <input type="password" id="password" name="password" 
                                       placeholder="Enter new password"
                                       class="block w-full pl-12 pr-10 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                                       required autofocus autocomplete="new-password">
                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                    <button type="button" onclick="togglePassword('password')" 
                                            class="text-gray-400 hover:text-gray-600 focus:outline-none">
                                        <span class="text-sm">👁️</span>
                                    </button>
                                </div>
                            </div>
                            <div class="mt-2 space-y-1">
                                <p class="text-xs text-gray-500">💡 Must be at least 8 characters</p>
                            </div>
                        </div>

                        <!-- Confirm Password -->
                        <div>
                            <label for="password_confirmation" class="block text-sm font-semibold text-gray-700 mb-2">Confirm Password</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <span class="text-gray-400">✅</span>
                                </div>
                                <input type="password" id="password_confirmation" name="password_confirmation" 
                                       placeholder="Confirm new password"
                                       class="block w-full pl-12 pr-10 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                                       required autocomplete="new-password">
                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                    <button type="button" onclick="togglePassword('password_confirmation')" 
                                            class="text-gray-400 hover:text-gray-600 focus:outline-none">
                                        <span class="text-sm">👁️</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Password Requirements -->
                        <div class="bg-green-50/50 p-4 rounded-xl border border-green-100">
                            <h4 class="font-semibold text-green-700 mb-2 flex items-center">
                                <span class="text-green-500 mr-2">📋</span>
                                Password Requirements
                            </h4>
                            <ul class="text-xs text-gray-600 space-y-1">
                                <li class="flex items-center">
                                    <span id="lengthCheck" class="mr-2">⭕</span>
                                    At least 8 characters
                                </li>
                                <li class="flex items-center">
                                    <span id="matchCheck" class="mr-2">⭕</span>
                                    Passwords must match
                                </li>
                            </ul>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" id="submitBtn"
                                class="w-full bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-bold py-4 px-6 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-200 flex items-center justify-center space-x-2 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span>Reset Password</span>
                            <span>🔄</span>
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
        </div>
    </div>
</main>

<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    field.type = field.type === 'password' ? 'text' : 'password';
}

function checkPasswordRequirements() {
    const password = document.getElementById('password').value;
    const confirm = document.getElementById('password_confirmation').value;
    const submitBtn = document.getElementById('submitBtn');
    
    // Check length
    const lengthCheck = document.getElementById('lengthCheck');
    if (password.length >= 8) {
        lengthCheck.textContent = '✅';
        lengthCheck.className = 'text-green-500 mr-2';
    } else {
        lengthCheck.textContent = '⭕';
        lengthCheck.className = 'text-gray-400 mr-2';
    }
    
    // Check match
    const matchCheck = document.getElementById('matchCheck');
    if (password && confirm && password === confirm) {
        matchCheck.textContent = '✅';
        matchCheck.className = 'text-green-500 mr-2';
    } else {
        matchCheck.textContent = '⭕';
        matchCheck.className = 'text-gray-400 mr-2';
    }
    
    // Enable/disable submit button
    if (password.length >= 8 && password === confirm) {
        submitBtn.disabled = false;
    } else {
        submitBtn.disabled = true;
    }
}

// Add event listeners
document.getElementById('password').addEventListener('input', checkPasswordRequirements);
document.getElementById('password_confirmation').addEventListener('input', checkPasswordRequirements);

// Initial check
document.addEventListener('DOMContentLoaded', checkPasswordRequirements);
</script>
</x-guest-layout>