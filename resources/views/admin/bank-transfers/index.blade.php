@extends('admin.layouts.app')

@section('title', 'Bank Transfers')
@section('page-title', 'Bank Transfer Management')

@section('content')
<div class="space-y-6">
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Pending</span>
                <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ $stats['pending'] ?? 0 }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Approved</span>
                <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ $stats['approved'] ?? 0 }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Rejected</span>
                <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ $stats['rejected'] ?? 0 }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-600">Fraudulent</span>
                <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
            </div>
            <h3 class="text-2xl font-bold text-gray-900">{{ $stats['fraudulent'] ?? 0 }}</h3>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <form method="GET" action="{{ route('admin.bank-transfers.index') }}" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <!-- In your filters section -->
<div>
    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
    <select name="status" class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
        <option value="">All Statuses</option>
        <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
        <option value="success" {{ request('status') == 'success' ? 'selected' : '' }}>Approved</option>
        <option value="failed" {{ request('status') == 'failed' ? 'selected' : '' }}>Rejected</option>
        <option value="fraudulent" {{ request('status') == 'fraudulent' ? 'selected' : '' }}>Fraudulent</option>
    </select>
</div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="User, reference..." class="w-full border-gray-300 rounded-lg focus:ring-green-500 focus:border-green-500">
            </div>

            <div class="flex items-end space-x-2">
                <button type="submit" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded-lg transition-colors">
                    Filter
                </button>
                <a href="{{ route('admin.bank-transfers.index') }}" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold py-2 px-4 rounded-lg transition-colors">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Transfers List -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="p-6 border-b border-gray-200">
            <h3 class="text-lg font-bold text-gray-900">Bank Transfer Requests</h3>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">User</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Amount</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Reference</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Proof</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Status</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Date</th>
                        <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($transfers ?? [] as $transfer)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="py-4 px-6">
                            <div class="flex items-center">
                                <div class="w-10 h-10 bg-gradient-to-br from-green-400 to-emerald-500 rounded-full flex items-center justify-center text-white font-bold mr-3">
                                    {{ substr($transfer->user->name ?? 'U', 0, 1) }}
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-900">{{ $transfer->user->name ?? 'Unknown' }}</p>
                                    <p class="text-xs text-gray-600">{{ $transfer->user->email ?? 'N/A' }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="py-4 px-6">
                            <span class="text-lg font-bold text-gray-900">₦{{ number_format($transfer->amount, 2) }}</span>
                        </td>
                        <td class="py-4 px-6">
                            <span class="font-mono text-sm text-gray-900">{{ $transfer->reference }}</span>
                        </td>
                        <td class="py-4 px-6">
                            @if($transfer->proof_of_payment)
                                <button onclick="viewProof('{{ asset('storage/' . $transfer->proof_of_payment) }}')" class="text-green-600 hover:text-green-700 font-semibold text-sm">
                                    View Proof
                                </button>
                            @else
                                <span class="text-gray-400 text-sm">No proof</span>
                            @endif
                        </td>
                        <td class="py-4 px-6">
                            @if($transfer->status === 'pending')
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800">
                                    <span class="w-2 h-2 bg-yellow-500 rounded-full mr-2 animate-pulse"></span>
                                    Pending
                                </span>
                            @elseif($transfer->status === 'success')
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                    Approved
                                </span>
                            @elseif($transfer->status === 'failed')
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                    </svg>
                                    Failed
                                </span>
                            @else
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-800">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                    </svg>
                                    Fraudulent
                                </span>
                            @endif
                        </td>
                        <td class="py-4 px-6 text-sm text-gray-600">
                            {{ $transfer->created_at->format('M d, Y') }}<br>
                            <span class="text-xs text-gray-500">{{ $transfer->created_at->format('h:i A') }}</span>
                        </td>
                        <td class="py-4 px-6">
                            @if($transfer->status === 'pending')
                                <div class="flex items-center space-x-2">
                                    <button onclick="reviewTransfer({{ $transfer->id }})" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg text-sm font-semibold transition-colors">
                                        Review
                                    </button>
                                </div>
                            @else
                                <button onclick="viewDetails({{ $transfer->id }})" class="text-green-600 hover:text-green-700 font-semibold text-sm">
                                    View Details
                                </button>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="py-12 text-center">
                            <div class="flex flex-col items-center">
                                <svg class="w-16 h-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                <p class="text-gray-600 font-medium">No bank transfers found</p>
                                <p class="text-sm text-gray-500 mt-1">Transfers will appear here when users submit them</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(isset($transfers) && $transfers->hasPages())
        <div class="p-6 border-t border-gray-200">
            {{ $transfers->links() }}
        </div>
        @endif
    </div>
</div>

<!-- Review Modal -->
<div id="reviewModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4" x-data="{ open: false }">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-bold text-gray-900">Review Bank Transfer</h3>
                <button onclick="closeReviewModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>

        <div id="modalContent" class="p-6">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>

<!-- Proof of Payment Modal -->
<div id="proofModal" class="fixed inset-0 bg-black/80 z-50 hidden items-center justify-center p-4">
    <div class="max-w-4xl w-full">
        <div class="bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="p-4 bg-gray-900 flex items-center justify-between">
                <h3 class="text-white font-semibold">Proof of Payment</h3>
                <button onclick="closeProofModal()" class="text-gray-400 hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="p-4 bg-gray-100">
                <img id="proofImage" src="" alt="Proof of Payment" class="w-full h-auto rounded-lg shadow-lg">
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function viewProof(imageUrl) {
    document.getElementById('proofImage').src = imageUrl;
    document.getElementById('proofModal').classList.remove('hidden');
    document.getElementById('proofModal').classList.add('flex');
}

function closeProofModal() {
    document.getElementById('proofModal').classList.add('hidden');
    document.getElementById('proofModal').classList.remove('flex');
}

function reviewTransfer(transferId) {
    // Load transfer details via AJAX
    fetch(`{{route('admin.bank-transfers.show', '')}}/${transferId}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('modalContent').innerHTML = html;
            document.getElementById('reviewModal').classList.remove('hidden');
            document.getElementById('reviewModal').classList.add('flex');
        });
}

function closeReviewModal() {
    document.getElementById('reviewModal').classList.add('hidden');
    document.getElementById('reviewModal').classList.remove('flex');
}

function viewDetails(transferId) {
    // Show loading
    const modalContent = document.getElementById('modalContent');
    modalContent.innerHTML = `
        <div class="flex items-center justify-center py-12">
            <div class="text-center">
                <svg class="w-8 h-8 text-gray-400 animate-spin mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <p class="text-gray-600">Loading transfer details...</p>
            </div>
        </div>
    `;
    
    // Show modal
    document.getElementById('reviewModal').classList.remove('hidden');
    document.getElementById('reviewModal').classList.add('flex');
    
    // Load transfer details via AJAX
    fetch(`/admin/bank-transfers/${transferId}?modal=true`, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) throw new Error('Network response was not ok');
        return response.text();
    })
    .then(html => {
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
        console.error('Error loading transfer details:', error);
        modalContent.innerHTML = `
            <div class="flex items-center justify-center py-12">
                <div class="text-center">
                    <svg class="w-12 h-12 text-red-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.998-.833-2.732 0L4.732 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <p class="text-gray-900 font-medium mb-2">Error Loading Details</p>
                    <p class="text-gray-600 text-sm mb-4">${error.message}</p>
                    <button onclick="viewDetails(${transferId})" class="text-green-600 hover:text-green-700 font-medium">
                        Try Again →
                    </button>
                </div>
            </div>
        `;
    });
}

function reviewTransfer(transferId) {
    // Show loading
    const modalContent = document.getElementById('modalContent');
    modalContent.innerHTML = `
        <div class="flex items-center justify-center py-12">
            <div class="text-center">
                <svg class="w-8 h-8 text-gray-400 animate-spin mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                <p class="text-gray-600">Loading transfer details...</p>
            </div>
        </div>
    `;
    
    // Show modal
    document.getElementById('reviewModal').classList.remove('hidden');
    document.getElementById('reviewModal').classList.add('flex');
    
    // Load transfer details via AJAX
    fetch(`/admin/bank-transfers/${transferId}?modal=true`, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) throw new Error('Network response was not ok');
        return response.text();
    })
    .then(html => {
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
        console.error('Error loading transfer details:', error);
        modalContent.innerHTML = `
            <div class="flex items-center justify-center py-12">
                <div class="text-center">
                    <svg class="w-12 h-12 text-red-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.998-.833-2.732 0L4.732 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <p class="text-gray-900 font-medium mb-2">Error Loading Details</p>
                    <p class="text-gray-600 text-sm mb-4">${error.message}</p>
                    <button onclick="reviewTransfer(${transferId})" class="text-green-600 hover:text-green-700 font-medium">
                        Try Again →
                    </button>
                </div>
            </div>
        `;
    });
}

function closeReviewModal() {
    document.getElementById('reviewModal').classList.add('hidden');
    document.getElementById('reviewModal').classList.remove('flex');
    // Clear modal content when closed
    document.getElementById('modalContent').innerHTML = '';
}

// Close modal when clicking outside
document.getElementById('reviewModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeReviewModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('reviewModal').classList.contains('flex')) {
        closeReviewModal();
    }
});
</script>
@endpush