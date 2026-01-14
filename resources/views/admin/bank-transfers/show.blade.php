{{-- This file is loaded dynamically into a modal --}}
<div class="space-y-6" id="bankTransferDetails">
    <!-- Transfer Information -->
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h4 class="text-lg font-bold text-gray-900">Transfer Details</h4>
                <p class="text-sm text-gray-600">Reference: <span class="font-mono">{{ $transfer->reference }}</span></p>
            </div>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold 
                @if($transfer->status === 'success') bg-green-100 text-green-800
                @elseif($transfer->status === 'pending') bg-yellow-100 text-yellow-800 animate-pulse
                @elseif($transfer->status === 'failed') bg-red-100 text-red-800
                @else bg-gray-100 text-gray-800 @endif">
                {{ strtoupper($transfer->status) }}
            </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div>
                <p class="text-sm text-gray-600 mb-1">Amount</p>
                <p class="text-2xl font-bold text-green-600">₦{{ number_format($transfer->amount, 2) }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-600 mb-1">Date</p>
                <p class="text-gray-900">{{ $transfer->created_at->format('M d, Y \a\t h:i A') }}</p>
            </div>
            <div class="md:col-span-2">
                <p class="text-sm text-gray-600 mb-1">Description</p>
                <p class="text-gray-900">{{ $transfer->description }}</p>
            </div>
        </div>

        <!-- User Info -->
        <div class="border-t border-gray-200 pt-4">
            <p class="text-sm font-semibold text-gray-700 mb-2">User Information</p>
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-gradient-to-br from-blue-400 to-cyan-500 rounded-full flex items-center justify-center text-white font-bold">
                    {{ substr($transfer->user->name, 0, 1) }}
                </div>
                <div>
                    <p class="font-medium text-gray-900">{{ $transfer->user->name }}</p>
                    <p class="text-sm text-gray-600">{{ $transfer->user->email }}</p>
                    <p class="text-xs text-gray-500 mt-1">
                        Wallet Balance: ₦{{ number_format($transfer->user->wallet->balance ?? 0, 2) }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Bank Details -->
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h4 class="text-lg font-bold text-gray-900 mb-4">Bank Details</h4>
        @php
            $bankDetails = config('wallet.bank');
            $meta = json_decode($transfer->meta, true);
        @endphp
        <div class="bg-blue-50 rounded-xl p-4">
            <div class="space-y-3">
                <div>
                    <p class="text-xs font-medium text-blue-900 mb-1">Account Name</p>
                    <p class="font-bold text-blue-900">{{ $bankDetails['account_name'] ?? 'Your Business' }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-blue-900 mb-1">Account Number</p>
                    <p class="font-bold text-blue-900">{{ $bankDetails['account_number'] ?? '0123456789' }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-blue-900 mb-1">Bank Name</p>
                    <p class="font-bold text-blue-900">{{ $bankDetails['bank_name'] ?? 'Access Bank' }}</p>
                </div>
                @if(isset($meta['narration']))
                <div class="bg-green-50 rounded-lg p-3 mt-2">
                    <p class="text-xs font-medium text-green-900 mb-1">Payment Narration</p>
                    <p class="font-bold text-green-900 text-sm">{{ $meta['narration'] }}</p>
                </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Proof of Payment -->
    @php
        $proofPath = $meta['proof_path'] ?? null;
        $proofUrl = $proofPath ? Storage::url($proofPath) : null;
    @endphp
    
    @if($proofUrl)
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
            <h4 class="text-lg font-bold text-gray-900">Proof of Payment</h4>
            <div class="flex items-center space-x-2">
                <a href="{{ route('admin.bank-transfers.proof.download', $transfer->id) }}" 
                   class="text-sm text-blue-600 hover:text-blue-700 font-medium">
                    Download
                </a>
            </div>
        </div>
        
        @php
            $extension = pathinfo($proofUrl, PATHINFO_EXTENSION);
            $isImage = in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'webp']);
            $isPdf = strtolower($extension) === 'pdf';
        @endphp
        
        @if($isImage)
            <div class="border border-gray-200 rounded-lg overflow-hidden">
                <img src="{{ $proofUrl }}" 
                     alt="Payment Proof" 
                     class="w-full h-auto max-h-64 object-contain bg-gray-50">
            </div>
        @elseif($isPdf)
            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                <div class="flex items-center">
                    <svg class="w-8 h-8 text-red-500 mr-3" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
                    </svg>
                    <div>
                        <p class="font-medium text-gray-900">PDF Document</p>
                        <p class="text-sm text-gray-600">Click download to view</p>
                    </div>
                </div>
            </div>
        @else
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <p class="text-gray-700">File uploaded: {{ basename($proofUrl) }}</p>
            </div>
        @endif
    </div>
    @endif

    <!-- Action Buttons (Only for Pending Transfers) -->
    @if($transfer->status === 'pending')
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h4 class="text-lg font-bold text-gray-900 mb-4">Actions</h4>
        
        <div class="space-y-4">
            <!-- Approve Form -->
            <form id="approveForm" action="{{ route('admin.bank-transfers.approve', $transfer->id) }}" method="POST" class="space-y-3">
                @csrf
                <div>
                    <label for="approveRemarks" class="block text-sm font-medium text-gray-700 mb-1">Approval Remarks (Optional)</label>
                    <textarea id="approveRemarks" name="remarks" rows="2"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent text-sm"
                              placeholder="Add remarks..."></textarea>
                </div>
                <button type="submit" 
                        onclick="return confirm('Approve this transfer and credit user wallet?')"
                        class="w-full bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-semibold py-3 rounded-lg transition-all duration-200 flex items-center justify-center space-x-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    <span>Approve Transfer</span>
                </button>
            </form>

            <!-- Reject Form -->
            <form id="rejectForm" action="{{ route('admin.bank-transfers.reject', $transfer->id) }}" method="POST" class="space-y-3 border-t border-gray-200 pt-4">
                @csrf
                <div>
                    <label for="rejectReason" class="block text-sm font-medium text-gray-700 mb-1">Rejection Reason *</label>
                    <textarea id="rejectReason" name="reason" rows="2" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent text-sm"
                              placeholder="Provide reason for rejection..."></textarea>
                </div>
                <div>
                    <label class="flex items-center">
                        <input type="checkbox" name="refund_pending_balance" value="1" 
                               class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                        <span class="ml-2 text-sm text-gray-700">Refund pending balance</span>
                    </label>
                </div>
                <button type="submit" 
                        onclick="return confirm('Reject this transfer?')"
                        class="w-full bg-gradient-to-r from-red-500 to-pink-600 hover:from-red-600 hover:to-pink-700 text-white font-semibold py-3 rounded-lg transition-all duration-200 flex items-center justify-center space-x-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    <span>Reject Transfer</span>
                </button>
            </form>

            <!-- Fraudulent Form -->
            <form id="fraudForm" action="{{ route('admin.bank-transfers.mark-fraudulent', $transfer->id) }}" method="POST" class="space-y-3 border-t border-gray-200 pt-4">
                @csrf
                <div>
                    <label for="fraudReason" class="block text-sm font-medium text-gray-700 mb-1">Fraud Reason *</label>
                    <textarea id="fraudReason" name="fraud_reason" rows="2" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent text-sm"
                              placeholder="Explain fraud suspicion..."></textarea>
                </div>
                <div class="space-y-2">
                    <label class="flex items-center">
                        <input type="checkbox" name="suspend_user" value="1" 
                               class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                        <span class="ml-2 text-sm text-gray-700">Suspend user account</span>
                    </label>
                    <label class="flex items-center">
                        <input type="checkbox" name="block_user" value="1" 
                               class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                        <span class="ml-2 text-sm text-gray-700">Block user permanently</span>
                    </label>
                </div>
                <button type="submit" 
                        onclick="return confirm('⚠️ WARNING: Mark as fraudulent and take action against user?')"
                        class="w-full bg-gradient-to-r from-red-700 to-red-900 hover:from-red-800 hover:to-red-900 text-white font-semibold py-3 rounded-lg transition-all duration-200 flex items-center justify-center space-x-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.998-.833-2.732 0L4.732 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <span>Mark as Fraudulent</span>
                </button>
            </form>
        </div>
    </div>
    @else
    <!-- Status Information for Non-Pending -->
    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h4 class="text-lg font-bold text-gray-900 mb-4">Status Information</h4>
        <div class="bg-gray-50 rounded-xl p-4">
            <p class="text-gray-700">
                This transfer has been 
                <span class="font-semibold">{{ $transfer->status }}</span>.
            </p>
            @if($transfer->status_message)
                <p class="text-sm text-gray-600 mt-2">{{ $transfer->status_message }}</p>
            @endif
            
            @if(isset($meta['approved_by']) || isset($meta['rejected_by']) || isset($meta['fraud_marked_by']))
                <div class="mt-3 pt-3 border-t border-gray-200">
                    <p class="text-sm font-medium text-gray-700 mb-2">Action History:</p>
                    @if(isset($meta['approved_by']))
                        <p class="text-sm text-green-700 flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            Approved by Admin #{{ $meta['approved_by'] }}
                        </p>
                    @endif
                    @if(isset($meta['rejected_by']))
                        <p class="text-sm text-red-700 flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                            </svg>
                            Rejected by Admin #{{ $meta['rejected_by'] }}
                        </p>
                    @endif
                    @if(isset($meta['fraud_marked_by']))
                        <p class="text-sm text-purple-700 flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            Marked fraudulent by Admin #{{ $meta['fraud_marked_by'] }}
                        </p>
                    @endif
                </div>
            @endif
        </div>
    </div>
    @endif
</div>

<script>
// Handle form submissions with AJAX
document.addEventListener('DOMContentLoaded', function() {
    // Handle approve form
    document.getElementById('approveForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        submitForm(this, 'Approving transfer...');
    });

    // Handle reject form
    document.getElementById('rejectForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        submitForm(this, 'Rejecting transfer...');
    });

    // Handle fraud form
    document.getElementById('fraudForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        submitForm(this, 'Marking as fraudulent...');
    });
});

