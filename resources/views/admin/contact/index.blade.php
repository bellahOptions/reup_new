@extends('admin.layouts.app')

@section('title', 'Contact Messages')

@section('content')
<div class="py-6">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
                        <span class="text-2xl">📧</span>
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">Contact Messages</h1>
                        <p class="text-gray-600 mt-1">Manage customer inquiries and support requests</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-gray-600">Total Messages</span>
                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                        <span class="text-xl">📬</span>
                    </div>
                </div>
                <h3 class="text-2xl font-bold text-gray-900">{{ $stats['total'] ?? 0 }}</h3>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-gray-600">Unread</span>
                    <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center">
                        <span class="text-xl">📩</span>
                    </div>
                </div>
                <h3 class="text-2xl font-bold text-yellow-600">{{ $stats['unread'] ?? 0 }}</h3>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-gray-600">Pending Response</span>
                    <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center">
                        <span class="text-xl">⏳</span>
                    </div>
                </div>
                <h3 class="text-2xl font-bold text-red-600">{{ $stats['pending'] ?? 0 }}</h3>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-gray-600">Today</span>
                    <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                        <span class="text-xl">📨</span>
                    </div>
                </div>
                <h3 class="text-2xl font-bold text-green-600">{{ $stats['today'] ?? 0 }}</h3>
            </div>
        </div>

        <!-- Messages List -->
        <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Sender</th>
                            <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Subject</th>
                            <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Message Preview</th>
                            <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Status</th>
                            <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Date</th>
                            <th class="text-left py-4 px-6 text-xs font-semibold text-gray-600 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($messages as $message)
                        <tr class="hover:bg-gray-50 transition-colors {{ !$message->is_read ? 'bg-blue-50' : '' }}">
                            <td class="py-4 px-6">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-gradient-to-br from-blue-400 to-blue-500 rounded-full flex items-center justify-center text-white font-bold mr-3">
                                        {{ substr($message->name, 0, 1) }}
                                    </div>
                                    <div>
                                        <p class="font-semibold text-gray-900">{{ $message->name }}</p>
                                        <p class="text-xs text-gray-600">{{ $message->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-4 px-6">
                                <span class="font-medium text-gray-900">{{ $message->subject }}</span>
                            </td>
                            <td class="py-4 px-6">
                                <p class="text-sm text-gray-600 truncate max-w-md">
                                    {{ Str::limit($message->message, 60) }}
                                </p>
                            </td>
                            <td class="py-4 px-6">
                                <div class="space-y-1">
                                    @if(!$message->is_read)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800">
                                            📩 Unread
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">
                                            ✅ Read
                                        </span>
                                    @endif
                                    
                                    @if($message->is_responded)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                            ✉️ Responded
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="py-4 px-6 text-sm text-gray-600">
                                {{ $message->created_at->format('M d, Y') }}<br>
                                <span class="text-xs text-gray-500">{{ $message->created_at->format('h:i A') }}</span>
                            </td>
                            <td class="py-4 px-6">
                                <div class="flex items-center space-x-2">
                                    <a href="{{ route('admin.contact.show', $message->id) }}" 
                                       class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg text-sm font-semibold transition-colors">
                                        View
                                    </a>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center">
                                <div class="flex flex-col items-center">
                                    <span class="text-4xl mb-4">📧</span>
                                    <p class="text-gray-600 font-medium">No contact messages yet</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($messages->hasPages())
            <div class="p-6 border-t border-gray-200">
                {{ $messages->links() }}
            </div>
            @endif
        </div>
    </div>
</div>

<script>
// Auto-refresh every 30 seconds
setInterval(() => {
    location.reload();
}, 30000);
</script>
@endsection