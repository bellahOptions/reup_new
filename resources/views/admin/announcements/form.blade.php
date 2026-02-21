@extends('admin.layouts.app')

@section('title', isset($announcement) ? 'Edit Announcement' : 'Create Announcement')

@section('content')
<div class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 py-4 sm:py-8">
    <div class="max-w-4xl mx-auto px-3 sm:px-4 lg:px-8">
        <!-- Header -->
        <div class="mb-4 sm:mb-8">
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 sm:gap-3 mb-2">
                        <a href="{{ route('admin.announcement.index') }}" 
                           class="p-2 bg-white rounded-lg shadow-sm hover:shadow-md transition-shadow flex-shrink-0">
                            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                            </svg>
                        </a>
                        <h1 class="text-xl sm:text-2xl lg:text-3xl font-bold text-gray-900 truncate">
                            {{ isset($announcement) ? 'Edit Announcement' : 'Create Announcement' }}
                        </h1>
                    </div>
                    <p class="text-sm sm:text-base text-gray-600">
                        {{ isset($announcement) ? 'Update announcement details' : 'Create a new promotion, notification, or news item' }}
                    </p>
                    <div class="w-24 sm:w-32 h-1 bg-gradient-to-r from-green-500 to-green-600 rounded-full mt-2 sm:mt-3"></div>
                </div>
            </div>
        </div>

        <!-- Form Container -->
        <div class="bg-white rounded-xl sm:rounded-2xl shadow-xl border border-gray-200 overflow-hidden">
            <!-- Form Header -->
            <div class="bg-gradient-to-r from-gray-50 to-gray-100 px-4 sm:px-6 lg:px-8 py-4 sm:py-6 border-b border-gray-200">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 sm:w-12 sm:h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-lg sm:text-xl font-bold text-gray-900">Announcement Details</h3>
                        <p class="text-gray-600 text-xs sm:text-sm">Fill in the details below</p>
                    </div>
                </div>
            </div>

            <!-- Form -->
            <form action="{{ isset($announcement) ? route('admin.announcement.update', $announcement->id) : route('admin.announcement.store') }}" 
                  method="POST" class="p-4 sm:p-6 lg:p-8">
                @csrf
                @if(isset($announcement))
                    @method('PUT')
                @endif

                @if($errors->any())
                    <div class="mb-4 sm:mb-6 bg-gradient-to-r from-red-50 to-red-100 border-l-4 border-red-500 rounded-lg sm:rounded-xl p-3 sm:p-4">
                        <div class="flex items-start">
                            <svg class="w-5 h-5 text-red-500 mr-3 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <div class="flex-1 min-w-0">
                                <h4 class="font-medium text-red-800 text-sm sm:text-base">Please fix the following errors:</h4>
                                <ul class="mt-1 text-xs sm:text-sm text-red-700 space-y-0.5">
                                    @foreach($errors->all() as $error)
                                        <li>• {{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Responsive Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 lg:gap-8">
                    <!-- Left Column -->
                    <div class="space-y-4 sm:space-y-6">
                        <!-- Type -->
                        <div>
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-2">
                                Announcement Type
                                <span class="text-red-500">*</span>
                            </label>
                            <div class="grid grid-cols-3 gap-2 sm:gap-3">
                                @foreach([
                                    'promotion' => ['icon' => '🎯', 'color' => 'from-yellow-400 to-yellow-500', 'text' => 'Promotion'],
                                    'notification' => ['icon' => '🔔', 'color' => 'from-blue-400 to-blue-500', 'text' => 'Notification'],
                                    'news' => ['icon' => '📰', 'color' => 'from-green-400 to-green-500', 'text' => 'News']
                                ] as $value => $data)
                                    <label class="cursor-pointer">
                                        <input type="radio" name="type" value="{{ $value }}" 
                                               class="hidden peer" 
                                               {{ old('type', isset($announcement) ? $announcement->type : '') == $value ? 'checked' : '' }}
                                               required>
                                        <div class="h-full p-2 sm:p-4 bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg sm:rounded-xl border-2 border-gray-200 peer-checked:border-green-500 peer-checked:shadow-lg transition-all duration-200">
                                            <div class="flex flex-col items-center text-center">
                                                <div class="w-8 h-8 sm:w-12 sm:h-12 rounded-lg bg-gradient-to-br {{ $data['color'] }} flex items-center justify-center text-lg sm:text-2xl mb-1 sm:mb-2">
                                                    {{ $data['icon'] }}
                                                </div>
                                                <span class="font-medium text-gray-900 text-xs sm:text-sm">{{ $data['text'] }}</span>
                                            </div>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <!-- Title -->
                        <div>
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-2">
                                Title
                                <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="title" value="{{ old('title', isset($announcement) ? $announcement->title : '') }}" 
                                   class="w-full px-3 sm:px-4 py-2 sm:py-3 text-sm sm:text-base border border-gray-300 rounded-lg sm:rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all"
                                   placeholder="e.g., Summer Sale 50% Off" required>
                        </div>

                        <!-- Badge Section -->
                        <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg sm:rounded-xl p-3 sm:p-5">
                            <h4 class="font-medium text-gray-900 mb-3 sm:mb-4 text-sm sm:text-base">Badge Settings</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                                <div>
                                    <label class="block text-xs sm:text-sm text-gray-600 mb-2">Badge Text</label>
                                    <input type="text" name="badge" value="{{ old('badge', isset($announcement) ? $announcement->badge : '') }}" 
                                           class="w-full px-3 py-2 text-sm sm:text-base border border-gray-300 rounded-lg"
                                           placeholder="New, Sale, Limited">
                                </div>
                                <div>
                                    <label class="block text-xs sm:text-sm text-gray-600 mb-2">Badge Color</label>
                                    <div class="flex items-center gap-2 sm:gap-3">
                                        <input type="color" name="badge_color" 
                                               value="{{ old('badge_color', isset($announcement) && $announcement->badge_color ? $announcement->badge_color : '#3B82F6') }}" 
                                               class="w-10 h-10 sm:w-12 sm:h-12 border border-gray-300 rounded-lg cursor-pointer flex-shrink-0">
                                        <input type="text" value="{{ old('badge_color', isset($announcement) && $announcement->badge_color ? $announcement->badge_color : '#3B82F6') }}" 
                                               class="flex-1 px-2 sm:px-3 py-2 border border-gray-300 rounded-lg text-xs sm:text-sm font-mono"
                                               readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="space-y-4 sm:space-y-6">
                        <!-- Content -->
                        <div>
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-2">
                                Content
                                <span class="text-red-500">*</span>
                            </label>
                            <textarea name="content" rows="6" 
                                      class="w-full px-3 sm:px-4 py-2 sm:py-3 text-sm sm:text-base border border-gray-300 rounded-lg sm:rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all"
                                      placeholder="Enter announcement details..." required>{{ old('content', isset($announcement) ? $announcement->content : '') }}</textarea>
                            <p class="text-xs text-gray-500 mt-2">Maximum 500 characters recommended</p>
                        </div>

                        <!-- Schedule Section -->
                        <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg sm:rounded-xl p-3 sm:p-5">
                            <h4 class="font-medium text-gray-900 mb-3 sm:mb-4 text-sm sm:text-base">Schedule (Optional)</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                                <div>
                                    <label class="block text-xs sm:text-sm text-gray-600 mb-2">Start Date & Time</label>
                                    <input type="datetime-local" name="starts_at" 
                                           value="{{ old('starts_at', isset($announcement) && $announcement->starts_at ? $announcement->starts_at->format('Y-m-d\TH:i') : '') }}" 
                                           class="w-full px-2 sm:px-3 py-2 text-sm sm:text-base border border-gray-300 rounded-lg">
                                </div>
                                <div>
                                    <label class="block text-xs sm:text-sm text-gray-600 mb-2">End Date & Time</label>
                                    <input type="datetime-local" name="ends_at" 
                                           value="{{ old('ends_at', isset($announcement) && $announcement->ends_at ? $announcement->ends_at->format('Y-m-d\TH:i') : '') }}" 
                                           class="w-full px-2 sm:px-3 py-2 text-sm sm:text-base border border-gray-300 rounded-lg">
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mt-3">Leave empty for immediate display</p>
                        </div>
                    </div>
                </div>

                <!-- Active Switch -->
                <div class="mt-6 sm:mt-8 pt-6 sm:pt-8 border-t border-gray-200">
                    <div class="flex items-start sm:items-center justify-between gap-3">
                        <label class="flex items-start sm:items-center cursor-pointer flex-1">
                            <div class="relative flex-shrink-0">
                                <input type="checkbox" name="is_active" id="is_active" value="1" 
                                       {{ old('is_active', isset($announcement) ? $announcement->is_active : true) ? 'checked' : '' }} 
                                       class="sr-only">
                                       @if(old('is_active', isset($announcement) ? $announcement->is_active : true))
                                <div class="block w-12 sm:w-14 h-7 sm:h-8 bg-green-500 rounded-full"></div>
                                @else
                                <div class="block w-12 sm:w-14 h-7 sm:h-8 bg-gray-300 rounded-full"></div>
                                @endif
                                <div class="dot absolute left-1 top-1 bg-white w-5 sm:w-6 h-5 sm:h-6 rounded-full transition transform"></div>
                            </div>
                            <div class="ml-3 sm:ml-4">
                                <span class="font-medium text-gray-900 text-sm sm:text-base">Active Status</span>
                                <p class="text-xs sm:text-sm text-gray-600">Make this announcement visible to users</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="mt-6 sm:mt-8 pt-6 sm:pt-8 border-t border-gray-200">
                    <div class="flex flex-col gap-4">
                        <div class="text-xs sm:text-sm text-gray-500 text-center sm:text-left">
                            @if(isset($announcement))
                                @if($announcement->created_at)
                                    Created {{ $announcement->created_at->diffForHumans() }}
                                @else
                                    Created recently
                                @endif
                            @else
                                All fields marked with <span class="text-red-500">*</span> are required
                            @endif
                        </div>
                        <div class="flex flex-col-reverse sm:flex-row items-stretch sm:items-center gap-3 sm:gap-4">
                            <a href="{{ route('admin.announcement.index') }}" 
                               class="w-full sm:w-auto text-center px-4 sm:px-6 py-2.5 sm:py-3 bg-gradient-to-r from-gray-100 to-gray-200 text-gray-700 rounded-lg sm:rounded-xl hover:from-gray-200 hover:to-gray-300 transition-all text-sm sm:text-base">
                                Cancel
                            </a>
                            <button type="submit" 
                                    class="w-full sm:w-auto px-6 sm:px-8 py-2.5 sm:py-3 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg sm:rounded-xl hover:from-green-600 hover:to-green-700 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 flex items-center justify-center gap-2 text-sm sm:text-base">
                                <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                          d="{{ isset($announcement) ? 'M5 13l4 4L19 7' : 'M12 4v16m8-8H4' }}"/>
                                </svg>
                                {{ isset($announcement) ? 'Update Announcement' : 'Create Announcement' }}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Toggle Switch Styling */
input:checked ~ .dot {
    transform: translateX(100%);
    @apply bg-green-500;
}

input:checked ~ .block {
    @apply bg-green-300;
}

/* Form input focus effects */
input:focus, textarea:focus, select:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
}

/* Radio card hover effect */
label:hover > div:not(.hidden) {
    @apply border-green-400;
}

/* Prevent zoom on input focus (iOS) */
@media screen and (max-width: 640px) {
    input[type="text"],
    input[type="datetime-local"],
    input[type="color"],
    textarea,
    select {
        font-size: 16px;
    }
}
</style>

<script>
// Update color text input when color picker changes
document.addEventListener('DOMContentLoaded', function() {
    const colorPicker = document.querySelector('input[type="color"]');
    const colorText = document.querySelector('input[type="text"][readonly]');
    
    if (colorPicker && colorText) {
        colorPicker.addEventListener('input', function(e) {
            colorText.value = e.target.value;
        });
    }

    // Character counter for textarea
    const textarea = document.querySelector('textarea[name="content"]');
    if (textarea) {
        const charCount = document.createElement('div');
        charCount.className = 'text-xs text-gray-500 mt-1 text-right';
        textarea.parentNode.appendChild(charCount);

        function updateCharCount() {
            const length = textarea.value.length;
            charCount.textContent = `${length} / 500 characters`;
            
            if (length > 500) {
                charCount.classList.add('text-red-500');
                charCount.classList.remove('text-gray-500');
            } else {
                charCount.classList.remove('text-red-500');
                charCount.classList.add('text-gray-500');
            }
        }

        textarea.addEventListener('input', updateCharCount);
        updateCharCount();
    }

    // Character counter for textarea
    const textarea = document.querySelector('textarea[name="content"]');
    if (textarea) {
        const charCount = document.createElement('div');
        charCount.className = 'text-xs text-gray-500 mt-1 text-right';
        textarea.parentNode.appendChild(charCount);

        function updateCharCount() {
            const length = textarea.value.length;
            charCount.textContent = `${length} / 500 characters`;

            if (length > 500) {
                charCount.classList.add('text-red-500');
                charCount.classList.remove('text-gray-500');
            } else {
                charCount.classList.remove('text-red-500');
                charCount.classList.add('text-gray-500');
            }
        }

        textarea.addEventListener('input', updateCharCount);
        updateCharCount();
    }

    
});
</script>
@endsection