function submitForm(form, loadingText) {
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    // Show loading
    submitBtn.disabled = true;
    submitBtn.innerHTML = `
        <svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        <span>${loadingText}</span>
    `;

    // Submit form via AJAX
    fetch(form.action, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify(Object.fromEntries(new FormData(form)))
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            showToast('Success', data.message || 'Action completed successfully', 'success');
            
            // Close modal and refresh page after delay
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            throw new Error(data.message || 'Action failed');
        }
    })
    .catch(error => {
        showToast('Error', error.message, 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
}

function showToast(title, message, type) {
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `fixed top-4 right-4 z-50 px-6 py-4 rounded-lg shadow-lg border transform transition-all duration-300 ${
        type === 'success' ? 'bg-green-50 border-green-200 text-green-800' :
        type === 'error' ? 'bg-red-50 border-red-200 text-red-800' :
        'bg-blue-50 border-blue-200 text-blue-800'
    }`;
    
    toast.innerHTML = `
        <div class="flex items-center">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                ${type === 'success' ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>' :
                  type === 'error' ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>' :
                  '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'}
            </svg>
            <div>
                <p class="font-semibold">${title}</p>
                <p class="text-sm">${message}</p>
            </div>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    // Remove toast after 5 seconds
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-10px)';
        setTimeout(() => {
            document.body.removeChild(toast);
        }, 300);
    }, 5000);
}
</script>