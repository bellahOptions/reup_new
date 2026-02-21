@extends('layouts.app')
@section('title', 'Buy Cheap Airtime & Data on ReUp - Instant Recharge for All Networks')
@section('content')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8 md:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Page Header -->
            <div class="mb-8 md:mb-12">
                <div class="flex items-center space-x-3 mb-4">
                    <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg">
                        <span class="text-2xl">📱</span>
                    </div>
                    <div>
                        <h1 class="text-3xl md:text-4xl font-bold text-gray-900">Buy Airtime & Data</h1>
                        <p class="text-gray-600 text-sm md:text-base mt-1">Quick recharge for all networks</p>
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

             <!-- Announcements Bar with Marquee -->
            @if(!empty($promotionsNotifications))
            <div class="bg-gradient-to-r from-green-100 to-green-100 border border-green-200 rounded-2xl py-3 px-4 mb-8 overflow-hidden">
                <div class="flex items-center">
                    <span class="text-green-600 font-bold mr-3 flex-shrink-0 text-sm md:text-base">📢 Announcements:</span>
                    <div class="marquee-container overflow-hidden flex-1">
                        <x-marquee 
                            :items="$promotionsNotifications"
                            speed="35"
                            direction="left"
                            pauseOnHover="true"
                            containerClass="w-full"
                            innerClass="w-full"
                            textColor="text-gray-700"
                            badgeColor="bg-green-100 text-green-800 border border-green-200"
                            compact="true"
                        />
                    </div>
                </div>
            </div>
            @endif

                <!-- Marquee Announcement Bar -->
    <div class="mb-8 rounded-xl bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-100 shadow-sm overflow-hidden">
@php
    // Prepare announcement items
    $announcementItems = [];
    
    if(!empty($announcements) && $announcements->count() > 0) {
        // Transform database announcements to marquee format
        foreach($announcements as $announcement) {
            $item = [];
            
            // Map database fields to marquee item fields
            if(!empty($announcement->badge)) {
                $item['badge'] = $announcement->badge;
                $item['badge_color'] = $announcement->badge_color ?? $announcement->color ?? 'bg-blue-100 text-blue-800';
            }
            
            if(!empty($announcement->icon)) {
                $item['icon'] = $announcement->icon;
            }
            
            // Use title or content field
            $item['title'] = $announcement->title ?? $announcement->content ?? '';
            
            if(!empty($announcement->text_color)) {
                $item['textColor'] = $announcement->text_color;
            }
            
            // Only add if we have content
            if(!empty($item['title']) || !empty($item['badge']) || !empty($item['icon'])) {
                $announcementItems[] = $item;
            }
        }
    } else {
        // Fallback to default announcements
        $announcementItems = [
            ['badge' => 'NEW', 'title' => 'Welcome back! Check out the new dashboard features', 'badgeColor' => 'bg-blue-100 text-blue-800'],
            ['icon' => '🔥', 'title' => 'Hot deal: 30% off premium subscription until Friday'],
            ['badge' => 'UPDATE', 'title' => 'Security patch installed', 'badgeColor' => 'bg-green-100 text-green-800'],
            ['icon' => '📊', 'title' => 'Monthly reports now available in analytics'],
            ['badge' => 'TIP', 'title' => 'Use dark mode for better battery life on OLED screens', 'badgeColor' => 'bg-purple-100 text-purple-800'],
        ];
    }
@endphp

@if(!empty($announcementItems))
    <x-marquee 
        :items="$announcementItems"
        speed="40"
        pauseOnHover="true"
        containerClass="py-2"
    />
