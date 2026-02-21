@extends('admin.layouts.app')

@section('title', 'Announcements Management')

@section('content')
<div class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 py-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Announcements</h1>
                    <p class="text-gray-600 mt-2">Manage promotions, notifications, and news</p>
                    <div class="w-24 h-1 bg-gradient-to-r from-green-500 to-green-600 rounded-full mt-3"></div>
                </div>
                <a href="{{ route('admin.announcement.create') }}" 
                   class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-xl hover:from-green-600 hover:to-green-700 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-0.5">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Create Announcement
                </a>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-2xl shadow-lg p-6 border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-sm">Total</p>
                        <p class="text-3xl font-bold text-gray-900 mt-1">{{ $announcements->total() }}</p>
                    </div>
                    <div class="w-12 h-12 bg-gradient-to-br from-gray-100 to-gray-200 rounded-xl flex items-center justify-center">
                        <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>
                        </svg>
                    </div>
                </div>
            </div>

            @php
                $types = ['promotion', 'notification', 'news'];
                $colors = [
                    'promotion' => 'from-yellow-400 to-yellow-500',
                    'notification' => 'from-blue-400 to-blue-500',
                    'news' => 'from-green-400 to-green-500'
                ];
                $icons = [
                    'promotion' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                    'notification' => 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
                    'news' => 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z'
                ];
            @endphp

            @foreach($types as $type)
                @php
                    $count = \App\Models\PromotionNotification::where('type', $type)->count();
                    $activeCount = \App\Models\PromotionNotification::where('type', $type)->where('is_active', true)->count();
                @endphp
                <div class="bg-white rounded-2xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 text-sm capitalize">{{ $type }}</p>
                            <p class="text-3xl font-bold text-gray-900 mt-1">{{ $count }}</p>
                            <p class="text-sm text-green-600 mt-1">{{ $activeCount }} active</p>
                        </div>
                        <div class="w-12 h-12 bg-gradient-to-br {{ $colors[$type] }} rounded-xl flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icons[$type] }}"/>
                            </svg>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Success Message -->
        @if(session('success'))
            <div class="mb-6 bg-gradient-to-r from-green-50 to-green-100 border-l-4 border-green-500 rounded-xl shadow-sm p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-green-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span class="text-green-800 font-medium">{{ session('success') }}</span>
                </div>
            </div>
        @endif

        <!-- Announcements Table -->
        <div class="bg-white rounded-2xl shadow-xl border border-gray-200 overflow-hidden">
            <!-- Table Header -->
            <div class="bg-gradient-to-r from-gray-50 to-gray-100 px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                        All Announcements
                    </h3>
                    <div class="text-sm text-gray-500">
                        Showing {{ $announcements->firstItem() }}-{{ $announcements->lastItem() }} of {{ $announcements->total() }}
                    </div>
                </div>
            </div>

            <!-- Table Body -->
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Announcement</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Type</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Schedule</th>
                            <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($announcements as $announcement)
                        <tr class="hover:bg-green-50 transition-colors duration-150">
                            <!-- Announcement Details -->
                            <td class="px-6 py-4">
                                <div class="flex items-start space-x-3">
                                    <div class="flex-shrink-0">
                                        @if($announcement->icon)
                                            <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
                                                <span class="text-lg">{{ $announcement->icon }}</span>
                                            </div>
                                        @else
                                            <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center">
                                                <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                            </div>
                                        @endif
                                    </div>
                                    <div>
                                        <h4 class="font-bold text-gray-900">{{ $announcement->title }}</h4>
                                        <p class="text-sm text-gray-600 mt-1 line-clamp-2">{{ Str::limit($announcement->content, 100) }}</p>
                                        @if($announcement->badge)
                                            <span class="inline-block mt-2 px-2 py-1 text-xs font-bold rounded"
                                                  style="background-color: {{ $announcement->badge_color ?? '#3B82F6' }}; color: {{ $announcement->text_color ?? '#ffffff' }}">
                                                {{ $announcement->badge }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </td>

                            <!-- Type -->
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium
                                    @if($announcement->type == 'promotion') bg-yellow-100 text-yellow-800
                                    @elseif($announcement->type == 'notification') bg-blue-100 text-green-800
                                    @else bg-green-100 text-green-800 @endif">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                              d="{{ $icons[$announcement->type] ?? $icons['news'] }}"/>
                                    </svg>
                                    {{ ucfirst($announcement->type) }}
                                </span>
                            </td>

                            <!-- Status -->
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    <div class="relative">
                                        @if($announcement->is_active)
                                            <div class="w-3 h-3 bg-green-500 rounded-full animate-ping absolute opacity-75"></div>
                                            <div class="w-3 h-3 bg-green-500 rounded-full relative"></div>
                                        @else
                                            <div class="w-3 h-3 bg-red-500 rounded-full"></div>
                                        @endif
                                    </div>
                                    <span class="ml-2 text-sm font-medium {{ $announcement->is_active ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $announcement->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </div>
                            </td>

                            <!-- Schedule -->
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-600">
                                    @if($announcement->starts_at && $announcement->ends_at)
                                        <div class="flex items-center">
                                            <svg class="w-4 h-4 text-gray-400 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                            {{ $announcement->starts_at->format('M d') }} - {{ $announcement->ends_at->format('M d') }}
                                        </div>
                                    @elseif($announcement->starts_at)
                                        <div>Starts {{ $announcement->starts_at->format('M d') }}</div>
                                    @else
                                        <span class="text-gray-400">No schedule</span>
                                    @endif
                                </div>
                            </td>

                            <!-- Actions -->
                            <td class="px-6 py-4">
                                <div class="flex items-center space-x-2">
                                    <!-- Edit -->
                                    <a href="{{ route('admin.announcement.edit', $announcement->id) }}" 
                                       class="p-2 bg-gradient-to-r from-blue-50 to-blue-100 text-blue-600 rounded-lg hover:from-blue-100 hover:to-blue-200 transition-all duration-200"
                                       title="Edit">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                    </a>

                                    <!-- Toggle Status -->
                                    <form action="{{ route('admin.announcement.toggle-status', $announcement->id) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" 
                                                class="p-2 bg-gradient-to-r {{ $announcement->is_active ? 'from-yellow-50 to-yellow-100 text-yellow-600' : 'from-green-50 to-green-100 text-green-600' }} rounded-lg hover:opacity-90 transition-all duration-200"
                                                title="{{ $announcement->is_active ? 'Deactivate' : 'Activate' }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                @if($announcement->is_active)
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L6.59 6.59m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                                                @else
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                @endif
                                            </svg>
                                        </button>
                                    </form>

                                    <!-- Delete -->
                                    <form action="{{ route('admin.announcement.destroy', $announcement->id) }}" method="POST" class="inline">
                                        @csrf @method('DELETE')
                                        <button type="submit" 
                                                onclick="return confirm('Are you sure you want to delete this announcement?')"
                                                class="p-2 bg-gradient-to-r from-red-50 to-red-100 text-red-600 rounded-lg hover:from-red-100 hover:to-red-200 transition-all duration-200"
                                                title="Delete">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="px-6 py-16 text-center">
                                <div class="text-gray-400">
                                    <svg class="w-16 h-16 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    <h3 class="text-lg font-medium text-gray-900 mb-2">No announcements yet</h3>
                                    <p class="text-gray-600">Get started by creating your first announcement</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if($announcements->hasPages())
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                {{ $announcements->links('vendor.pagination.tailwind') }}
            </div>
            @endif
        </div>
    </div>
</div>

<style>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.truncate-text {
    max-width: 300px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>
@endsection