@extends('admin.layouts.app')

@section('title', 'Transactions')
@section('page-title', 'Transaction Management')

@section('content')
<div class="space-y-6">
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Total Transactions</span>
                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ number_format($stats['total']) }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Total Volume</span>
                <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">₦{{ number_format($stats['total_amount'], 2) }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Successful</span>
                <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ number_format($stats['success']) }}</h3>
            <p class="text-xs text-green-600 mt-1">
                {{ $stats['total'] > 0 ? round(($stats['success'] / $stats['total']) * 100, 1) : 0 }}% success rate
            </p>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Pending</span>
                <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ number_format($stats['pending']) }}</h3>
        </div>
    </div>

    <!-- Service Type Distribution -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-bold text-gray-900 mb-4">Service Type Distribution</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4">
            @php
                $serviceTypeLabels = [
                    'airtime' => 'Airtime',
                    'data' => 'Data',
                    'funding' => 'Funding',
                    'transfer' => 'Transfer',
                    'cable-tv' => 'Cable TV',
                    'electricity' => 'Electricity',
                    'exam' => 'Exam',
                ];
                
                $serviceTypeColors = [
                    'airtime' => 'bg-blue-500',
                    'data' => 'bg-purple-500',
                    'funding' => 'bg-green-500',
                    'transfer' => 'bg-indigo-500',
                    'cable-tv' => 'bg-red-500',
                    'electricity' => 'bg-yellow-500',
                    'exam' => 'bg-pink-500',
                ];
            @endphp
            
            @foreach($serviceTypes as $type => $count)
                @if($count > 0)
                <div class="text-center">
                    <div class="mb-2">
                        <div class="w-12 h-12 {{ $serviceTypeColors[$type] }} rounded-full flex items-center justify-center text-white font-bold mx-auto">
                            {{ $count }}
                        </div>
                    </div>
                    <p class="text-xs font-medium text-gray-700">{{ $serviceTypeLabels[$type] }}</p>
                </div>
                @endif
            @endforeach
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-900">Filter Transactions</h3>
            <button onclick="toggleAdvancedFilters()" class="text-sm text-green-600 hover:text-green-700 font-medium">
                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                </svg>
                Advanced Filters
            </button>
        </div>
        
        <form method="GET" action="{{ route('admin.transactions.index') }}" id="filterForm">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                    <input type="text" name="search" value="{{ request('search') }}" 
                           placeholder="Reference, user, description..." 
                           class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Service Type</label>
                    <select name="service_type" class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                        <option value="">All Types</option>
                        <option value="airtime" {{ request('service_type') == 'airtime' ? 'selected' : '' }}>Airtime</option>
                        <option value="data" {{ request('service_type') == 'data' ? 'selected' : '' }}>Data</option>
                        <option value="funding" {{ request('service_type') == 'funding' ? 'selected' : '' }}>Funding</option>
                        <option value="transfer" {{ request('service_type') == 'transfer' ? 'selected' : '' }}>Transfer</option>
                        <option value="cable-tv" {{ request('service_type') == 'cable-tv' ? 'selected' : '' }}>Cable TV</option>
                        <option value="electricity" {{ request('service_type') == 'electricity' ? 'selected' : '' }}>Electricity</option>
                        <option value="exam" {{ request('service_type') == 'exam' ? 'selected' : '' }}>Exam</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select name="status" class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                        <option value="">All Statuses</option>
                        <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="processing" {{ request('status') == 'processing' ? 'selected' : '' }}>Processing</option>
                        <option value="success" {{ request('status') == 'success' ? 'selected' : '' }}>Success</option>
                        <option value="failed" {{ request('status') == 'failed' ? 'selected' : '' }}>Failed</option>
                        <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Payment Method</label>
                    <select name="payment_method" class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                        <option value="">All Methods</option>
                        <option value="wallet" {{ request('payment_method') == 'wallet' ? 'selected' : '' }}>Wallet</option>
                        <option value="bank_transfer" {{ request('payment_method') == 'bank_transfer' ? 'selected' : '' }}>Bank Transfer</option>
                        <option value="card" {{ request('payment_method') == 'card' ? 'selected' : '' }}>Card</option>
                        <option value="manual" {{ request('payment_method') == 'manual' ? 'selected' : '' }}>Manual</option>
                    </select>
                </div>
            </div>

            <!-- Advanced Filters (Hidden by Default) -->
            <div id="advancedFilters" class="hidden space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                        <input type="date" name="date_from" value="{{ request('date_from') }}" 
                               class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                        <input type="date" name="date_to" value="{{ request('date_to') }}" 
                               class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Min Amount (₦)</label>
                        <input type="number" name="min_amount" value="{{ request('min_amount') }}" 
                               placeholder="0" min="0" step="0.01"
                               class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Max Amount (₦)</label>
                        <input type="number" name="max_amount" value="{{ request('max_amount') }}" 
                               placeholder="1000000" min="0" step="0.01"
                               class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between mt-6">
                <div class="flex items-center space-x-2">
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2.5 px-6 rounded-lg transition-colors">
                        Apply Filters
                    </button>
                    <a href="{{ route('admin.transactions.index') }}" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold py-2.5 px-6 rounded-lg transition-colors">
                        Reset
                    </a>
                </div>
                
                <div class="flex items-center space-x-2">
                    <button type="button" onclick="exportTransactions()" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 px-6 rounded-lg transition-colors">
                        Export CSV
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Transactions List -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-bold text-gray-900">All Transactions</h3>
            <div class="text-sm text-gray-600">
                Showing {{ $transactions->firstItem() ?? 0 }} - {{ $transactions->lastItem() ?? 0 }} of {{ $transactions->total() }} transactions
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Transaction</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">User</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Service</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Amount</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Status</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Date</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($transactions as $transaction)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="py-4 px-6">
                            <div>
                                <p class="font-semibold text-gray-900">{{ $transaction->reference }}</p>
                                <p class="text-xs text-gray-600 truncate max-w-xs">{{ $transaction->description }}</p>
                                @if($transaction->payment_method)
                                <div class="inline-flex items-center mt-1 px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                    {{ ucfirst(str_replace('_', ' ', $transaction->payment_method)) }}
                                </div>
                                @endif
                            </div>
                        </td>
                        <td class="py-4 px-6">
                            @if($transaction->user)
                            <div class="flex items-center">
                                <div class="w-8 h-8 bg-gradient-to-br from-blue-400 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-sm mr-2">
                                    {{ substr($transaction->user->name, 0, 1) }}
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900">{{ $transaction->user->name }}</p>
                                    <p class="text-xs text-gray-600">{{ $transaction->user->email }}</p>
                                </div>
                            </div>
                            @else
                            <span class="text-gray-400 text-sm">User deleted</span>
                            @endif
                        </td>
                        <td class="py-4 px-6">
                            <div class="flex items-center">
                                @php
                                    $serviceIcons = [
                                        'airtime' => 'M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z',
                                        'data' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
                                        'funding' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                                        'transfer' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
                                        'cable-tv' => 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
                                        'electricity' => 'M13 10V3L4 14h7v7l9-11h-7z',
                                        'exam' => 'M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222',
                                    ];
                                    
                                    $serviceColors = [
                                        'airtime' => 'text-blue-600 bg-blue-100',
                                        'data' => 'text-purple-600 bg-purple-100',
                                        'funding' => 'text-green-600 bg-green-100',
                                        'transfer' => 'text-indigo-600 bg-indigo-100',
                                        'cable-tv' => 'text-red-600 bg-red-100',
                                        'electricity' => 'text-yellow-600 bg-yellow-100',
                                        'exam' => 'text-pink-600 bg-pink-100',
                                    ];
                                    
                                    $serviceLabel = ucwords(str_replace('-', ' ', $transaction->service_type));
                                    $iconPath = $serviceIcons[$transaction->service_type] ?? 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2';
                                    $colorClass = $serviceColors[$transaction->service_type] ?? 'text-gray-600 bg-gray-100';
                                @endphp
                                <div class="w-8 h-8 {{ $colorClass }} rounded-lg flex items-center justify-center mr-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $iconPath }}"/>
                                    </svg>
                                </div>
                                <span class="text-sm font-medium text-gray-900">{{ $serviceLabel }}</span>
                            </div>
                        </td>
                        <td class="py-4 px-6">
                            <div>
                                <p class="text-lg font-bold text-gray-900">₦{{ number_format($transaction->amount, 2) }}</p>
                                @if($transaction->service_fee > 0)
                                <p class="text-xs text-gray-500">Fee: ₦{{ number_format($transaction->service_fee, 2) }}</p>
                                @endif
                            </div>
                        </td>
                        <td class="py-4 px-6">
                            @if($transaction->status === 'pending')
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800">
                                    <span class="w-2 h-2 bg-yellow-500 rounded-full mr-2"></span>
                                    Pending
                                </span>
                            @elseif($transaction->status === 'processing')
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">
                                    <svg class="w-3 h-3 mr-1 animate-spin" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/>
                                    </svg>
                                    Processing
                                </span>
                            @elseif($transaction->status === 'success')
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                    Success
                                </span>
                            @elseif($transaction->status === 'failed')
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                    </svg>
                                    Failed
                                </span>
                            @else
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-800">
                                    Cancelled
                                </span>
                            @endif
                            @if($transaction->status_message)
                            <p class="text-xs text-gray-500 mt-1 truncate max-w-xs">{{ $transaction->status_message }}</p>
                            @endif
                        </td>
                        <td class="py-4 px-6 text-sm text-gray-600">
                            {{ $transaction->created_at->format('M d, Y') }}<br>
                            <span class="text-xs text-gray-500">{{ $transaction->created_at->format('h:i A') }}</span>
                            @if($transaction->completed_at)
                            <div class="text-xs text-green-600 mt-1">
                                Completed: {{ $transaction->completed_at->format('h:i A') }}
                            </div>
                            @endif
                        </td>
                        <td class="py-4 px-6">
                            <button onclick="viewTransactionDetails({{ $transaction->id }})" 
                                    class="text-green-600 hover:text-green-700 font-semibold text-sm px-3 py-1.5 bg-green-50 hover:bg-green-100 rounded-lg transition-colors">
                                View Details
                            </button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="py-12 text-center">
                            <div class="flex flex-col items-center">
                                <svg class="w-16 h-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                <p class="text-gray-600 font-medium">No transactions found</p>
                                <p class="text-sm text-gray-500 mt-1">
                                    @if(request()->hasAny(['search', 'service_type', 'status', 'payment_method']))
                                        Try adjusting your filters
                                    @else
                                        Transactions will appear here when users make them
                                    @endif
                                </p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($transactions->hasPages())
        <div class="p-6 border-t border-gray-200">
            {{ $transactions->withQueryString()->links() }}
        </div>
        @endif
    </div>
