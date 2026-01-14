@extends('admin.layouts.app')

@section('content')
<style>
/* Fixed chart container to prevent infinite expansion */
.chart-container {
    position: relative;
    height: 300px !important; /* Force fixed height */
    max-height: 300px !important;
    width: 100% !important;
    overflow: hidden; /* Prevent overflow */
}

.chart-container canvas {
    max-width: 100% !important;
    max-height: 300px !important;
    height: 300px !important; /* Force canvas height */
}

/* Prevent Chart.js from overriding */
canvas#revenueChart,
canvas#transactionTypesChart {
    height: 300px !important;
    max-height: 300px !important;
}
</style>
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Dashboard Overview</h1>
            <p class="text-gray-600">Welcome back, {{ Auth::user()->name }}! Here's what's happening today.</p>
        </div>
        <div class="flex items-center space-x-3">
            <span class="text-sm text-gray-600">{{ now()->format('l, F j, Y') }}</span>
            <button onclick="refreshDashboard(event)" class="flex items-center space-x-2 text-sm text-green-600 hover:text-green-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <span>Refresh</span>
            </button>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Total Users -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-shadow duration-200">
            <div class="flex items-center justify-between mb-4">
                <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </div>
                @php
                    $newUsersYesterday = \App\Models\User::whereDate('created_at', \Carbon\Carbon::yesterday())->count();
                    $newUsersToday = \App\Models\User::whereDate('created_at', \Carbon\Carbon::today())->count();
                    $growth = $newUsersYesterday > 0 ? (($newUsersToday - $newUsersYesterday) / $newUsersYesterday * 100) : 100;
                @endphp
                <span class="text-sm font-semibold flex items-center {{ $growth >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    @if($growth >= 0)
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/>
                        </svg>
                    @else
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                        </svg>
                    @endif
                    {{ number_format(abs($growth), 1) }}%
                </span>
            </div>
            <h3 class="text-3xl font-bold text-gray-900 mb-1">{{ number_format($stats['total_users'] ?? 0) }}</h3>
            <p class="text-gray-600 text-sm">Total Users</p>
        </div>

        <!-- Total Revenue -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-shadow duration-200">
            <div class="flex items-center justify-between mb-4">
                <div class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <span class="text-green-600 text-sm font-semibold">Lifetime</span>
            </div>
            <h3 class="text-3xl font-bold text-gray-900 mb-1">₦{{ number_format($stats['total_volume'] ?? 0, 2) }}</h3>
            <p class="text-gray-600 text-sm">Total Revenue</p>
        </div>

        <!-- Pending Transfers -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-shadow duration-200">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <div>
            <p class="text-2xl font-bold text-gray-900">
                @if($stats['clubkonnect_success'] ?? false)
                    ₦{{ number_format($stats['clubkonnect_balance'] ?? 0, 2) }}
                @else
                    <span class="text-red-500 text-sm">N/A</span>
                @endif
            </p>
            <p class="text-sm text-gray-600">ClubKonnect Balance</p>
            @if($stats['clubkonnect_date'] ?? false)
                <p class="text-xs text-gray-500 mt-1">Updated: {{ $stats['clubkonnect_date'] }}</p>
            @endif
            @if(isset($stats['clubkonnect_error']) && !$stats['clubkonnect_success'])
                <p class="text-xs text-red-500 mt-1">{{ Str::limit($stats['clubkonnect_error'], 30) }}</p>
            @endif
        </div>
        </div>

        <!-- Today's Transactions -->
        <div id="paystack-balance-container" class="h-auto">
    @include('components.paystack-balance-card', [
        'balance' => $stats['paystack_balance'] ?? 0,
        'currency' => $stats['paystack_currency'] ?? 'NGN',
        'stats' => $stats['paystack_stats'] ?? []
    ])
</div>
    </div>

   