@endif

            </div>

            

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">
                <!-- Main Form Section -->
                <div class="lg:col-span-2">
                    <!-- Service Type Toggle -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200/60 p-2 mb-6">
                        <div class="grid grid-cols-2 gap-2">
                            <button id="airtimeBtn" class="focus:bg-green-600 active:bg-green-600 focus:text-white focus:font-bold service-btn active py-3 px-6 rounded-xl font-semibold text-sm md:text-base transition-all duration-300 flex items-center justify-center space-x-2">
                                <span>📞</span>
                                <span>Airtime</span>
                            </button>
                            <button id="dataBtn" class="focus:bg-green-600 active:bg-green-600 focus:text-white focus:font-bold service-btn py-3 px-6 rounded-xl font-semibold text-sm md:text-base transition-all duration-300 flex items-center justify-center space-x-2">
                                <span>📶</span>
                                <span>Data</span>
                            </button>
                        </div>
                    </div>

                    <!-- Purchase Form Card -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 overflow-hidden">
                        <div class="p-6 md:p-8">
                            <!-- Airtime Form -->
                            <form id="airtimeForm" action="{{ route('airtime.purchase') }}" method="POST" class="space-y-6">
                                @csrf
                                
                                <!-- Network Selection -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">Select Network</label>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                        <label class="network-card cursor-pointer active:bg-yellow-500 active:text-white">
                                            <input type="radio" name="network" value="01" class="hidden network-radio" required>
                                            <div class="network-option p-4 border-2 border-gray-200 rounded-xl hover:border-yellow-400 transition-all duration-300 text-center">
                                                <div class="w-12 h-12 bg-yellow-100 rounded-full mx-auto mb-2 flex items-center justify-center">
                                                    <span class="text-2xl"><img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRfXy7KwjHO5-a673nHKp--FMaWXvR4oJDL0w&s" class="w-6 h-6"/> </span>
                                                </div>
                                                <span class="font-semibold text-gray-900 text-sm">MTN</span>
                                            </div>
                                        </label>
                                        <label class="network-card cursor-pointer active:bg-red-500 active:text-white">
                                            <input type="radio" name="network" value="04" class="hidden network-radio" required>
                                            <div class="network-option p-4 border-2 border-gray-200 rounded-xl hover:border-red-400 transition-all duration-300 text-center">
                                                <div class="w-12 h-12 bg-red-100 rounded-full mx-auto mb-2 flex items-center justify-center">
                                                    <span class="text-2xl"><img src="https://upload.wikimedia.org/wikipedia/commons/3/3a/Airtel_logo-01.png" class="w-6 h-6"/> </span>
                                                </div>
                                                <span class="font-semibold text-gray-900 text-sm">Airtel</span>
                                            </div>
                                        </label>
                                        <label class="network-card cursor-pointer active:bg-green-500 active:text-white">
                                            <input type="radio" name="network" value="02" class="hidden network-radio" required>
                                            <div class="network-option p-4 border-2 border-gray-200 rounded-xl hover:border-green-400 transition-all duration-300 text-center">
                                                <div class="w-12 h-12 bg-green-100 rounded-full mx-auto mb-2 flex items-center justify-center">
                                                    <span class="text-2xl"><img src="https://cdn.businessday.ng/2020/01/Globacom.jpg" class="w-6 h-6"/> </span>
                                                </div>
                                                <span class="font-semibold text-gray-900 text-sm">Glo</span>
                                            </div>
                                        </label>
                                        <label class="network-card cursor-pointer active:bg-lime-500 active:text-white">
                                            <input type="radio" name="network" value="03" class="hidden network-radio" required>
                                            <div class="network-option p-4 border-2 border-gray-200 rounded-xl hover:border-blue-400 transition-all duration-300 text-center">
                                                <div class="w-12 h-12 bg-blue-100 rounded-full mx-auto mb-2 flex items-center justify-center">
                                                    <span class="text-2xl"><img src="https://cdn.punchng.com/wp-content/uploads/2017/07/19170207/9Mobile-Telecom-Logo.jpg" class="w-6 h-6"/> </span>
                                                </div>
                                                <span class="font-semibold text-gray-900 text-sm">9Mobile</span>
                                            </div>
                                        </label>
                                    </div>
                                </div>

                                <!-- Phone Number -->
                                <div>
                                    <label for="phone" class="block text-sm font-semibold text-gray-700 mb-2">Phone Number</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-400">📱</span>
                                        </div>
                                        <input type="tel" id="phone" name="phone" placeholder="08012345678" 
                                               class="block w-full pl-12 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                                               required maxlength="11" pattern="[0-9]{11}">
                                    </div>
                                </div>

                                <!-- Amount -->
                                <div>
                                    <label for="amount" class="block text-sm font-semibold text-gray-700 mb-2">Amount (₦)</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-700 font-semibold">₦</span>
                                        </div>
                                        <input type="number" id="amount" name="amount" placeholder="1000" min="100" max="10000"
                                               class="block w-full pl-12 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                                               required>
                                    </div>
                                    <div class="mt-2 space-y-1">
                                        <p class="text-xs text-gray-500">💡 Minimum: ₦100 • Maximum: ₦10,000</p>
                                        <p class="text-xs text-amber-600 font-medium">⚠️ 2% service fee applies (e.g., ₦1,000 = ₦1,020)</p>
                                    </div>
                                </div>

                                <!-- Quick Amount Buttons -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">Quick Select</label>
                                    <div class="grid grid-cols-3 md:grid-cols-5 gap-2">
                                        <button type="button" class="quick-amount py-2 px-3 border-2 border-gray-200 rounded-lg hover:border-green-500 hover:bg-green-50 transition-all duration-200 text-sm font-semibold" data-amount="100">₦100</button>
                                        <button type="button" class="quick-amount py-2 px-3 border-2 border-gray-200 rounded-lg hover:border-green-500 hover:bg-green-50 transition-all duration-200 text-sm font-semibold" data-amount="200">₦200</button>
                                        <button type="button" class="quick-amount py-2 px-3 border-2 border-gray-200 rounded-lg hover:border-green-500 hover:bg-green-50 transition-all duration-200 text-sm font-semibold" data-amount="500">₦500</button>
                                        <button type="button" class="quick-amount py-2 px-3 border-2 border-gray-200 rounded-lg hover:border-green-500 hover:bg-green-50 transition-all duration-200 text-sm font-semibold" data-amount="1000">₦1K</button>
                                        <button type="button" class="quick-amount py-2 px-3 border-2 border-gray-200 rounded-lg hover:border-green-500 hover:bg-green-50 transition-all duration-200 text-sm font-semibold" data-amount="2000">₦2K</button>
                                    </div>
                                </div>

                                <!-- Submit Button -->
                                <button type="submit" class="w-full bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-bold py-4 px-6 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-200 flex items-center justify-center space-x-2">
                                    <span>Buy Airtime Now</span>
                                    <span>🚀</span>
                                </button>
                            </form>

                            <!-- Data Form (Hidden by default) -->
                            <form id="dataForm" action="{{ route('data.purchase') }}" method="POST" class="space-y-6 hidden">
                                @csrf
                                
                                <!-- Network Selection -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3">Select Network</label>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                        <label class="network-card cursor-pointer">
                                            <input type="radio" name="data_network" value="01" class="hidden network-radio-data" required>
                                            <div class="network-option p-4 border-2 border-gray-200 rounded-xl hover:border-yellow-400 transition-all duration-300 text-center">
                                                <div class="w-12 h-12 bg-yellow-100 rounded-full mx-auto mb-2 flex items-center justify-center">
                                                    <span class="text-2xl"><img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRfXy7KwjHO5-a673nHKp--FMaWXvR4oJDL0w&s" class="w-6 h-6"/> </span>
                                                </div>
                                                <span class="font-semibold text-gray-900 text-sm">MTN</span>
                                            </div>
                                        </label>
                                        <label class="network-card cursor-pointer">
                                            <input type="radio" name="data_network" value="04" class="hidden network-radio-data" required>
                                            <div class="network-option p-4 border-2 border-gray-200 rounded-xl hover:border-red-400 transition-all duration-300 text-center">
                                                <div class="w-12 h-12 bg-red-100 rounded-full mx-auto mb-2 flex items-center justify-center">
                                                    <span class="text-2xl"><img src="https://upload.wikimedia.org/wikipedia/commons/3/3a/Airtel_logo-01.png" class="w-6 h-6"/> </span>
                                                </div>
                                                <span class="font-semibold text-gray-900 text-sm">Airtel</span>
                                            </div>
                                        </label>
                                        <label class="network-card cursor-pointer">
                                            <input type="radio" name="data_network" value="04" class="hidden network-radio-data" required>
                                            <div class="network-option p-4 border-2 border-gray-200 rounded-xl hover:border-green-400 transition-all duration-300 text-center">
                                                <div class="w-12 h-12 bg-green-100 rounded-full mx-auto mb-2 flex items-center justify-center">
                                                    <span class="text-2xl"><img src="https://cdn.businessday.ng/2020/01/Globacom.jpg" class="w-6 h-6"/> </span>
                                                </div>
                                                <span class="font-semibold text-gray-900 text-sm">Glo</span>
                                            </div>
                                        </label>
                                        <label class="network-card cursor-pointer">
                                            <input type="radio" name="data_network" value="03" class="hidden network-radio-data" required>
                                            <div class="network-option p-4 border-2 border-gray-200 rounded-xl hover:border-blue-400 transition-all duration-300 text-center">
                                                <div class="w-12 h-12 bg-blue-100 rounded-full mx-auto mb-2 flex items-center justify-center">
                                                    <span class="text-2xl"><img src="https://cdn.punchng.com/wp-content/uploads/2017/07/19170207/9Mobile-Telecom-Logo.jpg" class="w-6 h-6"/> </span>
                                                </div>
                                                <span class="font-semibold text-gray-900 text-sm">9Mobile</span>
                                            </div>
                                        </label>
                                    </div>
                                </div>

                                <!-- Data Plan Selection -->
                               <!-- Data Plan Selection -->
