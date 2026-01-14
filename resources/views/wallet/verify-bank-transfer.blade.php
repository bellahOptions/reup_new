@extends('layouts.app')
@section('content')
<div class="min-h-screen bg-gray-50 py-12">
    <div class="max-w-3xl mx-auto px-4">
        <div class="bg-white rounded-2xl shadow-lg p-8">
            <div class="text-center mb-8">
                <div class="w-20 h-20 bg-blue-100 rounded-full mx-auto mb-4 flex items-center justify-center">
                    <span class="text-3xl">📤</span>
                </div>
                <h1 class="text-2xl font-bold text-gray-900">Upload Payment Proof</h1>
                <p class="text-gray-600 mt-2">Submit evidence of your bank transfer for verification</p>
            </div>
            
            <!-- Transaction Details -->
            <div class="bg-gray-50 rounded-xl p-6 mb-6">
                <h2 class="font-bold text-gray-900 mb-4">Transaction Details</h2>
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Reference:</span>
                        <span class="font-mono font-semibold">{{ $transaction->reference }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Amount:</span>
                        <span class="font-bold text-green-600">₦{{ number_format($transaction->amount, 2) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Date:</span>
                        <span>{{ $transaction->created_at->format('F d, Y h:i A') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Status:</span>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium 
                            {{ $transaction->status === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                               $transaction->status === 'verifying' ? 'bg-blue-100 text-blue-800' : 
                               'bg-green-100 text-green-800' }}">
                            {{ ucfirst($transaction->status) }}
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Bank Details Reminder -->
            <div class="bg-blue-50 rounded-xl p-6 mb-6">
                <h3 class="font-bold text-blue-900 mb-3">Bank Transfer Details</h3>
                <div class="space-y-2 text-sm">
                    <p><span class="font-medium">Account Name:</span> {{ $bank['account_name'] }}</p>
                    <p><span class="font-medium">Account Number:</span> {{ $bank['account_number'] }}</p>
                    <p><span class="font-medium">Bank:</span> {{ $bank['bank_name'] }}</p>
                    <p><span class="font-medium">Narration:</span> <code class="bg-blue-100 px-2 py-1 rounded">{{ $narration }}</code></p>
                </div>
            </div>
            
            <!-- Upload Form -->
            <div class="border border-gray-200 rounded-xl p-6">
                <form action="{{ route('wallet.bank-transfer.submit-proof') }}" method="POST" enctype="multipart/form-data" id="uploadForm">
    @csrf
    <!-- Fixed: Changed from transaction_id to transaction_reference -->
    <input type="hidden" name="transaction_reference" value="{{ $transaction->reference }}">
    
    <!-- File Upload -->
    <div class="mb-6">
        <label class="block text-sm font-medium text-gray-700 mb-2">
            Upload Proof of Payment
        </label>
        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-xl hover:border-blue-400 transition-colors">
            <div class="space-y-1 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <div class="flex text-sm text-gray-600">
                    <label for="proof" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500 focus-within:outline-none">
                        <span>Upload a file</span>
                        <input id="proof" name="proof" type="file" class="sr-only" accept=".jpg,.jpeg,.png,.pdf" required>
                    </label>
                    <p class="pl-1">or drag and drop</p>
                </div>
                <p class="text-xs text-gray-500">
                    PNG, JPG, PDF up to 5MB
                </p>
                <p id="fileName" class="text-sm font-medium text-green-600 mt-2"></p>
            </div>
        </div>
        @error('proof')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>
    
    <!-- Remarks -->
    <div class="mb-6">
        <label for="remarks" class="block text-sm font-medium text-gray-700 mb-2">
            Additional Remarks (Optional)
        </label>
        <textarea id="remarks" name="remarks" rows="3" 
                  class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  placeholder="Any additional information about your transfer..."></textarea>
    </div>
    
    <!-- Submit Button -->
    <button type="submit" 
            class="w-full bg-green-500 hover:bg-green-600 text-white font-medium py-3 px-4 rounded-lg transition-all duration-200 flex items-center justify-center space-x-2">
        <span id="submitText">Submit for Verification</span>
        <span id="submitIcon">📤</span>
    </button>
</form>
            </div>
        </div>
    </div>
</div>

<script>
// File upload preview
document.getElementById('proof').addEventListener('change', function(e) {
    const fileName = e.target.files[0]?.name;
    if (fileName) {
        document.getElementById('fileName').textContent = `Selected: ${fileName}`;
    }
});

</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('uploadForm');
    const proofInput = document.getElementById('proof');
    const fileName = document.getElementById('fileName');
    const submitBtn = form.querySelector('button[type="submit"]');
    const submitText = document.getElementById('submitText');
    const submitIcon = document.getElementById('submitIcon');
    
    // File selection handler
    proofInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            fileName.textContent = `Selected: ${file.name} (${formatBytes(file.size)})`;
            
            // Validate file size
            const maxSize = 5 * 1024 * 1024; // 5MB
            if (file.size > maxSize) {
                fileName.innerHTML = `<span class="text-red-600">File too large! Max 5MB</span>`;
                proofInput.value = ''; // Clear the input
            }
        }
    });
    
    // Form submission handler
    form.addEventListener('submit', function(e) {
        // Show loading state
        submitBtn.disabled = true;
        submitText.textContent = 'Uploading...';
        submitIcon.textContent = '⏳';
        
        // You can add additional validation here
        const file = proofInput.files[0];
        if (!file) {
            e.preventDefault();
            alert('Please select a file to upload.');
            resetButtonState();
            return;
        }
        
        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
        if (!allowedTypes.includes(file.type)) {
            e.preventDefault();
            alert('Invalid file type. Please upload JPG, PNG, or PDF files only.');
            resetButtonState();
            return;
        }
        
        // Validate file size
        const maxSize = 5 * 1024 * 1024; // 5MB
        if (file.size > maxSize) {
            e.preventDefault();
            alert('File is too large. Maximum size is 5MB.');
            resetButtonState();
            return;
        }
    });
    
    function resetButtonState() {
        submitBtn.disabled = false;
        submitText.textContent = 'Submit for Verification';
        submitIcon.textContent = '📤';
    }
    
    function formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }
});
</script>
@endsection