</div>

<!-- Transaction Details Modal -->
<div id="transactionModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <div>
                    <h3 id="modalTitle" class="text-xl font-bold text-gray-900">Transaction Details</h3>
                    <p id="modalSubtitle" class="text-sm text-gray-600">Loading...</p>
                </div>
                <button onclick="closeTransactionModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>

        <div id="transactionModalContent" class="p-6">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function toggleAdvancedFilters() {
    const advancedFilters = document.getElementById('advancedFilters');
    advancedFilters.classList.toggle('hidden');
}

function viewTransactionDetails(transactionId) {
    console.log('Loading transaction details for ID:', transactionId);
    
    const modalContent = document.getElementById('transactionModalContent');
    const modal = document.getElementById('transactionModal');
    
    modalContent.innerHTML = `
        <div class="flex items-center justify-center py-12">
            <div class="text-center">
                <svg class="w-8 h-8 text-gray-400 animate-spin mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <p class="text-gray-600">Loading transaction details...</p>
            </div>
        </div>
    `;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    
    // Use the correct route URL
    const url = `/admin/transactions/${transactionId}?modal=true`;
    console.log('Fetching URL:', url);
    
    fetch(url, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'text/html, application/json'
        }
    })
    .then(response => {
        console.log('Response status:', response.status, response.statusText);
        
        if (!response.ok) {
            return response.text().then(text => {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            });
        }
        
        return response.text();
    })
    .then(html => {
        console.log('Successfully loaded HTML, length:', html.length);
        
        if (!html || html.trim() === '') {
            throw new Error('Received empty response from server');
        }
        
        modalContent.innerHTML = html;
        
        // Initialize any scripts in the loaded content
        const scripts = modalContent.querySelectorAll('script');
        scripts.forEach(script => {
            const newScript = document.createElement('script');
            if (script.src) {
                newScript.src = script.src;
            } else {
                newScript.textContent = script.textContent;
            }
            document.body.appendChild(newScript);
        });
    })
    .catch(error => {
        console.error('=== ERROR LOADING TRANSACTION DETAILS ===');
        console.error('Transaction ID:', transactionId);
        console.error('Error name:', error.name);
        console.error('Error message:', error.message);
        console.error('Full error:', error);
        
        modalContent.innerHTML = `
            <div class="flex items-center justify-center py-12">
                <div class="text-center max-w-md">
                    <svg class="w-16 h-16 text-red-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.998-.833-2.732 0L4.732 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Error Loading Details</h3>
                    <p class="text-gray-600 text-sm mb-4">Failed to load transaction details. Please try again.</p>
                    <div class="bg-gray-50 rounded-lg p-3 mb-4 text-left">
                        <p class="text-xs font-mono text-gray-500 break-all">Error: ${error.message.substring(0, 100)}</p>
                    </div>
                    <div class="flex justify-center space-x-3">
                        <button onclick="viewTransactionDetails(${transactionId})" 
                                class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition-colors">
                            Try Again
                        </button>
                        <button onclick="closeTransactionModal()" 
                                class="px-4 py-2 border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium rounded-lg transition-colors">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        `;
    });
}