<div>
    <label class="block text-sm font-semibold text-gray-700 mb-3">Select Data Plan</label>
    
    <!-- Data Type Filter -->
    <div class="mb-4">
        <label class="block text-sm font-medium text-gray-600 mb-2">Filter by Type:</label>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="focus:bg-green-100 focus:text-green-700 focus:border focus:border-green-300 data-type-filter px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200 active bg-gray-100 text-gray-700 border border-gray-300 hover:bg-gray-200" data-type="all">
                All Plans
            </button>
            <button type="button" class="focus:bg-green-100 focus:text-green-700 focus:border focus:border-green-300 data-type-filter px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200 bg-gray-100 text-gray-700 border border-gray-300 hover:bg-gray-200" data-type="SME">
                SME Plans
            </button>
            <button type="button" class="focus:bg-green-100 focus:text-green-700 focus:border focus:border-green-300 data-type-filter px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200 bg-gray-100 text-gray-700 border border-gray-300 hover:bg-gray-200" data-type="Awoof Data">
                Awoof Data
            </button>
            <button type="button" class="focus:bg-green-100 focus:text-green-700 focus:border focus:border-green-300 data-type-filter px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200 bg-gray-100 text-gray-700 border border-gray-300 hover:bg-gray-200" data-type="Direct Data">
                Direct Data
            </button>
            <button type="button" class="focus:bg-green-100 focus:text-green-700 focus:border focus:border-green-300 data-type-filter px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200 bg-gray-100 text-gray-700 border border-gray-300 hover:bg-gray-200" data-type="Night Plan">
                Night Plans
            </button>
        </div>
    </div>
    
    <!-- Data Plan Select -->
    <div id="dataPlanLoader" class="text-center py-8 hidden">
        <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-green-600"></div>
        <p class="text-gray-600 mt-2 text-sm">Loading plans...</p>
    </div>
    
    <select id="data_plan" name="data_plan" 
            class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
            required disabled>
        <option value="">Select network first</option>
    </select>
    
    <!-- Hidden inputs to store additional plan details -->
    <input type="hidden" id="plan_name" name="plan_name">
    <input type="hidden" id="plan_price" name="plan_price">
    <input type="hidden" id="plan_type" name="plan_type">
    <input type="hidden" id="plan_base_price" name="plan_base_price">
