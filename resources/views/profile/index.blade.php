@extends('layouts.app')
@section('content')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8 md:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            
            <!-- Phone Number Alert -->
            @if(auth()->user()->requires_phone_update)
            <div class="mb-6 bg-gradient-to-r from-red-50 to-pink-50 border-l-4 border-red-500 p-4 rounded-r-xl animate-pulse">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <span class="text-red-500 text-xl">📱</span>
                    </div>
                    <div class="ml-3 flex-1">
                        <p class="text-red-800 font-medium">Phone Number Required!</p>
                        <p class="text-red-700 text-sm mt-1">
                            Please add your phone number to enjoy seamless airtime and data purchases. 
                            This helps with transaction verification and notifications.
                        </p>
                    </div>
                </div>
            </div>
            @endif

            <!-- Session Messages -->
            @if(session('success'))
                <div class="mb-6 bg-gradient-to-r from-green-50 to-emerald-50 border-l-4 border-green-500 p-4 rounded-r-xl">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <span class="text-green-500 text-xl">✅</span>
                        </div>
                        <div class="ml-3">
                            <p class="text-green-800">{{ session('success') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Page Header -->
            <div class="mb-8 md:mb-12">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-bold text-gray-900">Profile Settings</h1>
                        <p class="text-gray-600 text-sm md:text-base mt-1">Manage your personal information and preferences</p>
                    </div>
                    <div class="flex items-center space-x-2">
                        <!-- Profile Completion Badge -->
                        <div class="bg-gradient-to-r from-green-500 to-emerald-600 text-white px-4 py-2 rounded-xl font-semibold text-sm">
                            {{ auth()->user()->profile_completion_percentage }}% Complete
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">
                <!-- Left Column - Profile Info & Picture -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Profile Picture Card -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6">
                        <div class="flex items-center space-x-6">
                            <div class="relative">
                                <div class="w-24 h-24 md:w-32 md:h-32 rounded-2xl overflow-hidden border-4 border-green-100">
                                    @if(auth()->user()->profile_picture)
                                        <img src="{{ auth()->user()->profile_picture }}" 
                                             alt="Profile Picture" 
                                             class="w-full h-full object-cover">
                                    @else
                                        <div class="w-full h-full bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center">
                                            <span class="text-white text-4xl md:text-5xl font-bold">
                                                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                                            </span>
                                        </div>
                                    @endif
                                </div>
                                <label for="profile_picture" 
                                       class="absolute bottom-0 right-0 bg-green-500 text-white p-2 rounded-full cursor-pointer hover:bg-green-600 transition-all duration-200">
                                    <span class="text-sm">📷</span>
                                    <input type="file" id="profile_picture" name="profile_picture" class="hidden" accept="image/*" form="profileForm">
                                </label>
                            </div>
                            
                            <div class="flex-1">
                                <h2 class="text-xl font-bold text-gray-900">{{ auth()->user()->name }}</h2>
                                <p class="text-gray-600 text-sm mt-1">{{ auth()->user()->email }}</p>
                                
                                <!-- Member Since -->
                                <div class="mt-4 flex items-center space-x-2 text-sm text-gray-500">
                                    <span>👤</span>
                                    <span>Member since {{ auth()->user()->created_at->format('M Y') }}</span>
                                </div>
                                
                                <!-- Verification Status -->
                                <div class="mt-3 flex items-center space-x-3">
                                    <div class="flex items-center">
                                        <span class="{{ auth()->user()->email_verified_at ? 'text-green-500' : 'text-gray-300' }} mr-1">
                                            {{ auth()->user()->email_verified_at ? '✅' : '⭕' }}
                                        </span>
                                        <span class="text-xs">Email Verified</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Form Card -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6">
                        <h3 class="text-xl font-bold text-gray-900 mb-6 flex items-center">
                            <span class="text-green-500 mr-2">👤</span>
                            Personal Information
                        </h3>
                        
                        <form id="profileForm" method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="space-y-6">
                            @csrf
                            @method('PUT')

                            <!-- Name & Email -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="name" class="block text-sm font-semibold text-gray-700 mb-2">Full Name *</label>
                                    <input type="text" 
                                           id="name" 
                                           name="name" 
                                           value="{{ old('name', auth()->user()->name) }}"
                                           class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                                           required>
                                </div>
                                
                                <div>
                                    <label for="email" class="block text-sm font-semibold text-gray-700 mb-2">Email Address *</label>
                                    <input type="email" 
                                           id="email" 
                                           name="email" 
                                           value="{{ old('email', auth()->user()->email) }}"
                                           class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                                           required>
                                </div>
                            </div>

                            <!-- Phone & WhatsApp -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="phone" class="block text-sm font-semibold text-gray-700 mb-2">
                                        Phone Number 
                                        @if(empty(auth()->user()->phone))
                                            <span class="text-red-500">* Required</span>
                                        @endif
                                    </label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-400">📞</span>
                                        </div>
                                        <input type="tel" 
                                               id="phone" 
                                               name="phone" 
                                               value="{{ old('phone', auth()->user()->phone) }}"
                                               placeholder="08012345678"
                                               class="block w-full pl-12 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200">
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">Format: 08012345678</p>
                                </div>
                                
                                <div>
                                    <label for="whatsapp" class="block text-sm font-semibold text-gray-700 mb-2">WhatsApp Number</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-400">💬</span>
                                        </div>
                                        <input type="tel" 
                                               id="whatsapp" 
                                               name="whatsapp" 
                                               value="{{ old('whatsapp', auth()->user()->whatsapp) }}"
                                               placeholder="08012345678"
                                               class="block w-full pl-12 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200">
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">Optional - for WhatsApp notifications</p>
                                </div>
                            </div>

                            <!-- Birthday & Gender -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="birthday" class="block text-sm font-semibold text-gray-700 mb-2">Date of Birth</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-400">🎂</span>
                                        </div>
                                        <input type="date" 
                                               id="birthday" 
                                               name="birthday" 
                                               value="{{ old('birthday', auth()->user()->birthday ? auth()->user()->birthday->format('Y-m-d') : '') }}"
                                               max="{{ now()->subYears(18)->format('Y-m-d') }}"
                                               class="block w-full pl-12 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200">
                                    </div>
                                    @if(auth()->user()->age)
                                        <p class="text-xs text-gray-500 mt-1">{{ auth()->user()->age }} years old</p>
                                    @endif
                                </div>
                                
                                <div>
                                    <label for="gender" class="block text-sm font-semibold text-gray-700 mb-2">Gender</label>
                                    <select id="gender" 
                                            name="gender"
                                            class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200">
                                        <option value="">Select Gender</option>
                                        <option value="male" {{ old('gender', auth()->user()->gender) == 'male' ? 'selected' : '' }}>Male</option>
                                        <option value="female" {{ old('gender', auth()->user()->gender) == 'female' ? 'selected' : '' }}>Female</option>
                                        <option value="other" {{ old('gender', auth()->user()->gender) == 'other' ? 'selected' : '' }}>Other</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Address Information -->
                            <div class="space-y-6">
                                <div>
                                    <label for="address" class="block text-sm font-semibold text-gray-700 mb-2">Address</label>
                                    <textarea id="address" 
                                              name="address" 
                                              rows="2"
                                              class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200">{{ old('address', auth()->user()->address) }}</textarea>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="state" class="block text-sm font-semibold text-gray-700 mb-2">State</label>
                                        <select id="state" 
                                                name="state"
                                                class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200">
                                            <option value="">Select State</option>
                                            @foreach($states as $state)
                                                <option value="{{ $state }}" {{ old('state', auth()->user()->state) == $state ? 'selected' : '' }}>
                                                    {{ $state }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label for="city" class="block text-sm font-semibold text-gray-700 mb-2">City</label>
                                        <input type="text" 
                                               id="city" 
                                               name="city" 
                                               value="{{ old('city', auth()->user()->city) }}"
                                               class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200">
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="pt-6">
                                <button type="submit" 
                                        class="w-full md:w-auto bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-bold py-4 px-8 rounded-xl shadow-lg hover:shadow-xl transform hover:scale-[1.02] transition-all duration-200 flex items-center justify-center space-x-2">
                                    <span>Save Changes</span>
                                    <span>💾</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Right Column - Stats & Preferences -->
                <div class="space-y-6">
                    <!-- Profile Completion -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-green-500 mr-2">📊</span>
                            Profile Completion
                        </h3>
                        
                        <!-- Progress Bar -->
                        <div class="mb-4">
                            <div class="flex justify-between text-sm text-gray-600 mb-1">
                                <span>{{ auth()->user()->profile_completion_percentage }}% Complete</span>
                                <span>{{ auth()->user()->profile_completion_percentage < 100 ? 'Complete profile for better experience' : 'Profile Complete!' }}</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-gradient-to-r from-green-500 to-emerald-600 h-2 rounded-full" 
                                     style="width: {{ auth()->user()->profile_completion_percentage }}%"></div>
                            </div>
                        </div>
                        
                        <!-- Completion Checklist -->
                        <div class="space-y-2 text-sm">
                            <div class="flex items-center">
                                <span class="{{ auth()->user()->phone ? 'text-green-500' : 'text-gray-300' }} mr-2">
                                    {{ auth()->user()->phone ? '✅' : '⭕' }}
                                </span>
                                <span>Phone Number</span>
                            </div>
                            <div class="flex items-center">
                                <span class="{{ auth()->user()->whatsapp ? 'text-green-500' : 'text-gray-300' }} mr-2">
                                    {{ auth()->user()->whatsapp ? '✅' : '⭕' }}
                                </span>
                                <span>WhatsApp Number</span>
                            </div>
                            <div class="flex items-center">
                                <span class="{{ auth()->user()->birthday ? 'text-green-500' : 'text-gray-300' }} mr-2">
                                    {{ auth()->user()->birthday ? '✅' : '⭕' }}
                                </span>
                                <span>Date of Birth</span>
                            </div>
                            <div class="flex items-center">
                                <span class="{{ auth()->user()->address ? 'text-green-500' : 'text-gray-300' }} mr-2">
                                    {{ auth()->user()->address ? '✅' : '⭕' }}
                                </span>
                                <span>Address</span>
                            </div>
                        </div>
                    </div>

                    <!-- Notification Preferences -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-green-500 mr-2">🔔</span>
                            Notification Preferences
                        </h3>
                        
                        <form method="POST" action="{{ route('profile.notifications') }}" class="space-y-4">
                            @csrf
                            @method('PUT')
                            
                            @php $prefs = auth()->user()->getNotificationPreferences(); @endphp
                            
                            <!-- Email Notifications -->
                            <div class="space-y-2">
                                <h4 class="font-semibold text-gray-700 text-sm flex items-center">
                                    <span class="mr-2">📧</span>
                                    Email Notifications
                                </h4>
                                <div class="space-y-1 pl-6">
                                    <label class="flex items-center">
                                        <input type="checkbox" 
                                               name="notifications[email][transactions]" 
                                               value="1"
                                               {{ $prefs['email']['transactions'] ?? false ? 'checked' : '' }}
                                               class="rounded border-gray-300 text-green-500 focus:ring-green-500 mr-2">
                                        <span class="text-sm text-gray-600">Transaction Updates</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="checkbox" 
                                               name="notifications[email][promotions]" 
                                               value="1"
                                               {{ $prefs['email']['promotions'] ?? false ? 'checked' : '' }}
                                               class="rounded border-gray-300 text-green-500 focus:ring-green-500 mr-2">
                                        <span class="text-sm text-gray-600">Promotions & Offers</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="checkbox" 
                                               name="notifications[email][security]" 
                                               value="1"
                                               {{ $prefs['email']['security'] ?? false ? 'checked' : '' }}
                                               class="rounded border-gray-300 text-green-500 focus:ring-green-500 mr-2">
                                        <span class="text-sm text-gray-600">Security Alerts</span>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- SMS Notifications -->
                            <div class="space-y-2">
                                <h4 class="font-semibold text-gray-700 text-sm flex items-center">
                                    <span class="mr-2">📱</span>
                                    SMS Notifications
                                </h4>
                                <div class="space-y-1 pl-6">
                                    <label class="flex items-center">
                                        <input type="checkbox" 
                                               name="notifications[sms][transactions]" 
                                               value="1"
                                               {{ $prefs['sms']['transactions'] ?? false ? 'checked' : '' }}
                                               class="rounded border-gray-300 text-green-500 focus:ring-green-500 mr-2">
                                        <span class="text-sm text-gray-600">Transaction Updates</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="checkbox" 
                                               name="notifications[sms][security]" 
                                               value="1"
                                               {{ $prefs['sms']['security'] ?? false ? 'checked' : '' }}
                                               class="rounded border-gray-300 text-green-500 focus:ring-green-500 mr-2">
                                        <span class="text-sm text-gray-600">Security Alerts</span>
                                    </label>
                                </div>
                            </div>
                            
                            <button type="submit" 
                                    class="w-full bg-green-50 hover:bg-green-100 text-green-700 font-semibold py-2 px-4 rounded-xl border border-green-200 transition-all duration-200 text-sm">
                                Save Preferences
                            </button>
                        </form>
                    </div>

                    <!-- Quick Stats -->
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-green-500 mr-2">📈</span>
                            Quick Stats
                        </h3>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Total Transactions</span>
                                <span class="font-semibold">{{ auth()->user()->transactions()->count() }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Wallet Balance</span>
                                <span class="font-semibold text-green-600">₦{{ number_format(auth()->user()->wallet_balance ?? 0, 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Member Since</span>
                                <span class="font-semibold">{{ auth()->user()->created_at->format('M Y') }}</span>
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
    // Preview profile picture before upload
    const profilePicInput = document.getElementById('profile_picture');
    if (profilePicInput) {
        profilePicInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    // Update preview
                    const preview = document.querySelector('.rounded-2xl.overflow-hidden img') || 
                                   document.querySelector('.rounded-2xl.overflow-hidden div');
                    if (preview) {
                        if (preview.tagName === 'IMG') {
                            preview.src = event.target.result;
                        } else {
                            // Replace initial placeholder with image
                            const img = document.createElement('img');
                            img.src = event.target.result;
                            img.className = 'w-full h-full object-cover';
                            preview.parentNode.replaceChild(img, preview);
                        }
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    }
    
    // Auto-format phone numbers
    const phoneInputs = document.querySelectorAll('input[type="tel"]');
phoneInputs.forEach(input => {
    // Remove formatting on blur to send clean data
    input.addEventListener('blur', function(e) {
        // Remove all non-digit characters before submitting
        this.value = this.value.replace(/\D/g, '');
    });
    
    // Add formatting for display only
    input.addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        if (value.length > 11) value = value.substring(0, 11);
        
        // Format for display only
        if (value.length > 0) {
            if (value.length <= 3) {
                value = value;
            } else if (value.length <= 6) {
                value = value.substring(0, 3) + ' ' + value.substring(3);
            } else if (value.length <= 8) {
                value = value.substring(0, 3) + ' ' + value.substring(3, 6) + ' ' + value.substring(6);
            } else {
                value = value.substring(0, 3) + ' ' + value.substring(3, 6) + ' ' + value.substring(6, 8) + ' ' + value.substring(8);
            }
        }
        
        e.target.value = value;
    });
});
    
    // Real-time phone validation
    const phoneField = document.getElementById('phone');
    if (phoneField) {
        phoneField.addEventListener('blur', function() {
            const value = this.value.replace(/\D/g, '');
            if (value.length === 11 && value.startsWith('0')) {
                // Valid Nigerian number
                this.classList.remove('border-red-300');
                this.classList.add('border-green-300');
            } else if (value.length > 0) {
                this.classList.remove('border-green-300');
                this.classList.add('border-red-300');
            } else {
                this.classList.remove('border-red-300', 'border-green-300');
            }
        });
    }
});

// Age calculation from birthday
const birthdayInput = document.getElementById('birthday');
if (birthdayInput) {
    birthdayInput.addEventListener('change', function() {
        if (this.value) {
            const birthday = new Date(this.value);
            const today = new Date();
            let age = today.getFullYear() - birthday.getFullYear();
            const m = today.getMonth() - birthday.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < birthday.getDate())) {
                age--;
            }
            
            // Show age next to input
            let ageDisplay = document.getElementById('age-display');
            if (!ageDisplay) {
                ageDisplay = document.createElement('p');
                ageDisplay.id = 'age-display';
                ageDisplay.className = 'text-xs text-gray-500 mt-1';
                this.parentNode.appendChild(ageDisplay);
            }
            ageDisplay.textContent = age + ' years old';
        }
    });
}
</script>

<style>
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.animate-fade-in {
    animation: fadeIn 0.3s ease-in-out;
}

/* Custom phone input styling */
input[type="tel"] {
    letter-spacing: 0.5px;
}

/* Profile picture upload hover effect */
label[for="profile_picture"]:hover {
    transform: scale(1.1);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

/* Custom checkbox styling */
input[type="checkbox"]:checked {
    background-color: #10b981;
    border-color: #10b981;
}

/* Smooth transitions */
.transition-all {
    transition-property: all;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 200ms;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .rounded-2xl {
        border-radius: 1rem;
    }
    
    .text-3xl {
        font-size: 2rem;
    }
    
    .grid-cols-2 {
        grid-template-columns: 1fr;
    }
}
</style>
@endsection