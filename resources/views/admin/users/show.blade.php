{{-- resources/views/admin/users/show.blade.php --}}
@extends('admin.layouts.app')

@section('title', 'User Details - ' . $user->name)
@section('page-title', 'User Profile')

@section('content')
<div class="space-y-6">
    <!-- Back Button & Actions -->
    <div class="flex items-center justify-between">
        <a href="{{ route('admin.users.index') }}" class="inline-flex items-center text-green-600 hover:text-green-700 font-semibold transition-colors">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to Users
        </a>
        
        <div class="flex items-center space-x-3">
            <a href="{{ route('admin.users.edit', $user->id) }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-semibold transition-colors">
                Edit Profile
            </a>
            @if(!$user->email_verified_at)
            <button onclick="verifyUser({{ $user->id }})" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-semibold transition-colors">
                Verify Email
            </button>
            @endif
        </div>
    </div>

    <!-- Main Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column - Profile Card -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Profile Info -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
                <!-- Header -->
                <div class="bg-gradient-to-r from-green-500 to-emerald-600 p-6 text-white text-center">
                    <div class="w-24 h-24 bg-white/20 backdrop-blur-sm rounded-full flex items-center justify-center text-white font-bold text-4xl mx-auto mb-4 border-4 border-white/30">
                        {{ substr($user->name ?? 'U', 0, 1) }}
                    </div>
                    <h2 class="text-2xl font-bold mb-1">{{ $user->name }}</h2>
                    <p class="text-green-100 text-sm">{{ $user->email }}</p>
                </div>

                <!-- Verification Status -->
                <div class="p-4 bg-gray-50 border-b border-gray-200">
                    @if($user->email_verified_at)
                        <div class="flex items-center justify-center space-x-2 text-green-600">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <span class="font-semibold">Email Verified</span>
                        </div>
                        <p class="text-xs text-gray-500 text-center mt-1">{{ $user->email_verified_at->format('M d, Y') }}</p>
                    @else
                        <div class="flex items-center justify-center space-x-2 text-yellow-600">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            <span class="font-semibold">Email Not Verified</span>
                        </div>
                    @endif
                </div>

                <!-- Details -->
                <div class="p-6 space-y-4">
                    <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                        <span class="text-sm text-gray-600 flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"/>
                            </svg>
                            User ID
                        </span>
                        <span class="text-sm font-semibold text-gray-900">#{{ $user->id }}</span>
                    </div>

                    <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                        <span class="text-sm text-gray-600 flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                            </svg>
                            Phone
                        </span>
                        <span class="text-sm font-semibold text-gray-900">{{ $user->phone ?? 'Not set' }}</span>
                    </div>

                    <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                        <span class="text-sm text-gray-600 flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                            WhatsApp
                        </span>
                        <span class="text-sm font-semibold text-gray-900">{{ $user->whatsapp ?? 'Not set' }}</span>
                    </div>

                    <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                        <span class="text-sm text-gray-600 flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            Birthday
                        </span>
                        <span class="text-sm font-semibold text-gray-900">
                            @if($user->birthday)
                                {{ $user->birthday->format('M d, Y') }} ({{ $user->age }} yrs)
                            @else
                                Not set
                            @endif
                        </span>
                    </div>

                    <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                        <span class="text-sm text-gray-600 flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                            Gender
                        </span>
                        <span class="text-sm font-semibold text-gray-900">{{ ucfirst($user->gender ?? 'Not set') }}</span>
                    </div>

                    <div class="flex items-center justify-between pb-3 border-b border-gray-100">
                        <span class="text-sm text-gray-600 flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Joined
                        </span>
                        <span class="text-sm font-semibold text-gray-900">{{ $user->created_at->format('M d, Y') }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600 flex items-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                            </svg>
                            Last Login
                        </span>
                        <span class="text-sm font-semibold text-gray-900">{{ $user->last_login_at ? $user->last_login_at->diffForHumans() : 'Never' }}</span>
                    </div>
                </div>

                <!-- Profile Completion -->
                <div class="p-6 bg-gray-50 border-t border-gray-200">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-sm font-semibold text-gray-700">Profile Completion</span>
                        <span class="text-sm font-bold text-green-600">{{ $user->profile_completion_percentage }}%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                        <div class="bg-gradient-to-r from-green-500 to-emerald-600 h-3 rounded-full transition-all duration-500" style="width: {{ $user->profile_completion_percentage }}%"></div>
                    </div>
                </div>
            </div>

            <!-- Address Info -->
            @if($user->address || $user->city || $user->state)
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    Address Information
                </h3>
                <div class="space-y-3 text-sm">
                    @if($user->address)
                        <p class="text-gray-900">{{ $user->address }}</p>
                    @endif
                    @if($user->city || $user->state)
                        <p class="text-gray-600">
                            {{ $user->city }}{{ $user->city && $user->state ? ', ' : '' }}{{ $user->state }}
                        </p>
                    @endif
                </div>
            </div>
            @endif
        </div>

        <!-- Right Column - Activity & Stats -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Wallet Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Current Balance -->
                <div class="bg-gradient-to-br from-green-500 to-emerald-600 rounded-2xl shadow-lg p-6 text-white">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-green-100 text-sm font-medium">Current Balance</span>
                        <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"/>
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"/>
                            </svg>
                        </div>
                    </div>
                    <h3 class="text-4xl font-bold mb-2">₦{{ number_format($user->wallet_balance ?? 0, 2) }}</h3>
                    <p class="text-green-100 text-sm">Available to spend</p>
                </div>

                <!-- Total Spent -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-gray-600 text-sm font-medium">Total Spent</span>
                        <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"/>
                            </svg>
                        </div>
                    </div>
                    <h3 class="text-3xl font-bold text-gray-900 mb-2">₦{{ number_format($user->wallet->total_spent ?? 0, 2) }}</h3>
                    <p class="text-gray-600 text-sm">Lifetime spending</p>
                </div>

                <!-- Total Funded -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-gray-600 text-sm font-medium">Total Funded</span>
                        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                            </svg>
                        </div>
                    </div>
                    <h3 class="text-3xl font-bold text-gray-900 mb-2">₦{{ number_format($user->wallet->total_funded ?? 0, 2) }}</h3>
                    <p class="text-gray-600 text-sm">Total deposits</p>
                </div>

                <!-- Transaction Count -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-gray-600 text-sm font-medium">Transactions</span>
                        <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                        </div>
                    </div>
                    <h3 class="text-3xl font-bold text-gray-900 mb-2">{{ $user->wallet->transaction_count ?? 0 }}</h3>
                    <p class="text-gray-600 text-sm">Total transactions</p>
                </div>
            </div>

            <!-- Recent Transactions -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="p-6 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-900">Recent Transactions</h3>
                    <a href="{{ route('admin.transactions.index', ['user_id' => $user->id]) }}" class="text-sm text-green-600 hover:text-green-700 font-semibold">
                        View All →
                    </a>
                </div>
                
                <div class="p-6">
                    <div class="space-y-4">
                        @forelse($user->transactions()->latest()->take(10)->get() as $transaction)
                        <div class="flex items-center justify-between py-3 px-4 bg-gray-50 hover:bg-gray-100 rounded-xl transition-colors">
                            <div class="flex items-center flex-1">
                                <div class="w-12 h-12 bg-{{ $transaction->type === 'credit' ? 'green' : 'red' }}-100 rounded-xl flex items-center justify-center mr-4 flex-shrink-0">
                                    @if($transaction->type === 'credit')
                                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                        </svg>
                                    @else
                                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
                                        </svg>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-gray-900 truncate">{{ ucfirst($transaction->service_type) }}</p>
                                    <p class="text-xs text-gray-600">{{ $transaction->reference }}</p>
                                    <p class="text-xs text-gray-500 mt-1">{{ $transaction->created_at->format('M d, Y • h:i A') }}</p>
                                </div>
                            </div>
                            <div class="text-right ml-4">
                                <p class="font-bold text-lg text-{{ $transaction->type === 'credit' ? 'green' : 'red' }}-600">
                                    {{ $transaction->type === 'credit' ? '+' : '-' }}₦{{ number_format($transaction->amount, 2) }}
                                </p>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold mt-1
                                    bg-{{ $transaction->status === 'success' ? 'green' : ($transaction->status === 'pending' ? 'yellow' : 'red') }}-100 
                                    text-{{ $transaction->status === 'success' ? 'green' : ($transaction->status === 'pending' ? 'yellow' : 'red') }}-800">
                                    {{ ucfirst($transaction->status) }}
                                </span>
                            </div>
                        </div>
                        @empty
                        <div class="text-center py-12">
                            <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            <p class="text-gray-600 font-medium">No transactions yet</p>
                            <p class="text-sm text-gray-500 mt-1">User hasn't made any transactions</p>
                        </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- Activity Log (Optional) -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                    </svg>
                    Quick Stats
                </h3>
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-xl p-4 border border-green-200">
                        <p class="text-sm text-green-700 mb-1">Total Airtime</p>
                        <p class="text-2xl font-bold text-green-900">
                            {{ $user->transactions()->where('service_type', 'airtime')->count() }}
                        </p>
                    </div>
                    <div class="bg-gradient-to-br from-blue-50 to-cyan-50 rounded-xl p-4 border border-blue-200">
                        <p class="text-sm text-blue-700 mb-1">Total Data</p>
                        <p class="text-2xl font-bold text-blue-900">
                            {{ $user->transactions()->where('service_type', 'data')->count() }}
                        </p>
                    </div>
                    <div class="bg-gradient-to-br from-purple-50 to-pink-50 rounded-xl p-4 border border-purple-200">
                        <p class="text-sm text-purple-700 mb-1">Wallet Fundings</p>
                        <p class="text-2xl font-bold text-purple-900">
                            {{ $user->transactions()->where('service_type', 'funding')->count() }}
                        </p>
                    </div>
                    <div class="bg-gradient-to-br from-orange-50 to-red-50 rounded-xl p-4 border border-orange-200">
                        <p class="text-sm text-orange-700 mb-1">Success Rate</p>
                        <p class="text-2xl font-bold text-orange-900">
                            {{ $user->transactions()->count() > 0 ? round(($user->transactions()->where('status', 'success')->count() / $user->transactions()->count()) * 100, 1) : 0 }}%
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function verifyUser(userId) {
    if (confirm('Are you sure you want to manually verify this user\'s email?')) {
        fetch(`/admin/users/${userId}/verify`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            alert('An error occurred. Please try again.');
            console.error('Error:', error);
        });
    }
}
</script>
@endpush