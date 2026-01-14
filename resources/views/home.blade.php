@extends('layouts.main')

@section('main')
<!--hero-->
    <div class="relative min-h-screen overflow-hidden bg-gradient-to-br from-green-50 via-white to-emerald-50">
    <!-- Background Decorative Elements -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <!-- Gradient Orbs -->
        <div class="absolute top-20 -left-20 w-72 h-72 bg-green-200/30 rounded-full blur-3xl animate-pulse"></div>
        <div class="absolute bottom-20 -right-20 w-96 h-96 bg-emerald-200/30 rounded-full blur-3xl animate-pulse" style="animation-delay: 1s;"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-green-100/20 rounded-full blur-3xl"></div>
        
        <!-- Floating Icons -->
        <div class="absolute top-32 left-[10%] text-6xl animate-bounce" style="animation-duration: 3s;">📱</div>
        <div class="absolute top-48 right-[15%] text-5xl animate-bounce" style="animation-duration: 4s; animation-delay: 0.5s;">💳</div>
        <div class="absolute bottom-40 left-[15%] text-5xl animate-bounce" style="animation-duration: 3.5s; animation-delay: 1s;">⚡</div>
        <div class="absolute bottom-32 right-[20%] text-6xl animate-bounce" style="animation-duration: 4s; animation-delay: 1.5s;">🚀</div>
        <div class="absolute top-[60%] left-[8%] text-4xl animate-bounce" style="animation-duration: 3s; animation-delay: 2s;">💰</div>
        <div class="absolute top-[40%] right-[10%] text-4xl animate-bounce" style="animation-duration: 3.5s; animation-delay: 0.8s;">📡</div>
    </div>

    <!-- Main Content -->
    <div class="relative z-10 grid place-items-center min-h-screen py-20 px-4">
        <!-- Hero Headlines -->
        <div class="-space-y-4 text-center md:-space-y-6 mb-12">
            <div class="transform hover:scale-105 transition-transform duration-300">
                <h1 class="text-center hover:rotate-1 bg-gradient-to-r from-green-200 to-green-300 p-6 md:p-10 border-2 border-green-400/30 shadow-lg hover:shadow-xl transition-shadow duration-300 w-auto inline-block rounded-2xl rotate-2 text-gray-800 text-3xl md:text-5xl lg:text-6xl font-bold">
                    💸 Pay Bills Fast.
                </h1>
            </div>
            
            <div class="transform hover:scale-105 transition-transform duration-300">
                <h1 class="text-center hover:-rotate-2 bg-gradient-to-r from-green-300 to-green-400 p-6 md:p-10 border-2 border-green-500/30 shadow-lg hover:shadow-xl transition-shadow duration-300 w-auto inline-block rounded-2xl -rotate-2 font-bold text-gray-800 text-4xl md:text-6xl lg:text-7xl">
                    ⚡ Recharge Instantly
                </h1>
            </div>
            
            <div class="transform hover:scale-105 transition-transform duration-300">
                <h1 class="text-center hover:rotate-1 bg-gradient-to-r from-green-400 to-green-500 p-6 md:p-10 border-2 border-green-600/30 shadow-lg hover:shadow-xl transition-shadow duration-300 w-auto inline-block rounded-2xl rotate-2 text-white text-3xl md:text-5xl lg:text-6xl font-bold">
                    📱 Stay Connected.
                </h1>
            </div>
        </div>

        <!-- Description -->
        <div class="max-w-3xl mx-auto">
            <p class="text-center text-gray-700 px-6 md:px-10 py-6 text-lg md:text-2xl leading-relaxed">
                Buy airtime, data, and pay bills instantly with <span class="font-bold text-green-600">ReUp</span>. 
                Fast, secure, and stress-free — anytime, anywhere. 🌍✨
            </p>
        </div>

        <!-- CTA Buttons -->
        <div class="flex flex-col sm:flex-row items-center justify-center gap-4 mt-8 px-4">
            <a href="#" 
               class="inline-flex items-center justify-center bg-green-500 hover:bg-green-600 text-white font-semibold px-8 py-4 rounded-full shadow-lg hover:shadow-xl transform hover:scale-105 transition-all duration-300 text-base md:text-lg w-full sm:w-auto">
                🚀 Get Started
            </a>
            <a href="#" 
               class="inline-flex items-center justify-center ring-2 ring-green-500 hover:bg-green-50 text-gray-700 hover:text-green-600 font-semibold px-8 py-4 rounded-full shadow-md hover:shadow-lg transform hover:scale-105 transition-all duration-300 text-base md:text-lg w-full sm:w-auto">
                🔐 Login
            </a>
        </div>
    </div>

    <!-- Trust Badges Section -->
        <div id="trust-badges" class="w-full max-w-4xl mx-auto px-4 mb-16">
            <p class="text-center text-sm md:text-base text-gray-500 font-medium mb-6 uppercase tracking-wide">
                Trusted & Verified by
            </p>
            <div class="flex flex-wrap items-center justify-center gap-8 md:gap-12 lg:gap-16">        
                <div class="grayscale hover:grayscale-0 opacity-50 hover:opacity-100 transition-all duration-300 transform hover:scale-110 cursor-pointer">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/0/0b/Paystack_Logo.png/1200px-Paystack_Logo.png" 
                         alt="Paystack Verified" 
                         class="h-8 md:h-10 object-contain">
                </div>
                <div class="grayscale hover:grayscale-0 opacity-50 hover:opacity-100 transition-all duration-300 transform hover:scale-110 cursor-pointer">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/9/9e/Flutterwave_Logo.png" 
                         alt="Flutterwave Verified" 
                         class="h-8 md:h-10 object-contain">
                </div>
                <div class="grayscale hover:grayscale-0 opacity-50 hover:opacity-100 transition-all duration-300 transform hover:scale-110 cursor-pointer">
                    <img src="https://swiftbills.ng/wp-content/uploads/2023/06/image-1.png" 
                         alt="Monnify Verified" 
                         class="h-8 md:h-10 object-contain">
                </div>
            </div>
        </div>
  <!-- Marquee Announcements -->
            <div class="bg-gradient-to-r from-green-100 to-emerald-100 border border-green-200 rounded-2xl py-3 px-4 mb-8 overflow-hidden">
                <div class="flex items-center">
                    <span class="text-green-600 font-bold mr-3 flex-shrink-0">📢 Announcements:</span>
                    <div class="marquee-container overflow-hidden flex-1">
                        @include('layouts.marquee')
                    </div>
                </div>
            </div>
        <!-- Services Section -->
