
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Transaction Details</h1>
            <p class="text-gray-600 mt-1">Reference: {{ $transaction->reference }}</p>
        </div>
        <div class="flex items-center space-x-3">
            @if($transaction->status === 'pending')
                <button onclick="updateTransactionStatus({{ $transaction->id }}, 'success')" 
                        class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition-colors">
                    Mark as Success
                </button>
                <button onclick="updateTransactionStatus({{ $transaction->id }}, 'failed')" 
                        class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition-colors">
                    Mark as Failed
                </button>
            @endif
            <button onclick="closeTransactionModal()" 
                    class="px-4 py-2 border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium rounded-lg transition-colors">
                Close
            </button>
        </div>
    </div>

    <!-- Status Badge -->
    <div class="flex items-center">
        @if($transaction->status === 'pending')
            <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-semibold bg-yellow-100 text-yellow-800">
                <span class="w-2 h-2 bg-yellow-500 rounded-full mr-2"></span>
                Pending
            </span>
        @elseif($transaction->status === 'processing')
            <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-semibold bg-blue-100 text-blue-800">
                <svg class="w-3 h-3 mr-2 animate-spin" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/>
                </svg>
                Processing
            </span>
        @elseif($transaction->status === 'success')
            <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-semibold bg-green-100 text-green-800">
                <svg class="w-3 h-3 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                </svg>
                Success
            </span>
        @elseif($transaction->status === 'failed')
            <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-semibold bg-red-100 text-red-800">
                <svg class="w-3 h-3 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
                Failed
            </span>
        @else
            <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-semibold bg-gray-100 text-gray-800">
                Cancelled
            </span>
        @endif
        @if($transaction->status_message)
        <p class="ml-3 text-sm text-gray-600">{{ $transaction->status_message }}</p>
        @endif
    </div>

    <!-- Main Details -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Transaction Details -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Transaction Information</h3>
            <div class="space-y-4">
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-600">Transaction ID</span>
                    <span class="font-mono text-sm font-bold text-gray-900">#{{ $transaction->id }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-600">Reference</span>
                    <span class="font-mono text-sm font-bold text-gray-900">{{ $transaction->reference }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-600">Service Type</span>
                    <span class="text-sm font-bold text-gray-900">{{ ucwords(str_replace('-', ' ', $transaction->service_type)) }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-600">Type</span>
                    <span class="text-sm font-bold text-gray-900">{{ ucfirst($transaction->type) }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-600">Description</span>
                    <span class="text-sm text-gray-900 text-right">{{ $transaction->description }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-600">Payment Method</span>
                    <span class="text-sm font-bold text-gray-900">{{ ucfirst(str_replace('_', ' ', $transaction->payment_method)) }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-600">Provider</span>
                    <span class="text-sm font-bold text-gray-900">{{ $transaction->provider }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-600">Created</span>
                    <span class="text-sm text-gray-900">{{ $transaction->created_at->format('M d, Y h:i A') }}</span>
                </div>
                @if($transaction->completed_at)
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-600">Completed</span>
                    <span class="text-sm text-gray-900">{{ $transaction->completed_at->format('M d, Y h:i A') }}</span>
                </div>
                @endif
            </div>
        </div>

        <!-- Financial Details -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Financial Information</h3>
            <div class="space-y-4">
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-600">Amount</span>
                    <span class="text-xl font-bold text-gray-900">₦{{ number_format($transaction->amount, 2) }}</span>
                </div>
                @if($transaction->service_fee > 0)
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-600">Service Fee</span>
                    <span class="text-sm font-bold text-gray-900">₦{{ number_format($transaction->service_fee, 2) }}</span>
                </div>
                @endif
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-600">Total Amount</span>
                    <span class="text-lg font-bold text-gray-900">₦{{ number_format($transaction->total_amount, 2) }}</span>
                </div>
                @if($transaction->balance_before)
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-600">Balance Before</span>
                    <span class="text-sm font-bold text-gray-900">₦{{ number_format($transaction->balance_before, 2) }}</span>
                </div>
                @endif
                @if($transaction->balance_after)
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-600">Balance After</span>
                    <span class="text-sm font-bold text-gray-900">₦{{ number_format($transaction->balance_after, 2) }}</span>
                </div>
                @endif
                <div class="pt-4 border-t border-gray-200">
                    <h4 class="text-sm font-medium text-gray-900 mb-2">Payment Status</h4>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Status</span>
                        <span class="text-sm font-bold {{ $transaction->payment_status === 'success' ? 'text-green-600' : 'text-red-600' }}">
                            {{ ucfirst($transaction->payment_status) }}
                        </span>
                    </div>
                    @if($transaction->paid_at)
                    <div class="flex justify-between items-center mt-2">
                        <span class="text-sm text-gray-600">Paid At</span>
                        <span class="text-sm text-gray-900">{{ $transaction->paid_at->format('M d, Y h:i A') }}</span>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- User Information -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 lg:col-span-2">
            <h3 class="text-lg font-bold text-gray-900 mb-4">User Information</h3>
            <div class="flex items-center space-x-4">
                @if($transaction->user)
                <div class="w-16 h-16 bg-gradient-to-br from-blue-400 to-blue-600 rounded-full flex items-center justify-center text-white font-bold text-xl">
                    {{ substr($transaction->user->name, 0, 1) }}
                </div>
                <div>
                    <h4 class="text-lg font-bold text-gray-900">{{ $transaction->user->name }}</h4>
                    <p class="text-sm text-gray-600">{{ $transaction->user->email }}</p>
                    <p class="text-sm text-gray-600 mt-1">User ID: {{ $transaction->user->id }}</p>
                    @if($transaction->user->phone)
                    <p class="text-sm text-gray-600 mt-1">Phone: {{ $transaction->user->phone }}</p>
                    @endif
                </div>
                @else
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                </div>
                <div>
                    <h4 class="text-lg font-bold text-gray-900">User Deleted</h4>
                    <p class="text-sm text-gray-600">User account no longer exists</p>
                </div>
                @endif
            </div>
        </div>

        <!-- Additional Information -->
        @if($transaction->recipient || $transaction->meta)
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 lg:col-span-2">
            <h3 class="text-lg font-bold text-gray-900 mb-4">Additional Information</h3>
            <div class="space-y-4">
                @if($transaction->recipient)
                <div>
                    <h4 class="text-sm font-medium text-gray-900 mb-1">Recipient</h4>
                    <p class="text-sm text-gray-600">{{ $transaction->recipient }}</p>
                </div>
                @endif
                
                @if($transaction->api_reference)
                <div>
                    <h4 class="text-sm font-medium text-gray-900 mb-1">API Reference</h4>
                    <p class="font-mono text-sm text-gray-600">{{ $transaction->api_reference }}</p>
                </div>
                @endif
                
                @if($transaction->meta)
                <div>
                    <h4 class="text-sm font-medium text-gray-900 mb-2">Metadata</h4>
                    <div class="bg-gray-50 rounded-lg p-4">
                        {{-- Already an array: Transactions casts meta to 'array', so
                             json_decode() here threw a TypeError. --}}
                        <pre class="text-xs text-gray-700 whitespace-pre-wrap">{{ json_encode($transaction->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                </div>
                @endif
                
                @if($transaction->api_response)
                <div>
                    <h4 class="text-sm font-medium text-gray-900 mb-2">API Response</h4>
                    <div class="bg-gray-50 rounded-lg p-4 max-h-40 overflow-y-auto">
                        {{-- `api_response` is cast to 'array'. Echoing it directly made
                             Blade call htmlspecialchars() on an array, which threw a
                             TypeError — and only for transactions that actually had a
                             gateway response, so it looked intermittent. --}}
                        <pre class="text-xs text-gray-700 whitespace-pre-wrap">{{ json_encode($transaction->api_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                </div>
                @endif
            </div>
        </div>
        @endif
    </div>

    <!-- Action Buttons -->
    <div class="flex items-center justify-end space-x-3 pt-6 border-t border-gray-200">
        @if($transaction->status === 'pending')
        <button onclick="forceSuccess({{ $transaction->id }})" 
                class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition-colors">
            Force Success
        </button>
        <button onclick="forceFailed({{ $transaction->id }})" 
                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition-colors">
            Force Failed
        </button>
        <button onclick="cancelTransaction({{ $transaction->id }})" 
                class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white font-medium rounded-lg transition-colors">
            Cancel Transaction
        </button>
        @endif
        <button onclick="closeTransactionModal()" 
                class="px-4 py-2 border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium rounded-lg transition-colors">
            Close
        </button>
    </div>
</div>

@push('scripts')
<script>
function updateTransactionStatus(transactionId, status) {
    if (!confirm(`Are you sure you want to mark this transaction as ${status}?`)) return;
    
    fetch(`/admin/transactions/${transactionId}/status`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
            status: status,
            status_message: `Status changed to ${status} by admin`
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Transaction status updated successfully!');
            viewTransactionDetails(transactionId); // Reload the modal
        } else {
            alert('Failed to update status: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to update transaction status');
    });
}

function forceSuccess(transactionId) {
    if (!confirm('Are you sure you want to force this transaction as successful? This will credit the user\'s wallet if applicable.')) return;
    
    fetch(`/admin/transactions/${transactionId}/force-success`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Transaction marked as successful!');
            viewTransactionDetails(transactionId); // Reload the modal
        } else {
            alert('Failed: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to update transaction');
    });
}

function forceFailed(transactionId) {
    if (!confirm('Are you sure you want to force this transaction as failed?')) return;
    
    fetch(`/admin/transactions/${transactionId}/force-failed`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Transaction marked as failed!');
            viewTransactionDetails(transactionId); // Reload the modal
        } else {
            alert('Failed: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to update transaction');
    });
}

function cancelTransaction(transactionId) {
    if (!confirm('Are you sure you want to cancel this transaction? If payment was successful, user will be refunded.')) return;
    
    fetch(`/admin/transactions/${transactionId}/cancel`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Transaction cancelled successfully!');
            viewTransactionDetails(transactionId); // Reload the modal
        } else {
            alert('Failed: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to cancel transaction');
    });
}
</script>
@endpush