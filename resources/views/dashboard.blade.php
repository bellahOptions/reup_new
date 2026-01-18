@extends('layouts.app')
@section('title', '$user = auth()->user() Dashboard')
@section('content')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-6 md:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <!-- Welcome Header -->
            <div class="mb-8">
                <h1 class="text-2xl md:text-3xl font-bold text-gray-900">
                    Welcome back, {{ auth()->user()->name }}! 👋
                </h1>
                <p class="text-gray-600 mt-1">Here's what's happening with your account today.</p>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    @php
        // Calculate statistics from database
        $user = auth()->user();
        $totalBalance = $user->wallet_balance ?? 0;
        
        // Get transactions for current month
        $currentMonthTransactions = $user->transactions()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->get();
        
        // Calculate total spent this month (debits only)
        $totalSpentThisMonth = $currentMonthTransactions
            ->where('type', 'debit')
            ->where('status', 'success')
            ->sum('amount');
        
        // Calculate total funding this month (credits only)
        $totalFundedThisMonth = $currentMonthTransactions
            ->where('type', 'credit')
            ->where('status', 'success')
            ->sum('amount');
        
        // Count transactions by status
        $successfulCount = $user->transactions()->successful()->count();
        $pendingCount = $user->transactions()->pending()->count();
        $totalCount = $user->transactions()->count();
        
        // Success rate
        $successRate = $totalCount > 0 ? round(($successfulCount / $totalCount) * 100) : 0;
    @endphp
                <!-- Total Balance -->
                <div class="group bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-lg hover:shadow-xl transition-all duration-300 p-6 text-white transform hover:-translate-y-1">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <p class="text-green-100 text-sm font-medium mb-1">Total Balance</p>
                            <h3 class="text-3xl md:text-4xl font-bold">₦{{ number_format($totalBalance, 2) }}</h3>
                        </div>
                        <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                            <span class="text-2xl">💰</span>
                        </div>
                    </div>
                    <a href="{{ route('wallet.index') }}" class="inline-flex items-center text-sm font-semibold text-white hover:text-green-100 transition-colors duration-200">
                        Fund Wallet
                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>

                <!-- Total Transactions -->
                <div class="group bg-white rounded-2xl shadow-md hover:shadow-lg transition-all duration-300 p-6 border border-gray-200/60 transform hover:-translate-y-1">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <p class="text-gray-600 text-sm font-medium mb-1">Total Transactions</p>
                            <h3 class="text-3xl md:text-4xl font-bold text-gray-900">{{ $totalCount }}</h3>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                            <span class="text-2xl">📊</span>
                        </div>
                    </div>
                    <p class="text-sm text-gray-500">₦{{ number_format($totalSpentThisMonth, 2) }} transactions this month</p>
                </div>

                <!-- Membership Type -->
                <div class="group bg-white rounded-2xl shadow-md hover:shadow-lg transition-all duration-300 p-6 border border-gray-200/60 transform hover:-translate-y-1">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <p class="text-gray-600 text-sm font-medium mb-1">Membership Type</p>
                            <h3 class="text-2xl md:text-3xl font-bold text-green-600">Free Tier</h3>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                            <span class="text-2xl">⭐</span>
                        </div>
                    </div>
                    <a href="#" class="inline-flex items-center text-sm font-semibold text-green-600 hover:text-green-700 transition-colors duration-200">
                        Upgrade Plan
                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>
            </div>

               <!-- Announcements Bar with Marquee -->
            @if(!empty($promotionsNotifications))
            <div class="bg-gradient-to-r from-green-100 to-emerald-100 border border-green-200 rounded-2xl py-3 px-4 mb-8 overflow-hidden">
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

            <!-- Quick Actions -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl md:text-2xl font-bold text-gray-900">Quick Actions</h2>
                    <span class="text-sm text-gray-500">Fast access to services</span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <!-- Buy Airtime -->
                    <a href="{{ route('airtime-data.index') }}" class="group bg-white hover:bg-green-50 rounded-2xl shadow-md hover:shadow-lg transition-all duration-300 p-6 border border-gray-200/60 transform hover:-translate-y-1">
                        <div class="w-14 h-14 bg-gradient-to-br from-green-100 to-green-200 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300">
                            <span class="text-3xl">📱</span>
                        </div>
                        <h3 class="font-bold text-gray-900 mb-1">Buy Airtime</h3>
                        <p class="text-xs text-gray-600">Instant recharge</p>
                    </a>

                    <!-- Buy Data -->
                    <a href="{{ route('airtime-data.index') }}" class="group bg-white hover:bg-green-50 rounded-2xl shadow-md hover:shadow-lg transition-all duration-300 p-6 border border-gray-200/60 transform hover:-translate-y-1">
                        <div class="w-14 h-14 bg-gradient-to-br from-green-100 to-green-200 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300">
                            <span class="text-3xl">📶</span>
                        </div>
                        <h3 class="font-bold text-gray-900 mb-1">Buy Data</h3>
                        <p class="text-xs text-gray-600">All networks</p>
                    </a>

                    <!-- Cable TV -->
                    <a href="{{ route('cable-tv.index') }}" class="group bg-white hover:bg-purple-50 rounded-2xl shadow-md hover:shadow-lg transition-all duration-300 p-6 border border-gray-200/60 transform hover:-translate-y-1">
                        <div class="w-14 h-14 bg-gradient-to-br from-purple-100 to-purple-200 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300">
                            <span class="text-3xl">📺</span>
                        </div>
                        <h3 class="font-bold text-gray-900 mb-1">Cable TV</h3>
                        <p class="text-xs text-gray-600">Pay subscriptions</p>
                    </a>

                    <!-- Fund Wallet -->
                    <a href="{{ route('wallet.index') }}" class="group bg-white hover:bg-yellow-50 rounded-2xl shadow-md hover:shadow-lg transition-all duration-300 p-6 border border-gray-200/60 transform hover:-translate-y-1">
                        <div class="w-14 h-14 bg-gradient-to-br from-yellow-100 to-yellow-200 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300">
                            <span class="text-3xl">💳</span>
                        </div>
                        <h3 class="font-bold text-gray-900 mb-1">Fund Wallet</h3>
                        <p class="text-xs text-gray-600">Add money</p>
                    </a>
                </div>
            </div>

            <!-- Recent Transactions -->
             
            <div class="bg-white rounded-2xl shadow-md border border-gray-200/60 overflow-hidden">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-xl md:text-2xl font-bold text-gray-900">Recent Transactions</h2>
                            <p class="text-sm text-gray-500 mt-1">Your latest activity</p>
                        </div>
                        <a href="#" class="inline-flex items-center px-4 py-2 bg-green-50 hover:bg-green-100 border border-green-200 text-green-600 hover:text-green-700 font-semibold text-sm rounded-xl transition-all duration-200">
                            View All
                            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </a>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <!-- Add filters above the table -->