<section class="relative py-16 md:py-24 bg-gray-50 overflow-hidden">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Section Header -->
        <div class="text-center mb-12 md:mb-16">
            <h2 class="text-3xl md:text-5xl font-bold text-gray-900 mb-4">
                Electronic Top Up
            </h2>
            <p class="text-base md:text-xl text-gray-600">
                Electronic vending of data and airtime and so much more
            </p>
        </div>

        <!-- Services Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8">
            <!-- Buy Data Bundle -->
            <div class="group bg-white rounded-2xl shadow-md hover:shadow-xl transition-all duration-300 overflow-hidden transform hover:-translate-y-2">
                <div class="relative h-56 overflow-hidden">
                    <img src="https://www.nairaland.com/attachments/8246111_opeyemmithdataconnect20181201172649_jpeg426cac20996fa907c16f82fee9242444" 
                         alt="Data Bundle" 
                         class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
                    <div class="absolute bottom-4 left-4 right-4">
                        <span class="inline-block px-3 py-1 bg-red-500 text-white text-sm font-semibold rounded-full">
                            📊 Data Plans
                        </span>
                    </div>
                </div>
                <div class="p-6">
                    <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-3">Buy Data Bundle</h3>
                    <p class="text-sm md:text-base text-gray-600 mb-4 leading-relaxed">
                        Start enjoying this very low rates for your internet browsing databundle.
                    </p>
                    <a href="#" class="inline-flex items-center text-blue-600 hover:text-blue-700 font-semibold text-sm md:text-base group-hover:translate-x-1 transition-transform duration-300">
                        Buy Now
                        <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>
            </div>

            <!-- Buy Airtime -->
            <div class="group bg-white rounded-2xl shadow-md hover:shadow-xl transition-all duration-300 overflow-hidden transform hover:-translate-y-2">
                <div class="relative h-56 overflow-hidden">
                    <img src="https://www.nairaland.com/attachments/8246111_opeyemmithdataconnect20181201172649_jpeg426cac20996fa907c16f82fee9242444" 
                         alt="Buy Airtime" 
                         class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
                    <div class="absolute bottom-4 left-4 right-4">
                        <span class="inline-block px-3 py-1 bg-blue-500 text-white text-sm font-semibold rounded-full">
                            📱 Instant Top-up
                        </span>
                    </div>
                </div>
                <div class="p-6">
                    <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-3">Buy Airtime</h3>
                    <p class="text-sm md:text-base text-gray-600 mb-4 leading-relaxed">
                        Enjoy huge discount when you purchase airtime.
                    </p>
                    <a href="#" class="inline-flex items-center text-blue-600 hover:text-blue-700 font-semibold text-sm md:text-base group-hover:translate-x-1 transition-transform duration-300">
                        Buy Now
                        <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>
            </div>

            <!-- CableTV Subscription -->
            <div class="group bg-white rounded-2xl shadow-md hover:shadow-xl transition-all duration-300 overflow-hidden transform hover:-translate-y-2">
                <div class="relative h-56 overflow-hidden">
                    <img src="https://www.rizpay.app/_next/image?url=%2Fimages%2Fblogs%2Fnigeria-cable-tvs.jpg&w=3840&q=75" 
                         alt="Cable TV" 
                         class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
                    <div class="absolute bottom-4 left-4 right-4">
                        <span class="inline-block px-3 py-1 bg-purple-500 text-white text-sm font-semibold rounded-full">
                            📺 TV Subscriptions
                        </span>
                    </div>
                </div>
                <div class="p-6">
                    <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-3">CableTV Subscription</h3>
                    <p class="text-sm md:text-base text-gray-600 mb-4 leading-relaxed">
                        Instant recharge of DStv, GOtv and Startimes e.t.c.
                    </p>
                    <a href="#" class="inline-flex items-center text-blue-600 hover:text-blue-700 font-semibold text-sm md:text-base group-hover:translate-x-1 transition-transform duration-300">
                        Pay Now
                        <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>
            </div>

            <!-- Pay Electricity Bill -->
            <div class="group bg-white rounded-2xl shadow-md hover:shadow-xl transition-all duration-300 overflow-hidden transform hover:-translate-y-2">
                <div class="relative h-56 overflow-hidden">
                    <img src="https://global.ariseplay.com/amg/www.arise.tv/uploads/2023/06/Electricity-Distribution-Companies-DisCos.webp" 
                         alt="Electricity Bill" 
                         class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
                    <div class="absolute bottom-4 left-4 right-4">
                        <span class="inline-block px-3 py-1 bg-yellow-500 text-white text-sm font-semibold rounded-full">
                            💡 Power Bills
                        </span>
                    </div>
                </div>
                <div class="p-6">
                    <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-3">Pay Electricity Bill</h3>
                    <p class="text-sm md:text-base text-gray-600 mb-4 leading-relaxed">
                        Pay you electricity bill online e.g. EKEDC, IKEDC, AEDC, PHEDC e.t.c.
                    </p>
                    <a href="#" class="inline-flex items-center text-blue-600 hover:text-blue-700 font-semibold text-sm md:text-base group-hover:translate-x-1 transition-transform duration-300">
                        Pay Now
                        <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>
            </div>

            <!-- Buy WAEC e-pin -->
            <div class="group bg-white rounded-2xl shadow-md hover:shadow-xl transition-all duration-300 overflow-hidden transform hover:-translate-y-2">
                <div class="relative h-56 overflow-hidden">
                    <img src="https://edupast.com.ng/wp-content/uploads/2024/03/waec.jpg" 
                         alt="WAEC Exam" 
                         class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
                    <div class="absolute bottom-4 left-4 right-4">
                        <span class="inline-block px-3 py-1 bg-orange-500 text-white text-sm font-semibold rounded-full">
                            📝 WAEC E-PIN
                        </span>
                    </div>
                </div>
                <div class="p-6">
                    <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-3">Buy WAEC e-pin</h3>
                    <p class="text-sm md:text-base text-gray-600 mb-4 leading-relaxed">
                        Buy WAEC e-pin for Verification & Registration
                    </p>
                    <a href="#" class="inline-flex items-center text-blue-600 hover:text-blue-700 font-semibold text-sm md:text-base group-hover:translate-x-1 transition-transform duration-300">
                        Print Now
                        <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>
            </div>

            <!-- Buy JAMB e-pin -->
            <div class="group bg-white rounded-2xl shadow-md hover:shadow-xl transition-all duration-300 overflow-hidden transform hover:-translate-y-2">
                <div class="relative h-56 overflow-hidden">
                    <img src="https://cdn.businessday.ng/wp-content/uploads/2025/05/JAMB-invites-Alex-Onyia-to-2025-UTME-review-panel.png" 
                         alt="JAMB Exam" 
                         class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
                    <div class="absolute bottom-4 left-4 right-4">
                        <span class="inline-block px-3 py-1 bg-green-500 text-white text-sm font-semibold rounded-full">
                            🎓 JAMB E-PIN
                        </span>
                    </div>
                </div>
                <div class="p-6">
                    <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-3">Buy JAMB e-pin</h3>
                    <p class="text-sm md:text-base text-gray-600 mb-4 leading-relaxed">
                        Buy JAMB e-pin for UTME & Direct Entry (DE)
                    </p>
                    <a href="#" class="inline-flex items-center text-blue-600 hover:text-blue-700 font-semibold text-sm md:text-base group-hover:translate-x-1 transition-transform duration-300">
                        Print Now
                        <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

    <!-- Metrics Section -->
    <div class="relative z-10 max-w-6xl mx-auto px-4 pb-20">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 md:gap-8">
            <!-- Metric 1 -->
            <div class="bg-white/80 backdrop-blur-sm rounded-2xl p-8 shadow-lg hover:shadow-xl transform hover:scale-105 transition-all duration-300 border border-green-100">
                <div class="text-5xl mb-4 text-center">👥</div>
                <div class="text-4xl font-bold text-green-600 text-center mb-2">50K+</div>
                <div class="text-gray-600 text-center font-medium">Happy Users</div>
            </div>

            <!-- Metric 2 -->
            <div class="bg-white/80 backdrop-blur-sm rounded-2xl p-8 shadow-lg hover:shadow-xl transform hover:scale-105 transition-all duration-300 border border-green-100">
                <div class="text-5xl mb-4 text-center">💳</div>
                <div class="text-4xl font-bold text-green-600 text-center mb-2">₦1M+</div>
                <div class="text-gray-600 text-center font-medium">Transactions</div>
            </div>

            <!-- Metric 3 -->
            <div class="bg-white/80 backdrop-blur-sm rounded-2xl p-8 shadow-lg hover:shadow-xl transform hover:scale-105 transition-all duration-300 border border-green-100">
                <div class="text-5xl mb-4 text-center">⚡</div>
                <div class="text-4xl font-bold text-green-600 text-center mb-2">99.9%</div>
                <div class="text-gray-600 text-center font-medium">Uptime</div>
            </div>
        </div>
    </div>