</div>

                                <!-- Phone Number -->
                                <div>
                                    <label for="data_phone" class="block text-sm font-semibold text-gray-700 mb-2">Phone Number</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-400">📱</span>
                                        </div>
                                        <input type="tel" id="data_phone" name="phone" placeholder="08012345678" 
                                               class="block w-full pl-12 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200" 
                                               required maxlength="11" pattern="[0-9]{11}">
                                    </div>
                                </div>

                                <!-- Plan Summary (Optional) -->
<div id="planSummary" class="mt-4 p-4 bg-blue-50 rounded-lg border border-blue-200 hidden">
    <div class="flex justify-between items-center">
        <div>
            <h4 class="font-semibold text-blue-900 text-sm">Selected Plan:</h4>
            <p id="selectedPlanName" class="text-blue-700 text-sm"></p>
            <p id="selectedPlanType" class="text-blue-600 text-xs mt-1"></p>
        </div>
        <div class="text-right">
            <p id="selectedPlanPrice" class="text-lg font-bold text-green-700"></p>
            <p class="text-xs text-gray-500">Total Amount</p>
        </div>
    </div>
</div>

                                <!-- Submit Button -->
                                <button type="submit" class="w-full bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-bold py-4 px-6 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-200 flex items-center justify-center space-x-2">
                                    <span>Buy Data Now</span>
                                    <span>📶</span>
                                </button>
                            </form>

                            <!-- Terms Notice -->
                            <p class="text-xs text-gray-500 text-center mt-6">
                                By proceeding, you agree to our 
                                <a href="#" class="text-green-600 hover:text-green-700 font-medium underline">Terms of Service</a> and 
                                <a href="#" class="text-green-600 hover:text-green-700 font-medium underline">Privacy Policy</a>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Sidebar - Info & Recent -->
                <div class="space-y-6">
                    <!-- Wallet Balance Card -->
                    <div class="bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-lg p-6 text-white">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-sm opacity-90">Wallet Balance</span>
                            <span class="text-2xl">💰</span>
                        </div>
                        <div class="text-3xl font-bold mb-4">₦{{ number_format(auth()->user()->wallet_balance ?? 0, 2) }}</div>
                        <a href="{{ route('wallet.fund') }}" class="block w-full bg-white/20 hover:bg-white/30 text-center py-2 rounded-lg font-semibold text-sm transition-all duration-200">
                            + Fund Wallet
                        </a>
                    </div>

                    <!-- Quick Info -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">💡</span>
                            Quick Tips
                        </h3>
                        <ul class="space-y-3 text-sm text-gray-600">
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">✓</span>
                                <span>Instant delivery within seconds</span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">✓</span>
                                <span>Best rates guaranteed</span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">✓</span>
                                <span>24/7 customer support</span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">✓</span>
                                <span>Secure transactions</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Recent Transactions -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200/60 p-6">
    <h3 class="font-bold text-gray-900 mb-4 flex items-center">
        <span class="text-xl mr-2">📊</span>
        Recent Transactions
    </h3>

    <div class="space-y-3 text-sm">
        @forelse ($recentTransactions as $transaction)
            <div class="flex items-center justify-between py-2 border-b border-gray-100 last:border-b-0">
                <div>
                    <p class="font-medium text-gray-900">
                        {{ ucfirst($transaction->service_type ?? $transaction->type) }}
                    </p>
                    <p class="text-xs text-gray-500">
                        {{ $transaction->recipient ?? 'N/A' }}
                    </p>
                </div>

                <span class="
                    font-semibold
                    {{ $transaction->type === 'credit' ? 'text-green-600' : 'text-red-600' }}
                ">
                    {{ $transaction->type === 'credit' ? '+' : '-' }}
                    ₦{{ number_format($transaction->amount, 2) }}
                </span>
            </div>
        @empty
            <p class="text-xs text-gray-400 text-center py-4">
                No recent transactions
            </p>
        @endforelse
    </div>

    @if ($recentTransactions->count())
        <div class="mt-4 text-center">
            <a href="{{ route('transactions.index') }}"
               class="text-xs font-medium text-green-600 hover:text-green-700">
                View all transactions →
            </a>
        </div>
    @endif
