@extends('layouts.app')
@section('content')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8 md:py-12">
        <div class="max-w-full md:max-w-[900px] overflow-clip mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Page Header -->
            <div class="mb-8 md:mb-12">
                <div class="flex flex-col md:flex-row items-center justify-between mb-4">
                    <div class="flex flex-col md:flex-row space-y-5 items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center shadow-lg">
                            <span class="text-2xl">💰</span>
                        </div>
                        <div class="text-center sm:mb-5 md:text-left">
                            <h1 class="text-2xl md:text-4xl font-bold text-gray-900">Wallet Management</h1>
                            <p class="text-gray-600 text-sm md:text-base mt-1">Manage your balance, fund your wallet, and view transactions</p>
                        </div>
                    </div>
                    <a href="{{ route('wallet.fund') }}" class="mt-5 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-bold py-3 px-6 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-200 flex items-center space-x-2">
                        <span>+ Fund Wallet</span>
                        <span>💳</span>
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">
                <!-- Main Content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Wallet Balance Card -->
                    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-2xl shadow-xl p-6 md:p-8 text-white">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <p class="text-sm opacity-90">Total Balance</p>
                                <p class="text-2xl md:text-4xl font-bold mt-2">₦{{ number_format(auth()->user()->wallet_balance ?? 0, 2) }}</p>
                                <div class="flex items-center space-x-2 mt-3">
                                    <span class="text-xs bg-white/20 px-3 py-1 rounded-full">💎 Active</span>
                                    <span class="text-xs bg-white/20 px-3 py-1 rounded-full">⚡ Instant</span>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="text-5xl">💰</span>
                                <p class="text-sm mt-2 opacity-90">Last updated: {{ now()->format('jS M, Y') }}</p>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6">
                            <div class="bg-white/10 rounded-xl p-3 text-center backdrop-blur-sm">
                                <p class="text-xs opacity-80">Today's Spent</p>
                                <p class="text-lg font-bold">₦{{ number_format($monthlyStats['today_volume'] ?? 0, 2) }}</p>
                            </div>
                            <div class="bg-white/10 rounded-xl p-3 text-center backdrop-blur-sm">
                                <p class="text-xs opacity-80">This Month</p>
                                <p class="text-lg font-bold">₦{{ $monthlyStats['spent'] }}</p>
                            </div>
                            <div class="bg-white/10 rounded-xl p-3 text-center backdrop-blur-sm">
                                <p class="text-xs opacity-80">Transactions</p>
                                <p class="text-lg font-bold">{{ $monthlyStats['transactions'] }}</p>
                            </div>
                            <div class="bg-white/10 rounded-xl p-3 text-center backdrop-blur-sm">
                                <p class="text-xs opacity-80">Avg. Daily</p>
                                <p class="text-lg font-bold">₦{{ $monthlyStats['today_volume'] }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">⚡</span>
                            Quick Actions
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <a href="{{ route('wallet.fund') }}" class="bg-green-50 hover:bg-green-100 border-2 border-green-200 rounded-xl p-4 text-center transition-all duration-200 group">
                                <div class="w-12 h-12 bg-green-100 rounded-lg mx-auto mb-3 flex items-center justify-center group-hover:scale-110 transition-transform">
                                    <span class="text-2xl">💳</span>
                                </div>
                                <p class="font-semibold text-green-800">Fund Wallet</p>
                                <p class="text-xs text-gray-600 mt-1">Add money</p>
                            </a>
                            
                            <a href="#" class="bg-blue-50 hover:bg-blue-100 border-2 border-blue-200 rounded-xl p-4 text-center transition-all duration-200 group">
                                <div class="w-12 h-12 bg-blue-100 rounded-lg mx-auto mb-3 flex items-center justify-center group-hover:scale-110 transition-transform">
                                    <span class="text-2xl">📞</span>
                                </div>
                                <p class="font-semibold text-blue-800">Buy Airtime</p>
                                <p class="text-xs text-gray-600 mt-1">Instant recharge</p>
                            </a>
                            
                            <a href="#" class="bg-purple-50 hover:bg-purple-100 border-2 border-purple-200 rounded-xl p-4 text-center transition-all duration-200 group">
                                <div class="w-12 h-12 bg-purple-100 rounded-lg mx-auto mb-3 flex items-center justify-center group-hover:scale-110 transition-transform">
                                    <span class="text-2xl">📶</span>
                                </div>
                                <p class="font-semibold text-purple-800">Buy Data</p>
                                <p class="text-xs text-gray-600 mt-1">Fast delivery</p>
                            </a>
                            
                            <a href="{{ route('cable-tv.index') }}" class="bg-amber-50 hover:bg-amber-100 border-2 border-amber-200 rounded-xl p-4 text-center transition-all duration-200 group">
                                <div class="w-12 h-12 bg-amber-100 rounded-lg mx-auto mb-3 flex items-center justify-center group-hover:scale-110 transition-transform">
                                    <span class="text-2xl">📺</span>
                                </div>
                                <p class="font-semibold text-amber-800">TV Subscription</p>
                                <p class="text-xs text-gray-600 mt-1">Cable TV</p>
                            </a>
                        </div>
                    </div>

                    <!-- Recent Transactions -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="font-bold text-gray-900 flex items-center">
                                <span class="text-xl mr-2">📊</span>
                                Recent Transactions
                            </h3>
                            <a href="#" class="text-green-600 hover:text-green-700 text-sm font-medium">View All →</a>
                        </div>
                        
                        <div class="space-y-3">
                            @forelse($recent_transactions as $transaction)
                            <div class="flex items-center justify-between p-4 border border-gray-100 rounded-xl hover:bg-gray-50 transition-all duration-200">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 rounded-lg flex items-center justify-center 
                                        {{ $transaction->type === 'credit' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600' }}">
                                        <span class="text-xl">
                                            @if($transaction->type === 'credit')
                                            ⬇️
                                            @elseif($transaction->service_type === 'airtime')
                                            📞
                                            @elseif($transaction->service_type === 'data')
                                            📶
                                            @elseif($transaction->service_type === 'cable-tv')
                                            📺
                                            @elseif($transaction->service_type === 'jamb')
                                            🎓
                                            @else
                                            💸
                                            @endif
                                        </span>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-gray-900">{{ $transaction->description }}</p>
                                        <p class="text-xs text-gray-500">
                                            {{ $transaction->created_at->format('M j, Y • h:i A') }}
                                            @if($transaction->reference)
                                            • Ref: {{ $transaction->reference }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold {{ $transaction->type === 'credit' ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $transaction->type === 'credit' ? '+' : '-' }}₦{{ number_format($transaction->amount, 2) }}
                                    </p>
                                    <span class="text-xs px-2 py-1 rounded-full 
                                        {{ $transaction->status === 'success' ? 'bg-green-100 text-green-600' : 
                                           ($transaction->status === 'pending' ? 'bg-amber-100 text-amber-600' : 'bg-red-100 text-red-600') }}">
                                        {{ ucfirst($transaction->status) }}
                                    </span>
                                </div>
                            </div>
                            @empty
                            <div class="text-center py-8">
                                <div class="w-16 h-16 bg-gray-100 rounded-full mx-auto mb-4 flex items-center justify-center">
                                    <span class="text-2xl">📭</span>
                                </div>
                                <p class="text-gray-600">No transactions yet</p>
                                <p class="text-sm text-gray-500 mt-1">Your transaction history will appear here</p>
                            </div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Payment Methods -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">💳</span>
                            Payment Methods
                        </h3>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                        <span class="text-blue-600">🏦</span>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900">Bank Transfer</p>
                                        <p class="text-xs text-gray-600">Instant • No fees</p>
                                    </div>
                                </div>
                                <span class="text-green-600">✓</span>
                            </div>
                            
                            <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                                        <span class="text-purple-600">💎</span>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900">Card Payment</p>
                                        <p class="text-xs text-gray-600">Visa/Mastercard</p>
                                    </div>
                                </div>
                                <span class="text-green-600">✓</span>
                            </div>
                            
                            <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                        <span class="text-green-600">📱</span>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900">USSD</p>
                                        <p class="text-xs text-gray-600">*966# Code</p>
                                    </div>
                                </div>
                                <span class="text-green-600">✓</span>
                            </div>
                        </div>
                    </div>

                    <!-- Wallet Tips -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">💡</span>
                            Wallet Tips
                        </h3>
                        <ul class="space-y-3 text-sm text-gray-600">
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">✓</span>
                                <span>Keep minimum ₦500 for instant transactions</span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">✓</span>
                                <span>Set up auto-fund for uninterrupted service</span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">✓</span>
                                <span>Monitor spending with transaction alerts</span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">✓</span>
                                <span>Contact support for failed transactions</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Support -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">🛟</span>
                            Need Help?
                        </h3>
                        <div class="space-y-3 text-sm text-gray-600">
                            <p>For wallet issues or questions:</p>
                            <div class="bg-green-50 rounded-lg p-3">
                                <p class="font-medium text-green-900 mb-1">Support Team</p>
                                <p class="text-xs">📞 {{ $siteSettings['contact_phone'] ?? '' }}</p>
                                <p class="text-xs">✉️ {{ $siteSettings['support_email'] ?? '' }}</p>
                                <p class="text-xs">🕐 24/7 Support</p>
                            </div>
                            <p class="text-xs text-gray-500">Average response time: 5 minutes</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
@endsection