</div>

<!-- Why ReUp Section -->
<section class="relative py-16 md:py-24 bg-white overflow-hidden">
    <div class="absolute inset-0 bg-gradient-to-b from-green-50/30 to-transparent pointer-events-none"></div>
    
    <div class="relative z-10 max-w-6xl mx-auto px-4">
        <div class="text-center mb-12 md:mb-16">
            <h2 class="text-3xl md:text-5xl lg:text-6xl font-bold text-gray-900 mb-4">
                Why <span class="text-green-600">ReUp</span>?
            </h2>
            <p class="text-xl md:text-3xl text-gray-600 font-semibold">
                Fast. Simple. Reliable.
            </p>
        </div>

        <div class="max-w-3xl mx-auto text-center">
            <p class="text-lg md:text-2xl text-gray-700 leading-relaxed">
                No long processes. No stress. Just instant bill payments and wallet funding — whenever you need it. ⚡
            </p>
        </div>
    </div>
</section>

<!-- What You Can Do Section -->
<section class="relative py-16 md:py-24 bg-gradient-to-br from-green-50 to-emerald-50 overflow-hidden">
    <div class="absolute top-10 right-10 w-64 h-64 bg-green-200/20 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute bottom-10 left-10 w-80 h-80 bg-emerald-200/20 rounded-full blur-3xl pointer-events-none"></div>
    
    <div class="relative z-10 max-w-6xl mx-auto px-4">
        <div class="text-center mb-12 md:mb-16">
            <h2 class="text-3xl md:text-5xl font-bold text-gray-900 mb-4">
                What You Can Do
            </h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8">
            <!-- Feature 1 -->
            <div class="group bg-white/90 backdrop-blur-sm rounded-2xl p-6 md:p-8 shadow-md hover:shadow-xl transform hover:-translate-y-2 transition-all duration-300 border border-green-100/60">
                <div class="text-5xl mb-4 group-hover:scale-110 transition-transform duration-300">📱</div>
                <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-2">Buy Airtime instantly</h3>
                <p class="text-gray-600">Top up your phone in seconds across all networks</p>
            </div>

            <!-- Feature 2 -->
            <div class="group bg-white/90 backdrop-blur-sm rounded-2xl p-6 md:p-8 shadow-md hover:shadow-xl transform hover:-translate-y-2 transition-all duration-300 border border-green-100/60">
                <div class="text-5xl mb-4 group-hover:scale-110 transition-transform duration-300">📶</div>
                <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-2">Purchase Data on all networks</h3>
                <p class="text-gray-600">Stay connected with affordable data bundles</p>
            </div>

            <!-- Feature 3 -->
            <div class="group bg-white/90 backdrop-blur-sm rounded-2xl p-6 md:p-8 shadow-md hover:shadow-xl transform hover:-translate-y-2 transition-all duration-300 border border-green-100/60">
                <div class="text-5xl mb-4 group-hover:scale-110 transition-transform duration-300">💡</div>
                <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-2">Pay Electricity bills easily</h3>
                <p class="text-gray-600">Never worry about power bills again</p>
            </div>

            <!-- Feature 4 -->
            <div class="group bg-white/90 backdrop-blur-sm rounded-2xl p-6 md:p-8 shadow-md hover:shadow-xl transform hover:-translate-y-2 transition-all duration-300 border border-green-100/60">
                <div class="text-5xl mb-4 group-hover:scale-110 transition-transform duration-300">📺</div>
                <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-2">Renew Cable TV subscriptions</h3>
                <p class="text-gray-600">DSTV, GOtv, Startimes - all in one place</p>
            </div>

            <!-- Feature 5 -->
            <div class="group bg-white/90 backdrop-blur-sm rounded-2xl p-6 md:p-8 shadow-md hover:shadow-xl transform hover:-translate-y-2 transition-all duration-300 border border-green-100/60">
                <div class="text-5xl mb-4 group-hover:scale-110 transition-transform duration-300">🔒</div>
                <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-2">Fund your wallet securely</h3>
                <p class="text-gray-600">Safe, encrypted transactions you can trust</p>
            </div>

            <!-- Feature 6 - Empty for symmetry or add more -->
            <div class="hidden lg:flex items-center justify-center bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl p-6 md:p-8 shadow-lg">
                <div class="text-center text-white">
                    <div class="text-5xl mb-3">✨</div>
                    <p class="text-xl font-bold">And much more coming soon!</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- How It Works Section -->