<div class="mb-4 flex flex-wrap gap-2">
    <select id="typeFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
        <option value="">All Types</option>
        <option value="credit">Credit</option>
        <option value="debit">Debit</option>
    </select>
    
    <select id="statusFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
        <option value="">All Status</option>
        <option value="success">Success</option>
        <option value="pending">Pending</option>
        <option value="failed">Failed</option>
    </select>
    
    <input type="date" id="dateFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
    
    <button id="resetFilters" class="px-3 py-2 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">
        Reset
    </button>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const typeFilter = document.getElementById('typeFilter');
    const statusFilter = document.getElementById('statusFilter');
    const dateFilter = document.getElementById('dateFilter');
    const resetFilters = document.getElementById('resetFilters');
    
    // Load filters from URL
    const urlParams = new URLSearchParams(window.location.search);
    typeFilter.value = urlParams.get('type') || '';
    statusFilter.value = urlParams.get('status') || '';
    dateFilter.value = urlParams.get('date') || '';
    
    // Apply filters on change
    [typeFilter, statusFilter, dateFilter].forEach(filter => {
        filter.addEventListener('change', function() {
            applyFilters();
        });
    });
    
    // Reset filters
    resetFilters.addEventListener('click', function() {
        typeFilter.value = '';
        statusFilter.value = '';
        dateFilter.value = '';
        applyFilters();
    });
    
    function applyFilters() {
        const params = new URLSearchParams();
        
        if (typeFilter.value) params.set('type', typeFilter.value);
        if (statusFilter.value) params.set('status', statusFilter.value);
        if (dateFilter.value) params.set('date', dateFilter.value);
        
        window.location.href = window.location.pathname + '?' + params.toString();
    }
});
</script>
                  <table class="min-w-full divide-y divide-gray-200">
    <thead class="bg-gray-50">
        <tr>
            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Date</th>
            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Type</th>
            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Description</th>
            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Transaction ID</th>
            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Amount</th>
            <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
        </tr>
    </thead>
    <tbody class="bg-white divide-y divide-gray-200">
        

        @if($recentTransactions->isEmpty())
            <!-- Empty State -->
            <tr>
                <td colspan="6" class="px-6 py-12 text-center">
                    <div class="flex flex-col items-center justify-center">
                        <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                            <span class="text-4xl">📭</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-1">No transactions yet</h3>
                        <p class="text-gray-500 text-sm mb-4">Your transaction history will appear here</p>
                        <a href="{{ route('airtime-data.index') }}" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold text-sm rounded-lg transition-all duration-200">
                            Make your first transaction
                        </a>
                    </div>
                </td>
            </tr>
        @else
            @foreach($recentTransactions as $transaction)
            <tr class="hover:bg-gray-50 transition-colors duration-200">
                <!-- Date -->
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    <div class="flex flex-col">
                        <span class="font-medium">{{ $transaction->created_at->format('M d, Y') }}</span>
                        <span class="text-xs text-gray-500">{{ $transaction->created_at->format('h:i A') }}</span>
                    </div>
                </td>
                
                <!-- Type -->
                <td class="px-6 py-4 whitespace-nowrap">
                    @php
                        $typeColors = [
                            'credit' => 'bg-green-100 text-green-800',
                            'debit' => 'bg-red-100 text-red-800'
                        ];
                        $typeIcons = [
                            'credit' => '⬇️',
                            'debit' => '⬆️'
                        ];
                    @endphp
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $typeColors[$transaction->type] ?? 'bg-gray-100 text-gray-800' }}">
                        {{ $typeIcons[$transaction->type] ?? '' }} {{ ucfirst($transaction->type) }}
                    </span>
                </td>
                
                <!-- Description -->
                <td class="px-6 py-4 text-sm text-gray-900">
                    <div class="flex items-center">
                        <span class="mr-2">
                            @switch($transaction->service_type)
                                @case('airtime')
                                    📱
                                    @break
                                @case('data')
                                    📶
                                    @break
                                @case('cable-tv')
                                    📺
                                    @break
                                @case('jamb')
                                    🎓
                                    @break
                                @case('funding')
                                    💳
                                    @break
                                @default
                                    💸
                            @endswitch
                        </span>
                        <div>
                            <span class="font-medium">{{ $transaction->description }}</span>
                            @if($transaction->recipient)
                                <p class="text-xs text-gray-500 mt-1">
                                    To: {{ $transaction->recipient }}
                                    @if($transaction->provider)
                                        • {{ $transaction->provider }}
                                    @endif
                                </p>
                            @endif
                        </div>
                    </div>
                </td>
                
                <!-- Transaction ID -->
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm font-mono text-gray-500">
                        {{ $transaction->reference }}
                    </div>
                    @if($transaction->external_reference)
                        <div class="text-xs text-gray-400 mt-1">
                            Ext: {{ substr($transaction->external_reference, 0, 10) }}...
                        </div>
                    @endif
                </td>
                
                <!-- Amount -->
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm font-semibold {{ $transaction->type === 'credit' ? 'text-green-600' : 'text-red-600' }}">
                        {{ $transaction->type === 'credit' ? '+' : '-' }}₦{{ number_format($transaction->amount, 2) }}
                    </div>
                    <div class="text-xs text-gray-500 mt-1">
                        Bal: ₦{{ number_format($transaction->balance_after, 2) }}
                    </div>
                </td>
                
                <!-- Status -->
                <td class="px-6 py-4 whitespace-nowrap">
                    @php
                        $statusColors = [
                            'success' => 'bg-green-100 text-green-800',
                            'pending' => 'bg-yellow-100 text-yellow-800',
                            'failed' => 'bg-red-100 text-red-800',
                            'cancelled' => 'bg-gray-100 text-gray-800'
                        ];
                        $statusIcons = [
                            'success' => '✓',
                            'pending' => '⏳',
                            'failed' => '✗',
                            'cancelled' => '⊘'
                        ];
                    @endphp
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $statusColors[$transaction->status] ?? 'bg-gray-100 text-gray-800' }}">
                        {{ $statusIcons[$transaction->status] ?? '' }} {{ ucfirst($transaction->status) }}
                    </span>
                </td>
            </tr>
            @endforeach
        @endif
    </tbody>
</table>

                </div>
            </div>

        </div>
    </div>
</main>

@endsection