</div>

                </div>
            </div>
        </div>
    </div>
</main>

<style>
/* Service Toggle Buttons */
.service-btn {
    @apply bg-gray-100 text-gray-700;
}
.service-btn.active {
    @apply bg-gradient-to-r from-green-500 to-emerald-600 text-white shadow-md;
}

/* Network Selection */
.network-radio:checked + .network-option {
    @apply border-green-500 bg-green-50 shadow-md;
}
.network-radio-data:checked + .network-option {
    @apply border-blue-500 bg-blue-50 shadow-md;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const airtimeBtn = document.getElementById('airtimeBtn');
    const dataBtn = document.getElementById('dataBtn');
    const airtimeForm = document.getElementById('airtimeForm');
    const dataForm = document.getElementById('dataForm');
    const amountInput = document.getElementById('amount');
    const quickAmounts = document.querySelectorAll('.quick-amount');
    const dataPlanSelect = document.getElementById('data_plan');
    const dataPlanLoader = document.getElementById('dataPlanLoader');
    const dataNetworkRadios = document.querySelectorAll('.network-radio-data');
    const planNameInput = document.getElementById('plan_name');
    const planPriceInput = document.getElementById('plan_price');
    const planTypeInput = document.getElementById('plan_type');
    const dataTypeFilters = document.querySelectorAll('.data-type-filter');
    
    // Get user's wallet balance
    const walletBalance = {{ auth()->user()->wallet_balance ?? 0 }};
    const serviceFeeRate = 0.02; // 2% service fee
    const profitMargin = 0.015; // 1.5% profit margin on data plans
    
    // Store fetched data plans
    let fetchedDataPlans = [];
    let currentFilter = 'all';
    let currentNetworkId = '';

    // Function to calculate price with profit
    function calculateWithProfit(basePrice) {
        return parseFloat((basePrice * (1 + profitMargin)).toFixed(2));
    }

    // Toggle between Airtime and Data
    airtimeBtn.addEventListener('click', () => {
        airtimeBtn.classList.add('active');
        dataBtn.classList.remove('active');
        airtimeForm.classList.remove('hidden');
        dataForm.classList.add('hidden');
    });

    dataBtn.addEventListener('click', () => {
        dataBtn.classList.add('active');
        airtimeBtn.classList.remove('active');
        dataForm.classList.remove('hidden');
        airtimeForm.classList.add('hidden');
    });

    // Quick amount selection with balance check
    quickAmounts.forEach(btn => {
        btn.addEventListener('click', () => {
            const amount = parseFloat(btn.getAttribute('data-amount'));
            amountInput.value = amount;
            checkAirtimeBalance(amount);
        });
    });

    // Check balance on amount input
    amountInput.addEventListener('input', function() {
        const amount = parseFloat(this.value) || 0;
        checkAirtimeBalance(amount);
    });

    // Function to check airtime balance
    function checkAirtimeBalance(amount) {
        const serviceFee = amount * serviceFeeRate;
        const totalAmount = amount + serviceFee;
        
        const submitBtn = airtimeForm.querySelector('button[type="submit"]');
        const balanceWarning = document.getElementById('airtimeBalanceWarning');
        
        if (totalAmount > walletBalance) {
            const shortage = totalAmount - walletBalance;
            
            // Show warning
            if (!balanceWarning) {
                const warning = document.createElement('div');
                warning.id = 'airtimeBalanceWarning';
                warning.className = 'mt-3 p-3 bg-red-50 border border-red-200 rounded-lg';
                warning.innerHTML = `
                    <div class="flex items-start">
                        <span class="text-red-500 text-lg mr-2">⚠️</span>
                        <div>
                            <p class="text-red-800 font-semibold text-sm">Insufficient Balance</p>
                            <p class="text-red-700 text-xs mt-1">
                                Total needed: <strong>₦${totalAmount.toFixed(2)}</strong> 
                                (Amount: ₦${amount.toFixed(2)} + Fee: ₦${serviceFee.toFixed(2)})
                            </p>
                            <p class="text-red-700 text-xs">
                                Your balance: <strong>₦${walletBalance.toFixed(2)}</strong> 
                                • Short by: <strong>₦${shortage.toFixed(2)}</strong>
                            </p>
                            <a href="{{ route('wallet.fund') }}" class="inline-block mt-2 text-xs font-semibold text-red-600 hover:text-red-700 underline">
                                Fund Wallet →
                            </a>
                        </div>
                    </div>
                `;
                amountInput.parentElement.parentElement.appendChild(warning);
            }
            
            // Disable submit button
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            submitBtn.querySelector('span:first-child').textContent = 'Insufficient Balance';
        } else {
            // Remove warning
            if (balanceWarning) {
                balanceWarning.remove();
            }
            
            // Enable submit button
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            submitBtn.querySelector('span:first-child').textContent = 'Buy Airtime Now';
        }
    }

    // Network mapping based on your JSON response
    const networkMapping = {
        '01': 'MTN',
        '02': 'Glo', 
        '03': 'm_9mobile',
        '04': 'Airtel'
    };

    // Function to categorize plan type
    function getPlanType(productName) {
        const name = productName.toLowerCase();
        
        if (name.includes('sme')) return 'SME';
        if (name.includes('awoof')) return 'Awoof Data';
        if (name.includes('night')) return 'Night Plan';
        if (name.includes('direct')) return 'Direct Data';
        if (name.includes('weekend')) return 'Weekend Plan';
        
        return 'Direct Data';
    }

    // Function to filter and display plans
    function displayFilteredPlans() {
        dataPlanSelect.innerHTML = '<option value="">Select a data plan</option>';
        
        let filteredPlans = fetchedDataPlans;
        
        if (currentFilter !== 'all') {
            filteredPlans = fetchedDataPlans.filter(plan => 
                plan.type.toLowerCase().includes(currentFilter.toLowerCase()) ||
                (currentFilter === 'Night Plan' && plan.type === 'Night Plan')
            );
        }
        
        filteredPlans.sort((a, b) => parseFloat(a.price) - parseFloat(b.price));
        
        const groupedPlans = {};
        filteredPlans.forEach(plan => {
            if (!groupedPlans[plan.type]) {
                groupedPlans[plan.type] = [];
            }
            groupedPlans[plan.type].push(plan);
        });
        
        Object.keys(groupedPlans).sort().forEach(type => {
            const optgroup = document.createElement('optgroup');
            optgroup.label = `${type} Plans`;
            
            groupedPlans[type].forEach(plan => {
                const option = document.createElement('option');
                option.value = plan.product_id;
                
                const displayText = `${plan.name} - ₦${plan.price}`;
                option.textContent = displayText;
                
                option.dataset.planName = plan.name;
                option.dataset.planPrice = plan.price;
                option.dataset.planType = plan.type;
                option.dataset.productCode = plan.product_code;
                
                optgroup.appendChild(option);
            });
            
            dataPlanSelect.appendChild(optgroup);
        });
        
        dataPlanSelect.disabled = filteredPlans.length === 0;
        
        if (filteredPlans.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No plans available for this filter';
            dataPlanSelect.appendChild(option);
        }
        
        planNameInput.value = '';
        planPriceInput.value = '';
        planTypeInput.value = '';
    }

    // Data type filter buttons
    dataTypeFilters.forEach(filterBtn => {
        filterBtn.addEventListener('click', function() {
            dataTypeFilters.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            currentFilter = this.dataset.type;
            
            if (fetchedDataPlans.length > 0) {
                displayFilteredPlans();
            }
        });
    });

    // Load data plans when network is selected
    dataNetworkRadios.forEach(radio => {
        radio.addEventListener('change', async function() {
            currentNetworkId = this.value;
            const networkKey = networkMapping[currentNetworkId];
            
            dataPlanSelect.disabled = true;
            dataPlanSelect.innerHTML = '<option value="">Loading plans...</option>';
            dataPlanLoader.classList.remove('hidden');
            planNameInput.value = '';
            planPriceInput.value = '';
            planTypeInput.value = '';
            
            dataTypeFilters.forEach(btn => btn.classList.remove('active'));
            document.querySelector('[data-type="all"]').classList.add('active');
            currentFilter = 'all';

            try {
                const response = await fetch(`https://www.nellobytesystems.com/APIDatabundlePlansV2.asp?UserID=CK101255870`);
                const data = await response.json();
                
                const mobileNetwork = data.MOBILE_NETWORK;
                const networkData = mobileNetwork[networkKey];
                
                if (!networkData || !networkData[0] || !networkData[0].PRODUCT) {
                    dataPlanSelect.innerHTML = '<option value="">No data plans available for this network</option>';
                    return;
                }
                
                const products = networkData[0].PRODUCT;
                fetchedDataPlans = [];

                products.forEach(product => {
                    const planType = getPlanType(product.PRODUCT_NAME);
                    const basePrice = parseFloat(product.PRODUCT_AMOUNT);
                    const sellingPrice = calculateWithProfit(basePrice);
                    
                    fetchedDataPlans.push({
                        product_id: product.PRODUCT_ID,
                        name: product.PRODUCT_NAME,
                        price: sellingPrice.toFixed(2), // Price with 1.5% markup
                        base_price: basePrice.toFixed(2), // Original price from API
                        product_code: product.PRODUCT_CODE,
                        product_sno: product.PRODUCT_SNO,
                        network_id: currentNetworkId,
                        network_name: networkKey.replace('m_', ''),
                        type: planType
                    });
                });

                console.log(`Loaded ${fetchedDataPlans.length} plans for ${networkKey} with 1.5% markup`);
                
                displayFilteredPlans();

            } catch (error) {
                console.error('Error loading data plans:', error);
                dataPlanSelect.innerHTML = '<option value="">Error loading plans. Please try again.</option>';
            } finally {
                dataPlanLoader.classList.add('hidden');
            }
        });
    });

    // When user selects a data plan, update hidden inputs and check balance
    dataPlanSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
    
    if (selectedOption.value) {
        const planName = selectedOption.dataset.planName || '';
        const planPrice = parseFloat(selectedOption.dataset.planPrice || 0); // Selling price
        const planType = selectedOption.dataset.planType || '';
        
        // Find the base price from fetchedDataPlans
        const selectedPlan = fetchedDataPlans.find(p => p.product_id === selectedOption.value);
        const basePrice = selectedPlan ? parseFloat(selectedPlan.base_price) : planPrice / 1.015;
        
        planNameInput.value = planName;
        planPriceInput.value = planPrice; // Selling price (with markup)
        document.getElementById('plan_base_price').value = basePrice; // API price
        planTypeInput.value = planType;
            
            // Show plan summary
            const planSummary = document.getElementById('planSummary');
            const selectedPlanName = document.getElementById('selectedPlanName');
            const selectedPlanType = document.getElementById('selectedPlanType');
            const selectedPlanPrice = document.getElementById('selectedPlanPrice');
            
            if (selectedPlanName && selectedPlanType && selectedPlanPrice && planSummary) {
                selectedPlanName.textContent = planName;
                selectedPlanType.textContent = `Type: ${planType}`;
                selectedPlanPrice.textContent = `₦${planPrice.toFixed(2)}`;
                planSummary.classList.remove('hidden');
            }
            
            // Check balance for data
            checkDataBalance(planPrice);
            
            console.log(`Selected: ${planName} (${planType}) - ₦${planPrice} (includes 1.5% markup)`);
        } else {
            planNameInput.value = '';
            planPriceInput.value = '';
            planTypeInput.value = '';
            
            const planSummary = document.getElementById('planSummary');
            if (planSummary) {
                planSummary.classList.add('hidden');
            }
            
            // Remove warning
            const balanceWarning = document.getElementById('dataBalanceWarning');
            if (balanceWarning) {
                balanceWarning.remove();
            }
        }
    });

    // Function to check data balance
    function checkDataBalance(amount) {
        const serviceFee = amount * serviceFeeRate;
        const totalAmount = amount + serviceFee;
        
        const submitBtn = dataForm.querySelector('button[type="submit"]');
        const balanceWarning = document.getElementById('dataBalanceWarning');
        
        if (totalAmount > walletBalance) {
            const shortage = totalAmount - walletBalance;
            
            // Show warning
            if (!balanceWarning) {
                const warning = document.createElement('div');
                warning.id = 'dataBalanceWarning';
                warning.className = 'mt-3 p-3 bg-red-50 border border-red-200 rounded-lg';
                warning.innerHTML = `
                    <div class="flex items-start">
                        <span class="text-red-500 text-lg mr-2">⚠️</span>
                        <div>
                            <p class="text-red-800 font-semibold text-sm">Insufficient Balance</p>
                            <p class="text-red-700 text-xs mt-1">
                                Total needed: <strong>₦${totalAmount.toFixed(2)}</strong> 
                                (Amount: ₦${amount.toFixed(2)} + Fee: ₦${serviceFee.toFixed(2)})
                            </p>
                            <p class="text-red-700 text-xs">
                                Your balance: <strong>₦${walletBalance.toFixed(2)}</strong> 
                                • Short by: <strong>₦${shortage.toFixed(2)}</strong>
                            </p>
                            <a href="{{ route('wallet.fund') }}" class="inline-block mt-2 text-xs font-semibold text-red-600 hover:text-red-700 underline">
                                Fund Wallet →
                            </a>
                        </div>
                    </div>
                `;
                dataPlanSelect.parentElement.appendChild(warning);
            }
            
            // Disable submit button
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            submitBtn.querySelector('span:first-child').textContent = 'Insufficient Balance';
        } else {
            // Remove warning
            if (balanceWarning) {
                balanceWarning.remove();
            }
            
            // Enable submit button
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            submitBtn.querySelector('span:first-child').textContent = 'Buy Data Now';
        }
    }

    // Data form submission - validation
    dataForm.addEventListener('submit', function(e) {
        if (!dataPlanSelect.value || dataPlanSelect.disabled) {
            e.preventDefault();
            alert('Please select a data plan');
            return;
        }
        
        const phoneInput = document.getElementById('data_phone');
        const phoneRegex = /^[0-9]{11}$/;
        if (!phoneRegex.test(phoneInput.value)) {
            e.preventDefault();
            alert('Please enter a valid 11-digit phone number');
            phoneInput.focus();
            return;
        }
    });

    // Airtime form submission
    airtimeForm.addEventListener('submit', function(e) {
        const amount = parseFloat(amountInput.value) || 0;
        const serviceFee = amount * serviceFeeRate;
        const totalAmount = amount + serviceFee;
        
        if (totalAmount > walletBalance) {
            e.preventDefault();
            alert('Insufficient wallet balance. Please fund your wallet first.');
            return;
        }
    });

    // Add input validation for phone numbers
    const phoneInputs = document.querySelectorAll('input[type="tel"]');
    phoneInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length > 11) {
                this.value = this.value.slice(0, 11);
            }
        });
    });
});
</script>
@endsection