<section class="relative py-16 md:py-24 bg-white overflow-hidden">
    <div class="max-w-6xl mx-auto px-4">
        <div class="text-center mb-12 md:mb-16">
            <h2 class="text-3xl md:text-5xl font-bold text-gray-900 mb-4">
                How It Works
            </h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 md:gap-12 mb-12">
            <!-- Step 1 -->
            <div class="text-center group">
                <div class="relative inline-flex items-center justify-center w-20 h-20 md:w-24 md:h-24 bg-gradient-to-br from-green-400 to-green-600 rounded-full shadow-lg mb-6 group-hover:scale-110 transition-transform duration-300">
                    <span class="text-3xl md:text-4xl font-bold text-white">1</span>
                </div>
                <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-3">Create an account in seconds</h3>
                <p class="text-gray-600 text-base md:text-lg">Quick signup with just your phone number and email</p>
            </div>

            <!-- Step 2 -->
            <div class="text-center group">
                <div class="relative inline-flex items-center justify-center w-20 h-20 md:w-24 md:h-24 bg-gradient-to-br from-green-500 to-emerald-600 rounded-full shadow-lg mb-6 group-hover:scale-110 transition-transform duration-300">
                    <span class="text-3xl md:text-4xl font-bold text-white">2</span>
                </div>
                <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-3">Fund your wallet</h3>
                <p class="text-gray-600 text-base md:text-lg">Multiple secure payment options available</p>
            </div>

            <!-- Step 3 -->
            <div class="text-center group">
                <div class="relative inline-flex items-center justify-center w-20 h-20 md:w-24 md:h-24 bg-gradient-to-br from-emerald-500 to-green-700 rounded-full shadow-lg mb-6 group-hover:scale-110 transition-transform duration-300">
                    <span class="text-3xl md:text-4xl font-bold text-white">3</span>
                </div>
                <h3 class="text-xl md:text-2xl font-bold text-gray-900 mb-3">Pay bills instantly</h3>
                <p class="text-gray-600 text-base md:text-lg">Enjoy lightning-fast transactions 24/7</p>
            </div>
        </div>

        <div class="text-center">
            <p class="text-lg md:text-2xl text-gray-700 font-semibold">
                No delays. No hidden steps. 🎯
            </p>
        </div>
    </div>
