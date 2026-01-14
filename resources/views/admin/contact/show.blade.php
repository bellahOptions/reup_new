@extends('admin.layouts.app')

@section('title', 'Contact Message Details')

@section('content')
<div class="py-6">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
        <!-- Back Button -->
        <div class="mb-6">
            <a href="{{ route('admin.contact.index') }}" 
               class="inline-flex items-center text-gray-600 hover:text-gray-900 font-semibold transition-colors">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Messages
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Message Content -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Message Header -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="bg-gradient-to-r from-green-500 to-green-600 px-6 py-8">
                        <div class="flex items-start justify-between">
                            <div>
                                <h1 class="text-2xl font-bold text-white mb-2">{{ $message->subject }}</h1>
                                <p class="text-green-100 text-sm">
                                    Reference ID: #{{ str_pad($message->id, 6, '0', STR_PAD_LEFT) }}
                                </p>
                            </div>
                            <div class="flex flex-col items-end space-y-2">
                                @if(!$message->is_read)
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-800">
                                        📩 Unread
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-white text-green-600">
                                        ✅ Read
                                    </span>
                                @endif
                                
                                @if($message->is_responded)
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                        ✉️ Responded
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                        ⏳ Pending
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Sender Info -->
                    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                        <div class="flex items-center space-x-4">
                            <div class="w-16 h-16 bg-gradient-to-br from-green-400 to-green-500 rounded-full flex items-center justify-center text-white text-2xl font-bold">
                                {{ substr($message->name, 0, 1) }}
                            </div>
                            <div class="flex-1">
                                <h3 class="text-lg font-bold text-gray-900">{{ $message->name }}</h3>
                                <div class="flex flex-wrap items-center gap-4 mt-1">
                                    <a href="mailto:{{ $message->email }}" 
                                       class="text-sm text-green-600 hover:text-green-800 flex items-center">
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                        </svg>
                                        {{ $message->email }}
                                    </a>
                                    
                                    @if($message->user)
                                    <a href="{{ route('admin.users.show', $message->user->id) }}" 
                                       class="text-sm text-green-600 hover:text-green-800 flex items-center">
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                        </svg>
                                        Registered User
                                    </a>
                                    @else
                                    <span class="text-sm text-gray-500 flex items-center">
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                        </svg>
                                        Guest
                                    </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Message Content -->
                    <div class="px-6 py-8">
                        <div class="prose max-w-none">
                            <h4 class="text-sm font-semibold text-gray-500 uppercase mb-3">Message</h4>
                            <div class="bg-gray-50 rounded-lg p-6 border border-gray-200">
                                <p class="text-gray-800 whitespace-pre-wrap leading-relaxed">{{ $message->message }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Timestamp -->
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                        <div class="flex items-center justify-between text-sm text-gray-600">
                            <div class="flex items-center space-x-4">
                                <span class="flex items-center">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    Received: {{ $message->created_at->format('M d, Y \a\t h:i A') }}
                                </span>
                                <span class="text-gray-400">•</span>
                                <span>{{ $message->created_at->diffForHumans() }}</span>
                            </div>
                            
                            @if($message->responded_at)
                            <div class="flex items-center text-green-600">
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                Responded: {{ $message->responded_at->format('M d, Y') }}
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
                <!-- Previous Replies -->
@if($message->replies && $message->replies->count() > 0)
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
    <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
        <span class="text-xl mr-2">📨</span>
        Reply History
    </h3>
    
    <div class="space-y-4">
        @foreach($message->replies->sortByDesc('sent_at') as $reply)
        <div class="border border-gray-200 rounded-lg p-4">
            <div class="flex justify-between items-start mb-3">
                <div>
                    <span class="font-semibold text-gray-900">{{ $reply->subject }}</span>
                    <span class="text-xs text-gray-500 ml-2">
                        Sent by {{ $reply->admin->name ?? 'Admin' }}
                    </span>
                </div>
                <span class="text-sm text-gray-500">
                    {{ $reply->sent_at->format('M d, Y h:i A') }}
                </span>
            </div>
            <div class="bg-gray-50 rounded p-3 text-gray-700 whitespace-pre-wrap">
                {{ $reply->message }}
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

                <!-- Quick Reply Form -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-bold text-gray-900 mb-4 flex items-center">
                        <span class="text-xl mr-2">✉️</span>
                        Quick Reply
                    </h3>
                    
                    <form id="replyForm" action="{{ route('admin.contact.reply', $message->id) }}" method="POST">
                        @csrf
                        
                        <div class="mb-4">
                            <label for="reply_subject" class="block text-sm font-semibold text-gray-700 mb-2">
                                Subject
                            </label>
                            <input type="text" 
                                   id="reply_subject" 
                                   name="subject" 
                                   value="Re: {{ $message->subject }}" 
                                   class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all"
                                   required>
                        </div>

                        <div class="mb-4">
                            <label for="reply_message" class="block text-sm font-semibold text-gray-700 mb-2">
                                Your Response
                            </label>
                            <textarea id="reply_message" 
                                      name="message" 
                                      rows="8" 
                                      placeholder="Type your response here..."
                                      class="block w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all resize-none"
                                      required></textarea>
                            <p class="text-xs text-gray-500 mt-2">
                                Your response will be sent to {{ $message->email }}
                            </p>
                        </div>

                        <!-- Quick Templates -->
                        <div class="mb-4">
                            <p class="text-sm font-semibold text-gray-700 mb-2">Quick Templates:</p>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" 
                                        onclick="insertTemplate('greeting')"
                                        class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg transition-colors">
                                    Greeting
                                </button>
                                <button type="button" 
                                        onclick="insertTemplate('thanks')"
                                        class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg transition-colors">
                                    Thank You
                                </button>
                                <button type="button" 
                                        onclick="insertTemplate('support')"
                                        class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg transition-colors">
                                    Support Info
                                </button>
                                <button type="button" 
                                        onclick="insertTemplate('closing')"
                                        class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg transition-colors">
                                    Closing
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center space-x-3">
                            <button type="submit" 
                                    id="sendReplyBtn"
                                    class="flex-1 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-bold py-3 px-6 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                                </svg>
                                Send Reply
                            </button>
                            
                            <button type="button" 
                                    onclick="copyToClipboard('{{ $message->email }}')"
                                    class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold py-3 px-4 rounded-lg transition-colors"
                                    title="Copy email address">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                </svg>
                            </button>
                        </div>
                    </form>

                    <div id="replyMessage" class="mt-4 hidden"></div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="lg:col-span-1 space-y-6">
                <!-- Actions Card -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                        <span class="text-xl mr-2">⚡</span>
                        Actions
                    </h3>
                    
                    <div class="space-y-3">
                        @if(!$message->is_read)
                        <button onclick="markAsRead({{ $message->id }})" 
                                id="markReadBtn"
                                class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Mark as Read
                        </button>
                        @endif
                        
                        @if(!$message->is_responded)
                        <button onclick="markAsResponded({{ $message->id }})" 
                                id="markRespondedBtn"
                                class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Mark as Responded
                        </button>
                        @endif
                        
                        <a href="mailto:{{ $message->email }}" 
                           class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            Open in Email Client
                        </a>
                        
                        <button onclick="printMessage()" 
                                class="w-full bg-gray-600 hover:bg-gray-700 text-white font-semibold py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                            </svg>
                            Print
                        </button>
                        
                        <button onclick="deleteMessage({{ $message->id }})" 
                                class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            Delete Message
                        </button>
                    </div>
                </div>

                <!-- Message Info -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                        <span class="text-xl mr-2">ℹ️</span>
                        Information
                    </h3>
                    
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between items-start py-2 border-b border-gray-100">
                            <span class="text-gray-600">Category</span>
                            <span class="font-semibold text-gray-900">{{ $message->subject }}</span>
                        </div>
                        
                        <div class="flex justify-between items-start py-2 border-b border-gray-100">
                            <span class="text-gray-600">Status</span>
                            <span class="font-semibold {{ $message->is_responded ? 'text-green-600' : 'text-yellow-600' }}">
                                {{ $message->is_responded ? 'Responded' : 'Pending' }}
                            </span>
                        </div>
                        
                        <div class="flex justify-between items-start py-2 border-b border-gray-100">
                            <span class="text-gray-600">Received</span>
                            <span class="font-semibold text-gray-900">{{ $message->created_at->diffForHumans() }}</span>
                        </div>
                        
                        <div class="flex justify-between items-start py-2 border-b border-gray-100">
                            <span class="text-gray-600">IP Address</span>
                            <span class="font-semibold text-gray-900">{{ request()->ip() }}</span>
                        </div>
                        
                        @if($message->user)
                        <div class="flex justify-between items-start py-2">
                            <span class="text-gray-600">User Type</span>
                            <span class="font-semibold text-green-600">Registered</span>
                        </div>
                        @else
                        <div class="flex justify-between items-start py-2">
                            <span class="text-gray-600">User Type</span>
                            <span class="font-semibold text-gray-600">Guest</span>
                        </div>
                        @endif
                    </div>
                </div>

                <!-- User Info (if registered) -->
                @if($message->user)
                <div class="bg-gradient-to-br from-green-50 to-green-50 rounded-xl border border-green-200 p-6">
                    <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                        <span class="text-xl mr-2">👤</span>
                        User Account
                    </h3>
                    
                    <div class="space-y-3">
                        <div class="flex items-center space-x-3">
                            <div class="w-12 h-12 bg-gradient-to-br from-green-400 to-green-500 rounded-full flex items-center justify-center text-white font-bold">
                                {{ substr($message->user->name, 0, 1) }}
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900">{{ $message->user->name }}</p>
                                <p class="text-xs text-gray-600">ID: #{{ str_pad($message->user->id, 6, '0', STR_PAD_LEFT) }}</p>
                            </div>
                        </div>
                        
                        <div class="pt-3 border-t border-green-200 space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Email</span>
                                <span class="font-semibold text-gray-900">{{ $message->user->email }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Phone</span>
                                <span class="font-semibold text-gray-900">{{ $message->user->phone ?? 'N/A' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Wallet</span>
                                <span class="font-semibold text-green-600">₦{{ number_format($message->user->wallet_balance ?? 0, 2) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Joined</span>
                                <span class="font-semibold text-gray-900">{{ $message->user->created_at->format('M Y') }}</span>
                            </div>
                        </div>
                        
                        <a href="{{ route('admin.users.show', $message->user->id) }}" 
                           class="block w-full bg-green-600 hover:bg-green-700 text-white text-center font-semibold py-2 px-4 rounded-lg transition-colors mt-4">
                            View Full Profile
                        </a>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
// Quick reply templates
const templates = {
    greeting: "Dear {{ $message->name }},\n\nThank you for contacting us. ",
    thanks: "Thank you for reaching out to us. We appreciate your message and will assist you promptly.\n\n",
    support: "Our support team is here to help you. If you need further assistance, please don't hesitate to contact us at:\n\n📧 Email: reup.bellahoptions@gmail.com\n📞 Phone: +234 903 141 2354\n💬 Live Chat: Available 24/7 on our website\n\n",
    closing: "\n\nBest regards,\nThe {{ config('app.name') }} Support Team"
};

function insertTemplate(type) {
    const textarea = document.getElementById('reply_message');
    const template = templates[type];
    const currentValue = textarea.value;
    const cursorPos = textarea.selectionStart;
    
    const newValue = currentValue.substring(0, cursorPos) + template + currentValue.substring(cursorPos);
    textarea.value = newValue;
    textarea.focus();
    textarea.setSelectionRange(cursorPos + template.length, cursorPos + template.length);
}

document.getElementById('replyForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const submitBtn = document.getElementById('sendReplyBtn');
    const replyMessage = document.getElementById('replyMessage');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.innerHTML = '<svg class="animate-spin h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Sending...';
    submitBtn.disabled = true;
    
    try {
        const formData = new FormData(this);
        const response = await fetch(this.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            replyMessage.className = 'mt-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg';
            replyMessage.innerHTML = `
                <div class="flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    <strong>${data.message}</strong>
                </div>
            `;
            
            // Clear form
            document.getElementById('reply_message').value = '';
            
            // Update status badges
            const respondedBadge = document.querySelector('[class*="Pending"]');
            if (respondedBadge) {
                respondedBadge.className = 'inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800';
                respondedBadge.innerHTML = '✉️ Responded';
            }
            
            // Reload the page after 2 seconds to show new reply
            setTimeout(() => {
                location.reload();
            }, 2000);
        } else {
            throw new Error(data.message || 'Failed to send reply');
        }
    } catch (error) {
        replyMessage.className = 'mt-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg';
        replyMessage.innerHTML = `
            <div class="flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                <strong>Error: ${error.message}</strong>
            </div>
        `;
    } finally {
        replyMessage.classList.remove('hidden');
        submitBtn.disabled = false;
        
        setTimeout(() => {
            submitBtn.innerHTML = originalText;
        }, 1000);
    }
});

async function markAsRead(id) {
    try {
        const response = await fetch(`/admin/contact/${id}/mark-read`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (response.ok) {
            location.reload();
        }
    } catch (error) {
        console.error('Error marking as read:', error);
        alert('Failed to mark as read. Please try again.');
    }
}

async function markAsResponded(id) {
    try {
        const response = await fetch(`/admin/contact/${id}/mark-responded`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (response.ok) {
            location.reload();
        }
    } catch (error) {
        console.error('Error marking as responded:', error);
        alert('Failed to mark as responded. Please try again.');
    }
}

function deleteMessage(id) {
    if (!confirm('Are you sure you want to delete this message? This action cannot be undone.')) {
        return;
    }
    
    fetch(`/admin/contact/${id}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (response.ok) {
            window.location.href = '{{ route("admin.contact.index") }}';
        } else {
            throw new Error('Failed to delete message');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to delete message. Please try again.');
    });
}

function printMessage() {
    window.print();
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        // Show temporary success message
        const btn = event.target.closest('button');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
        btn.classList.add('bg-green-500', 'text-white');
        
        setTimeout(() => {
            btn.innerHTML = originalHTML;
            btn.classList.remove('bg-green-500', 'text-white');
        }, 2000);
    }).catch(err => {
        console.error('Failed to copy:', err);
        alert('Failed to copy email address');
    });
}
</script>

<style>
@media print {
    .no-print {
        display: none !important;
    }
    
    body {
        background: white !important;
    }
    
    .bg-gradient-to-r,
    .bg-gradient-to-br {
        background: #3b82f6 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
@endpush
@endsection