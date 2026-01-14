@extends('layouts.app')
@section('content')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8 md:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <!-- Page Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg">
                            <span class="text-2xl">💳</span>
                        </div>
                        <div>
                            <h1 class="text-2xl md:text-3xl font-bold text-gray-900">Wallet History</h1>
                            <p class="text-gray-600 text-sm md:text-base">Track all your transactions</p>
                        </div>
                    </div>
                    <a href="{{ route('wallet.fund') }}" class="inline-flex items-center bg-green-600 hover:bg-green-700 text-white font-semibold px-6 py-3 rounded-xl shadow-md hover:shadow-lg transform hover:scale-105 transition-all duration-200">
                        <span class="mr-2">+</span>
                        Fund Wallet
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 md:gap-8">
                <!-- Sidebar -->
                <div class="lg:col-span-1 space-y-6">
                    <!-- Wallet Balance Card -->
                    <div class="bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-lg p-6 text-white">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm opacity-90 font-medium">Available Balance</span>
                            <span class="text-2xl">💰</span>
                        </div>
                        <div class="text-3xl md:text-4xl font-bold mb-6">₦{{ number_format(auth()->user()->wallet_balance ?? 0, 2) }}</div>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="bg-white/20 rounded-lg p-3">
                                <p class="text-xs opacity-90 mb-1">Total Spent</p>
                                <p class="font-bold text-sm">₦0.00</p>
                            </div>
                            <div class="bg-white/20 rounded-lg p-3">
                                <p class="text-xs opacity-90 mb-1">Total Funded</p>
                                <p class="font-bold text-sm">₦0.00</p>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div class="bg-white rounded-2xl shadow-md border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">📊</span>
                            This Month
                        </h3>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                <span class="text-sm text-gray-600">Transactions</span>
                                <span class="font-bold text-gray-900">0</span>
                            </div>
                            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                <span class="text-sm text-gray-600">Total Spent</span>
                                <span class="font-bold text-green-600">₦0.00</span>
                            </div>
                            <div class="flex items-center justify-between py-2">
                                <span class="text-sm text-gray-600">Avg. Transaction</span>
                                <span class="font-bold text-gray-900">₦0.00</span>
                            </div>
                        </div>
                    </div>

                    <!-- Export Button -->
                    <button class="w-full bg-white hover:bg-gray-50 border border-gray-200 text-gray-700 font-semibold px-4 py-3 rounded-xl shadow-sm hover:shadow-md transition-all duration-200 flex items-center justify-center space-x-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span>Export History</span>
                    </button>
                </div>

                <!-- Main Content -->
                <div class="lg:col-span-3">
                    <!-- Filters Card -->
                    <div class="bg-white rounded-2xl shadow-md border border-gray-200/60 p-6 mb-6">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <!-- Search -->
                            <div class="md:col-span-2">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Search</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                        </svg>
                                    </div>
                                    <input type="text" id="searchInput" placeholder="Search by transaction ID, description..." 
                                           class="block w-full pl-10 pr-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200">
                                </div>
                            </div>

                            <!-- Transaction Type Filter -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Type</label>
                                <select id="typeFilter" class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200">
                                    <option value="all">All Types</option>
                                    <option value="credit">Credit</option>
                                    <option value="debit">Debit</option>
                                </select>
                            </div>

                            <!-- Status Filter -->
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Status</label>
                                <select id="statusFilter" class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200">
                                    <option value="all">All Status</option>
                                    <option value="success">Success</option>
                                    <option value="pending">Pending</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>
                        </div>

                        <!-- Date Range -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">From Date</label>
                                <input type="date" id="fromDate" 
                                       class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">To Date</label>
                                <input type="date" id="toDate" 
                                       class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200">
                            </div>
                            <div class="flex items-end">
                                <button id="filterBtn" class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-3 px-4 rounded-xl transition-all duration-200">
                                    Apply Filters
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Transactions Table -->
                    <div class="bg-white rounded-2xl shadow-md border border-gray-200/60 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Date & Time</th>
                                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Type</th>
                                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Description</th>
                                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Transaction ID</th>
                                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Amount</th>
                                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Balance</th>
                                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Status</th>
                                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200" id="transactionTableBody">
                                    <!-- Sample Transaction Rows -->
                                    <tr class="transaction-row hover:bg-gray-50 transition-colors duration-200" data-type="debit" data-status="success">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex flex-col">
                                                <span class="text-sm font-medium text-gray-900">Jan 09, 2026</span>
                                                <span class="text-xs text-gray-500">10:30 AM</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                                ↓ Debit
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <span class="text-xl mr-2">📱</span>
                                                <div>
                                                    <p class="text-sm font-medium text-gray-900">MTN Airtime Purchase</p>
                                                    <p class="text-xs text-gray-500">0801234****</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="text-sm font-mono text-gray-500">TXN123456789</span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="text-sm font-bold text-red-600">-₦500.00</span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="text-sm font-medium text-gray-900">₦9,500.00</span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                                ✓ Success
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <button class="text-green-600 hover:text-green-700 font-medium">
                                                View
                                            </button>
                                        </td>
                                    </tr>

                                    <tr class="transaction-row hover:bg-gray-50 transition-colors duration-200" data-type="credit" data-status="success">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex flex-col">
                                                <span class="text-sm font-medium text-gray-900">Jan 09, 2026</span>
                                                <span class="text-xs text-gray-500">09:15 AM</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                                ↑ Credit
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <span class="text-xl mr-2">💳</span>
                                                <div>
                                                    <p class="text-sm font-medium text-gray-900">Wallet Funding</p>
                                                    <p class="text-xs text-gray-500">Bank Transfer</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="text-sm font-mono text-gray-500">TXN987654321</span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="text-sm font-bold text-green-600">+₦10,000.00</span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="text-sm font-medium text-gray-900">₦10,000.00</span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                                ✓ Success
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <button class="text-green-600 hover:text-green-700 font-medium">
                                                View
                                            </button>
                                        </td>
                                    </tr>

                                    <tr class="transaction-row hover:bg-gray-50 transition-colors duration-200" data-type="debit" data-status="pending">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex flex-col">
                                                <span class="text-sm font-medium text-gray-900">Jan 08, 2026</span>
                                                <span class="text-xs text-gray-500">05:45 PM</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                                ↓ Debit
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <span class="text-xl mr-2">📺</span>
                                                <div>
                                                    <p class="text-sm font-medium text-gray-900">DStv Premium</p>
                                                    <p class="text-xs text-gray-500">1234567890</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="text-sm font-mono text-gray-500">TXN456789123</span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="text-sm font-bold text-red-600">-₦24,500.00</span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="text-sm font-medium text-gray-900">₦9,500.00</span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800">
                                                ⏳ Pending
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <button class="text-green-600 hover:text-green-700 font-medium">
                                                View
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Empty State (show when no transactions) -->
                                    <tr id="emptyState" class="hidden">
                                        <td colspan="8" class="px-6 py-16 text-center">
                                            <div class="flex flex-col items-center justify-center">
                                                <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                                                    <span class="text-4xl">📭</span>
                                                </div>
                                                <h3 class="text-lg font-semibold text-gray-900 mb-1">No transactions found</h3>
                                                <p class="text-gray-500 text-sm mb-4">Try adjusting your filters or make your first transaction</p>
                                                <a href="{{ route('airtime-data.index') }}" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold text-sm rounded-lg transition-all duration-200">
                                                    Start Transaction
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div class="bg-gray-50 px-6 py-4 flex items-center justify-between border-t border-gray-200">
                            <div class="flex-1 flex justify-between sm:hidden">
                                <button class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Previous
                                </button>
                                <button class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Next
                                </button>
                            </div>
                            <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm text-gray-700">
                                        Showing <span class="font-medium">1</span> to <span class="font-medium">3</span> of <span class="font-medium">3</span> results
                                    </p>
                                </div>
                                <div>
                                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                        <button class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                            </svg>
                                        </button>
                                        <button class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-green-50 text-sm font-medium text-green-600">
                                            1
                                        </button>
                                        <button class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/>
                                            </svg>
                                        </button>
                                    </nav>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const typeFilter = document.getElementById('typeFilter');
    const statusFilter = document.getElementById('statusFilter');
    const filterBtn = document.getElementById('filterBtn');
    const transactionRows = document.querySelectorAll('.transaction-row');
    const emptyState = document.getElementById('emptyState');

    function filterTransactions() {
        const searchTerm = searchInput.value.toLowerCase();
        const typeValue = typeFilter.value;
        const statusValue = statusFilter.value;
        let visibleCount = 0;

        transactionRows.forEach(row => {
            const rowText = row.textContent.toLowerCase();
            const rowType = row.getAttribute('data-type');
            const rowStatus = row.getAttribute('data-status');

            const matchesSearch = searchTerm === '' || rowText.includes(searchTerm);
            const matchesType = typeValue === 'all' || rowType === typeValue;
            const matchesStatus = statusValue === 'all' || rowStatus === statusValue;

            if (matchesSearch && matchesType && matchesStatus) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // Show/hide empty state
        if (visibleCount === 0) {
            emptyState.classList.remove('hidden');
        } else {
            emptyState.classList.add('hidden');
        }
    }

    // Event listeners
    searchInput.addEventListener('input', filterTransactions);
    typeFilter.addEventListener('change', filterTransactions);
    statusFilter.addEventListener('change', filterTransactions);
    filterBtn.addEventListener('click', filterTransactions);
});
</script>
@endsection