</section>

<!-- Built for Everyday Nigerians Section -->
<section class="relative py-16 md:py-24 bg-gradient-to-br from-gray-50 to-green-50 overflow-hidden">
    <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCA2MCA2MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZyBmaWxsPSJub25lIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiPjxnIGZpbGw9IiMyMmM1NWUiIGZpbGwtb3BhY2l0eT0iMC4wNSI+PHBhdGggZD0iTTM2IDE2YzAtMi4yMSAxLjc5LTQgNC00czQgMS43OSA0IDQtMS43OSA0LTQgNC00LTEuNzktNC00em0wIDI0YzAtMi4yMSAxLjc5LTQgNC00czQgMS43OSA0IDQtMS43OSA0LTQgNC00LTEuNzktNC00ek0xMiAxNmMwLTIuMjEgMS43OS00IDQtNHM0IDEuNzkgNCA0LTEuNzkgNC00IDQtNC0xLjc5LTQtNHptMCAyNGMwLTIuMjEgMS43OS00IDQtNHM0IDEuNzkgNCA0LTEuNzkgNC00IDQtNC0xLjc5LTQtNHoiLz48L2c+PC9nPjwvc3ZnPg==')] opacity-40 pointer-events-none"></div>
    
    <div class="relative z-10 max-w-6xl mx-auto px-4">
        <div class="text-center mb-12 md:mb-16">
            <h2 class="text-3xl md:text-5xl font-bold text-gray-900 mb-4">
                Built for Everyday Nigerians 🇳🇬
            </h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 md:gap-8 max-w-4xl mx-auto">
            <!-- Benefit 1 -->
            <div class="flex items-start space-x-4 p-6 bg-white/80 backdrop-blur-sm rounded-xl shadow-sm hover:shadow-md transition-shadow duration-300">
                <div class="flex-shrink-0">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <span class="text-2xl">📱</span>
                    </div>
                </div>
                <div>
                    <h3 class="text-lg md:text-xl font-bold text-gray-900 mb-1">Mobile-first experience</h3>
                    <p class="text-gray-600">Optimized for your smartphone, anywhere you go</p>
                </div>
            </div>

            <!-- Benefit 2 -->
            <div class="flex items-start space-x-4 p-6 bg-white/80 backdrop-blur-sm rounded-xl shadow-sm hover:shadow-md transition-shadow duration-300">
                <div class="flex-shrink-0">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <span class="text-2xl">🔐</span>
                    </div>
                </div>
                <div>
                    <h3 class="text-lg md:text-xl font-bold text-gray-900 mb-1">Secure transactions</h3>
                    <p class="text-gray-600">Bank-level security protecting every payment</p>
                </div>
            </div>

            <!-- Benefit 3 -->
            <div class="flex items-start space-x-4 p-6 bg-white/80 backdrop-blur-sm rounded-xl shadow-sm hover:shadow-md transition-shadow duration-300">
                <div class="flex-shrink-0">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <span class="text-2xl">📊</span>
                    </div>
                </div>
                <div>
                    <h3 class="text-lg md:text-xl font-bold text-gray-900 mb-1">Clear transaction history</h3>
                    <p class="text-gray-600">Track every naira spent with detailed records</p>
                </div>
            </div>

            <!-- Benefit 4 -->
            <div class="flex items-start space-x-4 p-6 bg-white/80 backdrop-blur-sm rounded-xl shadow-sm hover:shadow-md transition-shadow duration-300">
                <div class="flex-shrink-0">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <span class="text-2xl">⚡</span>
                    </div>
                </div>
                <div>
                    <h3 class="text-lg md:text-xl font-bold text-gray-900 mb-1">Reliable service, every time</h3>
                    <p class="text-gray-600">99.9% uptime ensures you're never stuck</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Final CTA Section -->