function closeTransactionModal() {
    document.getElementById('transactionModal').classList.add('hidden');
    document.getElementById('transactionModal').classList.remove('flex');
    document.getElementById('transactionModalContent').innerHTML = '';
}

function closeTransactionModal() {
    document.getElementById('transactionModal').classList.add('hidden');
    document.getElementById('transactionModal').classList.remove('flex');
    document.getElementById('transactionModalContent').innerHTML = '';
}

function exportTransactions() {
    const form = document.getElementById('filterForm');
    const formData = new FormData(form);
    const params = new URLSearchParams(formData).toString();
    
    // Show loading state
    const exportBtn = document.querySelector('button[onclick="exportTransactions()"]');
    const originalText = exportBtn.innerHTML;
    exportBtn.innerHTML = `
        <svg class="w-4 h-4 animate-spin inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Exporting...
    `;
    exportBtn.disabled = true;
    
    fetch(`/admin/transactions/export?${params}`, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        // Reset button
        exportBtn.innerHTML = originalText;
        exportBtn.disabled = false;
        
        if (data.url) {
            // Download the file
            window.location.href = data.url;
        } else if (data.message) {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Export error:', error);
        exportBtn.innerHTML = originalText;
        exportBtn.disabled = false;
        alert('Export failed. Please try again.');
    });
}

// Close modal when clicking outside
document.getElementById('transactionModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeTransactionModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('transactionModal').classList.contains('flex')) {
        closeTransactionModal();
    }
});
</script>
@endpush