<div class="mb-8">
    <h2 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
        <span class="text-2xl mr-2">💬</span>
        Communication & Support
    </h2>
    
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        {{-- Active Chats --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-shadow">
            <div class="flex items-center justify-between mb-3">
                <div class="w-12 h-12 bg-gradient-to-br from-green-400 to-green-500 rounded-xl flex items-center justify-center">
                    <span class="text-2xl">💬</span>
                </div>
                <a href="{{ route('admin.chat.index') }}" 
                   class="text-xs text-green-600 hover:text-green-800 font-semibold">
                    View →
                </a>
            </div>
            <h3 class="text-2xl font-bold text-gray-900" id="activeChatsStat">
                {{ $chatStats['active_chats'] ?? 0 }}
            </h3>
            <p class="text-sm text-gray-600 mt-1">Active Chats</p>
            @if(($chatStats['pending_chats'] ?? 0) > 0)
            <div class="mt-3 pt-3 border-t border-gray-100">
                <span class="text-xs text-yellow-600 font-semibold">
                    {{ $chatStats['pending_chats'] }} waiting
                </span>
            </div>
            @endif
        </div>

        {{-- Unread Chat Messages --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-shadow">
            <div class="flex items-center justify-between mb-3">
                <div class="w-12 h-12 bg-gradient-to-br from-yellow-400 to-yellow-500 rounded-xl flex items-center justify-center">
                    <span class="text-2xl">📩</span>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900" id="unreadMessagesStat">
                {{ $chatStats['unread_messages'] ?? 0 }}
            </h3>
            <p class="text-sm text-gray-600 mt-1">Unread Messages</p>
            <div class="mt-3 pt-3 border-t border-gray-100">
                <span class="text-xs text-gray-500">
                    {{ $chatStats['total_messages'] ?? 0 }} today
                </span>
            </div>
        </div>

        {{-- Contact Messages --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-shadow">
            <div class="flex items-center justify-between mb-3">
                <div class="w-12 h-12 bg-gradient-to-br from-green-400 to-green-500 rounded-xl flex items-center justify-center">
                    <span class="text-2xl">📧</span>
                </div>
                <a href="{{ route('admin.contact.index') }}" 
                   class="text-xs text-green-600 hover:text-green-800 font-semibold">
                    View →
                </a>
            </div>
            <h3 class="text-2xl font-bold text-gray-900" id="contactMessagesStat">
                {{ $contactStats['unread'] ?? 0 }}
            </h3>
            <p class="text-sm text-gray-600 mt-1">Unread Contacts</p>
            <div class="mt-3 pt-3 border-t border-gray-100">
                <span class="text-xs text-gray-500">
                    {{ $contactStats['today'] ?? 0 }} new today
                </span>
            </div>
        </div>

        {{-- Online Users --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-shadow">
            <div class="flex items-center justify-between mb-3">
                <div class="w-12 h-12 bg-gradient-to-br from-emerald-400 to-emerald-500 rounded-xl flex items-center justify-center">
                    <span class="text-2xl">⚡</span>
                </div>
                <div class="flex items-center space-x-1">
                    <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                    <span class="text-xs text-green-600 font-semibold">Live</span>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900" id="onlineUsersStat">
                {{ $userStats['online_now'] ?? 0 }}
            </h3>
            <p class="text-sm text-gray-600 mt-1">Users Online</p>
            <div class="mt-3 pt-3 border-t border-gray-100">
                <span class="text-xs text-gray-500">
                    {{ $userStats['total'] ?? 0 }} total users
                </span>
            </div>
        </div>
    </div>
</div>


    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Revenue Chart -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-bold text-gray-900">Revenue Overview (Last 7 Days)</h3>
                <select id="revenuePeriod" class="text-sm border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500" onchange="updateRevenueChart(this.value)">
                    <option value="weekly">Last 7 days</option>
                    <option value="monthly">Last 30 days</option>
                    <option value="daily">Today</option>
                </select>
            </div>
            <canvas id="revenueChart" height="300"></canvas>
        </div>

        <!-- Transaction Types Chart -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-bold text-gray-900">Transaction Types</h3>
                <span class="text-sm text-gray-600">All Time</span>
            </div>
            <canvas id="transactionTypesChart" height="300"></canvas>
        </div>
    </div>

    <!-- Recent Activity & Quick Actions -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Recent Transactions -->
        <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-bold text-gray-900">Recent Transactions</h3>
                <a href="{{ route('admin.transactions.index') }}" class="text-sm text-green-600 hover:text-green-700 font-semibold">View All →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase">User</th>
                            <th class="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase">Service</th>
                            <th class="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase">Amount</th>
                            <th class="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase">Status</th>
                            <th class="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase">Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentTransactions ?? [] as $transaction)
                        <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors">
                            <td class="py-3 px-4">
                                <div class="flex items-center">
                                    <div class="w-8 h-8 bg-gradient-to-br from-green-400 to-emerald-500 rounded-full flex items-center justify-center text-white text-xs font-bold mr-3">
                                        {{ substr($transaction->user->name ?? 'U', 0, 1) }}
                                    </div>
                                    <span class="text-sm font-medium text-gray-900">{{ $transaction->user->name ?? 'Unknown' }}</span>
                                </div>
                            </td>
                            <td class="py-3 px-4 text-sm text-gray-900">{{ ucfirst(str_replace('-', ' ', $transaction->service_type)) }}</td>
                            <td class="py-3 px-4 text-sm font-semibold text-gray-900">₦{{ number_format($transaction->amount, 2) }}</td>
                            <td class="py-3 px-4">
                                @if($transaction->status === 'success')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800">Success</span>
                                @elseif($transaction->status === 'pending')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800">Pending</span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">Failed</span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-sm text-gray-600">{{ $transaction->created_at->diffForHumans() }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-gray-500">No recent transactions</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-6">Quick Actions</h3>
            <div class="space-y-3">
                <a href="{{ route('admin.bank-transfers.index') }}" class="block p-4 bg-gradient-to-r from-green-50 to-emerald-50 hover:from-green-100 hover:to-emerald-100 rounded-xl border border-green-200 transition-all duration-200 group">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                </svg>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900 text-sm">Review Transfers</p>
                                <p class="text-xs text-gray-600">{{ $stats['pending_transfers'] ?? 0 }} pending</p>
                            </div>
                        </div>
                        <svg class="w-5 h-5 text-gray-400 group-hover:text-green-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </div>
                </a>

                <a href="{{ route('admin.users.index') }}" class="block p-4 bg-gradient-to-r from-green-50 to-cyan-50 hover:from-green-100 hover:to-cyan-100 rounded-xl border border-green-200 transition-all duration-200 group">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-green-500 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900 text-sm">Manage Users</p>
                                <p class="text-xs text-gray-600">View all users</p>
                            </div>
                        </div>
                        <svg class="w-5 h-5 text-gray-400 group-hover:text-green-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </div>
                </a>

                <a href="{{ route('admin.transactions.index') }}" class="block p-4 bg-gradient-to-r from-purple-50 to-pink-50 hover:from-purple-100 hover:to-pink-100 rounded-xl border border-purple-200 transition-all duration-200 group">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-purple-500 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900 text-sm">View Reports</p>
                                <p class="text-xs text-gray-600">All transactions</p>
                            </div>
                        </div>
                        <svg class="w-5 h-5 text-gray-400 group-hover:text-purple-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </div>
                </a>

                <a href="#" class="block p-4 bg-gradient-to-r from-gray-50 to-slate-50 hover:from-gray-100 hover:to-slate-100 rounded-xl border border-gray-200 transition-all duration-200 group">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-gray-500 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900 text-sm">Settings</p>
                                <p class="text-xs text-gray-600">Platform settings</p>
                            </div>
                        </div>
                        <svg class="w-5 h-5 text-gray-400 group-hover:text-gray-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Hidden data for JavaScript -->
<div id="chartData" 
     data-revenue='@json($chartData['revenue']['data'] ?? [])'
     data-revenue-labels='@json($chartData['revenue']['labels'] ?? [])'
     data-type-labels='@json($chartData['types']['labels'] ?? [])'
     data-type-data='@json($chartData['types']['data'] ?? [])'
     data-type-colors='@json($chartData['types']['colors'] ?? [])'
     style="display: none;">
</div>
@endsection

@push('scripts')
<script>
// Initialize charts when document is loaded
document.addEventListener('DOMContentLoaded', function() {
    initRevenueChart();
    initTransactionTypesChart();
});

// Revenue Chart
let revenueChart = null;
function initRevenueChart() {
    const ctx = document.getElementById('revenueChart').getContext('2d');
    
    // Get data from hidden div
    const revenueData = JSON.parse(document.getElementById('chartData').dataset.revenue || '[]');
    const revenueLabels = JSON.parse(document.getElementById('chartData').dataset.revenueLabels || '[]');
    
    // Destroy existing chart if it exists
    if (revenueChart) {
        revenueChart.destroy();
    }
    
    revenueChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: revenueLabels,
            datasets: [{
                label: 'Revenue',
                data: revenueData,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                tension: 0.4,
                fill: true,
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '₦' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₦' + value.toLocaleString();
                        },
                        maxTicksLimit: 5 // Limit number of y-axis labels
                    },
                    grid: {
                        drawBorder: false
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        maxRotation: 0 // Prevent label rotation
                    }
                }
            },
            // Prevent chart from expanding
            layout: {
                padding: {
                    left: 10,
                    right: 10,
                    top: 10,
                    bottom: 10
                }
            }
        }
    });
}

// Transaction Types Chart
let transactionTypesChart = null;
function initTransactionTypesChart() {
    const ctx = document.getElementById('transactionTypesChart').getContext('2d');
    
    // Get data from hidden div
    const typeLabels = JSON.parse(document.getElementById('chartData').dataset.typeLabels || '[]');
    const typeData = JSON.parse(document.getElementById('chartData').dataset.typeData || '[]');
    const typeColors = JSON.parse(document.getElementById('chartData').dataset.typeColors || '{}');
    
    // Map colors to data
    const backgroundColors = typeLabels.map(label => {
        const key = label.toLowerCase().replace(' ', '-');
        return typeColors[key] || '#6b7280';
    });
    
    // Destroy existing chart if it exists
    if (transactionTypesChart) {
        transactionTypesChart.destroy();
    }
    
    transactionTypesChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: typeLabels,
            datasets: [{
                data: typeData,
                backgroundColor: backgroundColors,
                borderWidth: 1,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true,
                        font: {
                            size: 11
                        },
                        boxWidth: 12 // Reduce legend box width
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = Math.round((value / total) * 100);
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            },
            cutout: '65%', // Reduce hole size
            layout: {
                padding: {
                    top: 10,
                    bottom: 10
                }
            }
        }
    });
}

// Update revenue chart based on period
function updateRevenueChart(period) {
    fetch(`/admin/stats/revenue?period=${period}`)
        .then(response => response.json())
        .then(data => {
            if (revenueChart) {
                revenueChart.data.labels = data.labels;
                revenueChart.data.datasets[0].data = data.revenue;
                revenueChart.update('none'); // 'none' prevents animation
            }
        })
        .catch(error => {
            console.error('Error updating revenue chart:', error);
        });
}

// Refresh dashboard data
function refreshDashboard(event) {
    const refreshBtn = event.target.closest('button');
    if (!refreshBtn) return;
    
    // Show loading
    refreshBtn.disabled = true;
    const originalContent = refreshBtn.innerHTML;
    refreshBtn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg><span>Refreshing...</span>';
    
    // Reload page after a short delay
    setTimeout(() => {
        window.location.reload();
    }, 1000);
}

// Resize charts on window resize
let resizeTimeout;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(function() {
        if (revenueChart) revenueChart.resize();
        if (transactionTypesChart) transactionTypesChart.resize();
    }, 250);
});
</script>
@endpush