<section class="relative py-20 md:py-28 bg-gradient-to-br from-green-600 to-green-800 overflow-hidden">
    <!-- Decorative elements -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-0 left-0 w-96 h-96 bg-white/10 rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 right-0 w-[500px] h-[500px] bg-white/10 rounded-full blur-3xl"></div>
    </div>

    <div class="relative z-10 max-w-4xl mx-auto px-4 text-center">
        <h2 class="text-3xl md:text-5xl lg:text-6xl font-bold text-white mb-6">
            Get started today. 🚀
        </h2>
        <p class="text-lg md:text-2xl text-green-50 mb-10 md:mb-12 max-w-2xl mx-auto">
            Join thousands of users paying bills faster with <span class="font-bold">ReUp</span>.
        </p>

        <div class="flex flex-col sm:flex-row items-center justify-center gap-4 md:gap-6">
            <a href="#" 
               class="group relative inline-flex items-center justify-center bg-white text-green-600 hover:text-green-700 font-bold px-10 py-4 md:px-12 md:py-5 rounded-full shadow-xl hover:shadow-2xl transform hover:scale-105 transition-all duration-300 text-base md:text-lg w-full sm:w-auto overflow-hidden">
                <span class="relative z-10">✨ Create Account</span>
                <div class="absolute inset-0 bg-gradient-to-r from-green-50 to-emerald-50 opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>
            </a>
            <a href="#" 
               class="inline-flex items-center justify-center ring-2 ring-white text-white hover:bg-white/10 font-semibold px-10 py-4 md:px-12 md:py-5 rounded-full shadow-lg hover:shadow-xl transform hover:scale-105 transition-all duration-300 text-base md:text-lg w-full sm:w-auto">
                Login
            </a>
        </div>

        <p class="mt-8 text-green-100 text-sm md:text-base">
            No credit card required • Free to sign up • Start in seconds
        </p>
    </div>
</section>

<style>
    @keyframes pulse {
        0%, 100% {
            opacity: 0.3;
        }
        50% {
            opacity: 0.5;
        }
    }
</style>
@endsection