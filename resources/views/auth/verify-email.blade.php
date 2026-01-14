<x-guest-layout>
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8 md:py-12">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="text-center mb-8 md:mb-12">
                <div class="inline-flex items-center justify-center w-20 h-20 bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-lg mb-4">
                    <span class="text-4xl text-white">✉️</span>
                </div>
                <h1 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">Verify Your Email</h1>
                <p class="text-gray-600 text-sm md:text-base">We've sent a verification link to your email</p>
                <div class="w-24 h-1 bg-gradient-to-r from-green-500 to-emerald-600 mx-auto mt-4 rounded-full"></div>
            </div>

            <!-- Main Content Card -->
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 overflow-hidden">
                <div class="p-6 md:p-8 lg:p-10">
                    <!-- Status Messages -->
                    @if (session('status') == 'verification-link-sent')
                        <div class="mb-6 bg-gradient-to-r from-green-50 to-emerald-50 border-l-4 border-green-500 p-4 rounded-r-xl">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <span class="text-green-500 text-xl">✅</span>
                                </div>
                                <div class="ml-3">
                                    <p class="text-green-800 font-medium">A fresh verification link has been sent to your email address.</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- Verification Steps -->
                    <div class="space-y-6">
                        <!-- Step 1 -->
                        <div class="flex items-start space-x-4">
                            <div class="flex-shrink-0 w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-full flex items-center justify-center">
                                <span class="text-white font-bold text-sm">1</span>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900 mb-1">Check Your Inbox</h3>
                                <p class="text-gray-600 text-sm">Look for an email from <strong>{{ config('app.name') }}</strong> with the subject "Verify Your Email Address"</p>
                            </div>
                        </div>

                        <!-- Step 2 -->
                        <div class="flex items-start space-x-4">
                            <div class="flex-shrink-0 w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-full flex items-center justify-center">
                                <span class="text-white font-bold text-sm">2</span>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900 mb-1">Click the Verification Link</h3>
                                <p class="text-gray-600 text-sm">Open the email and click the "Verify Email" button or link</p>
                            </div>
                        </div>

                        <!-- Step 3 -->
                        <div class="flex items-start space-x-4">
                            <div class="flex-shrink-0 w-8 h-8 bg-gradient-to-br from-green-500 to-emerald-600 rounded-full flex items-center justify-center">
                                <span class="text-white font-bold text-sm">3</span>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900 mb-1">Get Full Access</h3>
                                <p class="text-gray-600 text-sm">Once verified, you'll have full access to all features</p>
                            </div>
                        </div>
                    </div>

                    <!-- Email Preview -->
                    <div class="mt-8 bg-gradient-to-r from-green-50 to-emerald-50 p-5 rounded-xl border border-green-200">
                        <div class="flex items-center mb-4">
                            <span class="text-green-500 text-2xl mr-3">📧</span>
                            <div>
                                <h4 class="font-semibold text-green-800">What the email looks like:</h4>
                                <p class="text-green-700 text-sm">From: reup.bellahoptions@gmail.com</p>
                            </div>
                        </div>
                        <div class="bg-white p-4 rounded-lg border border-green-100">
                            <p class="text-gray-700 text-sm font-semibold mb-2">Subject: Verify Your Email Address</p>
                            <p class="text-gray-600 text-sm">
                                Please click the button below to verify your email address and activate your account.
                            </p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="mt-8 pt-8 border-t border-gray-200">
                        <!-- Resend Form --> 
                        <form method="POST" action="{{ route('verification.send') }}" class="mb-6">
                            @csrf
                            <button type="submit" 
                                    class="w-full bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-bold py-4 px-6 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-200 flex items-center justify-center space-x-2">
                                <span>Resend Verification Email</span>
                                <span>🔄</span>
                            </button>
                        </form>

                        <!-- Alternative Actions -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <a href="{{ route('logout') }}" 
                               onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
                               class="bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold py-3 px-6 rounded-xl text-center transition-all duration-200 border border-gray-300 flex items-center justify-center space-x-2">
                                <span>Logout</span>
                                <span>🚪</span>
                            </a>
                            
                            <a href="{{ route('dashboard') }}" 
                               class="bg-gradient-to-r from-blue-500 to-cyan-600 hover:from-blue-600 hover:to-cyan-700 text-white font-semibold py-3 px-6 rounded-xl text-center shadow-md hover:shadow-lg transition-all duration-200 flex items-center justify-center space-x-2">
                                <span>Go to Dashboard</span>
                                <span>→</span>
                            </a>
                        </div>
                    </div>

                    <!-- Help Section -->
                    <div class="mt-8 bg-yellow-50/50 border border-yellow-200 p-5 rounded-xl">
                        <div class="flex">
                            <span class="text-yellow-500 text-xl mr-3">💡</span>
                            <div>
                                <h4 class="font-semibold text-yellow-800 mb-2">Didn't receive the email?</h4>
                                <ul class="text-yellow-700 text-sm space-y-1">
                                    <li>• Check your spam or junk folder</li>
                                    <li>• Make sure you entered the correct email address</li>
                                    <li>• Wait a few minutes and try again</li>
                                    <li>• Contact support if the issue persists</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Logout Form (hidden) -->
            <form id="logout-form" action="{{ route('logout') }}" method="POST" class="hidden">
                @csrf
            </form>
        </div>
    </div>
</main>
</x-guest-layout>
