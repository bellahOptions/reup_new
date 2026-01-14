@extends('admin.layouts.app')

@section('title', 'Live Chat')

@section('content')
<div class="py-6">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center shadow-lg">
                        <span class="text-2xl">💬</span>
                    </div>
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">Live Chat Support</h1>
                        <p class="text-gray-600 mt-1">Manage customer support chats</p>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-2 bg-green-100 text-green-800 px-4 py-2 rounded-lg">
                        <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                        <span class="text-sm font-semibold">Active</span>
                    </div>
                    <button onclick="toggleAvailability()" 
                            id="availabilityBtn"
                            class="px-4 py-2 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white font-semibold rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2">
                        <span id="availabilityIcon">✅</span>
                        <span id="availabilityText">Available</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <!-- Sidebar - Chat List -->
            <div class="lg:col-span-1 space-y-6">
                <!-- Stats Card -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                        <span class="text-xl mr-2">📊</span>
                        Chat Stats
                    </h3>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Active Chats</span>
                            <span class="font-bold text-green-600" id="activeChatsCount">0</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Waiting</span>
                            <span class="font-bold text-yellow-600" id="waitingChatsCount">0</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Closed Today</span>
                            <span class="font-bold text-blue-600" id="closedChatsCount">0</span>
                        </div>
                    </div>
                </div>

                <!-- Online Admins -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                        <span class="text-xl mr-2">👥</span>
                        Online Team
                    </h3>
                    <div id="onlineAdmins" class="space-y-3">
                        <!-- Online admins will be loaded here -->
                        <div class="text-center py-4">
                            <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-green-600"></div>
                            <p class="text-gray-600 mt-2 text-sm">Loading...</p>
                        </div>
                    </div>
                </div>

                <!-- Chat Filters -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                        <span class="text-xl mr-2">🔍</span>
                        Filters
                    </h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                            <div class="space-y-2">
                                <label class="flex items-center space-x-2">
                                    <input type="radio" name="statusFilter" value="all" checked 
                                           class="text-blue-600 focus:ring-blue-500">
                                    <span class="text-sm text-gray-700">All Chats</span>
                                </label>
                                <label class="flex items-center space-x-2">
                                    <input type="radio" name="statusFilter" value="active"
                                           class="text-green-600 focus:ring-green-500">
                                    <span class="text-sm text-gray-700">Active</span>
                                </label>
                                <label class="flex items-center space-x-2">
                                    <input type="radio" name="statusFilter" value="pending"
                                           class="text-yellow-600 focus:ring-yellow-500">
                                    <span class="text-sm text-gray-700">Pending</span>
                                </label>
                                <label class="flex items-center space-x-2">
                                    <input type="radio" name="statusFilter" value="closed"
                                           class="text-red-600 focus:ring-red-500">
                                    <span class="text-sm text-gray-700">Closed</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Chat Area -->
            <div class="lg:col-span-3">
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                    <!-- Chat Header -->
                    <div class="border-b border-gray-200 p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-xl font-bold text-gray-900">Customer Support</h2>
                                <p class="text-sm text-gray-600 mt-1">
                                    <span id="selectedChatInfo">Select a chat to begin</span>
                                </p>
                            </div>
                            <div class="flex items-center space-x-4">
                                <div id="typingIndicator" class="hidden text-sm text-gray-500">
                                    <span class="flex items-center">
                                        <span class="typing-dots">
                                            <span>.</span><span>.</span><span>.</span>
                                        </span>
                                        <span class="ml-2">Customer is typing</span>
                                    </span>
                                </div>
                                <span id="unreadBadge" class="hidden bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full">0</span>
                            </div>
                        </div>
                    </div>

                    <!-- Two Column Layout -->
                    <div class="flex h-[calc(100vh-300px)]">
                        <!-- Chat List -->
                        <div class="w-1/3 border-r border-gray-200 overflow-y-auto">
                            <div id="chatList" class="divide-y divide-gray-200">
                                <!-- Chat sessions will be loaded here -->
                                <div class="text-center py-8">
                                    <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-green-600"></div>
                                    <p class="text-gray-600 mt-2 text-sm">Loading chats...</p>
                                </div>
                            </div>
                        </div>

                        <!-- Chat Messages -->
                        <div class="w-2/3 flex flex-col">
                            <!-- Messages Container -->
                            <div id="chatMessages" class="flex-1 overflow-y-auto p-6 space-y-4">
                                <div class="text-center py-12">
                                    <div class="w-16 h-16 bg-gray-100 rounded-full mx-auto mb-4 flex items-center justify-center">
                                        <span class="text-2xl">💬</span>
                                    </div>
                                    <h3 class="text-lg font-semibold text-gray-900 mb-2">No chat selected</h3>
                                    <p class="text-gray-600 text-sm">Select a chat from the list to start conversation</p>
                                </div>
                            </div>

                            <!-- Chat Input -->
                            <div class="border-t border-gray-200 p-6">
                                <form id="chatForm" class="space-y-3">
                                    @csrf
                                    <input type="hidden" id="currentSessionId">
                                    
                                    <div class="flex space-x-3">
                                        <div class="flex-1">
                                            <textarea id="messageInput" 
                                                      rows="2"
                                                      placeholder="Type your response..."
                                                      disabled
                                                      class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200 resize-none disabled:bg-gray-100 disabled:cursor-not-allowed"
                                                      maxlength="1000"></textarea>
                                            <p id="charCount" class="text-xs text-gray-500 text-right mt-1 hidden">
                                                <span id="charCountText">0</span>/1000
                                            </p>
                                        </div>
                                        <button type="submit" 
                                                id="sendBtn"
                                                disabled
                                                class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-bold px-6 py-3 rounded-xl shadow-lg hover:shadow-xl transition-all duration-200 flex-shrink-0 disabled:opacity-50 disabled:cursor-not-allowed">
                                            Send
                                        </button>
                                    </div>
                                    
                                    <div class="flex items-center justify-between text-sm text-gray-500">
                                        <div>
                                            <span id="connectionStatus" class="flex items-center">
                                                <span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                                                Connected
                                            </span>
                                        </div>
                                        <div class="space-x-4">
                                            <button type="button" id="closeChatBtn" 
                                                    class="text-red-600 hover:text-red-800 hidden">
                                                ❌ End Chat
                                            </button>
                                            <button type="button" id="transferChatBtn" 
                                                    class="text-blue-600 hover:text-blue-800 hidden">
                                                🔄 Transfer
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
let currentSessionId = null;
let isAvailable = true;
let chatPollInterval;

document.addEventListener('DOMContentLoaded', function() {
    // Load initial data
    loadChatSessions();
    loadOnlineAdmins();
    updateStats();
    
    // Set up polling
    chatPollInterval = setInterval(() => {
        if (currentSessionId) {
            loadMessages(currentSessionId);
        }
        loadChatSessions();
        loadOnlineAdmins();
        updateStats();
    }, 3000);
    
    // Update activity
    setInterval(updateActivity, 60000);
    
    // Chat form submission
    document.getElementById('chatForm').addEventListener('submit', sendMessage);
    
    // Message input handling
    const messageInput = document.getElementById('messageInput');
    messageInput.addEventListener('input', handleMessageInput);
});

function loadChatSessions() {
    fetch('/admin/chat/sessions')
        .then(res => res.json())
        .then(data => {
            renderChatList(data.sessions);
        });
}

function loadOnlineAdmins() {
    fetch('/admin/chat/online-admins')
        .then(res => res.json())
        .then(data => {
            renderOnlineAdmins(data.admins);
        });
}

function updateStats() {
    fetch('/admin/chat/stats')
        .then(res => res.json())
        .then(data => {
            document.getElementById('activeChatsCount').textContent = data.active;
            document.getElementById('waitingChatsCount').textContent = data.pending;
            document.getElementById('closedChatsCount').textContent = data.closed;
        });
}

function renderChatList(sessions) {
    const container = document.getElementById('chatList');
    if (sessions.length === 0) {
        container.innerHTML = `
            <div class="text-center py-8">
                <div class="w-12 h-12 bg-gray-100 rounded-full mx-auto mb-4 flex items-center justify-center">
                    <span class="text-xl">💬</span>
                </div>
                <p class="text-gray-600 text-sm">No active chats</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = sessions.map(session => `
        <div class="p-4 hover:bg-gray-50 cursor-pointer transition-colors ${currentSessionId === session.id ? 'bg-blue-50' : ''}"
             onclick="selectChat(${session.id}, this)">
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center space-x-2">
                    <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold">
                        ${session.user.name.charAt(0).toUpperCase()}
                    </div>
                    <div>
                        <div class="font-medium text-gray-900">${session.user.name}</div>
                        <div class="text-xs text-gray-500">#${session.id.toString().padStart(6, '0')}</div>
                    </div>
                </div>
                <div class="text-xs ${session.status === 'active' ? 'text-green-600' : 'text-yellow-600'}">
                    ${session.status}
                </div>
            </div>
            <div class="text-sm text-gray-600 truncate">
                ${session.last_message ? session.last_message.substring(0, 50) + '...' : 'No messages yet'}
            </div>
            <div class="flex items-center justify-between mt-2 text-xs text-gray-500">
                <span>${new Date(session.last_message_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
                ${session.unread_count > 0 ? `
                    <span class="bg-red-500 text-white px-2 py-1 rounded-full">${session.unread_count}</span>
                ` : ''}
            </div>
        </div>
    `).join('');
}

function renderOnlineAdmins(admins) {
    const container = document.getElementById('onlineAdmins');
    container.innerHTML = admins.map(admin => `
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <div class="w-8 h-8 bg-gradient-to-br from-green-500 to-green-600 rounded-full flex items-center justify-center text-white text-xs font-bold">
                    ${admin.name.charAt(0).toUpperCase()}
                </div>
                <div>
                    <div class="text-sm font-medium text-gray-900">${admin.name}</div>
                    <div class="text-xs text-gray-500">${admin.admin_role}</div>
                </div>
            </div>
            <div class="flex items-center">
                <span class="w-2 h-2 bg-green-500 rounded-full"></span>
            </div>
        </div>
    `).join('');
}

// Replace the selectChat function
async function selectChat(sessionId, element) {
    currentSessionId = sessionId;
    document.getElementById('currentSessionId').value = sessionId;
    
    // Update UI
    document.querySelectorAll('#chatList > div').forEach(div => {
        div.classList.remove('bg-blue-50');
    });
    if (element) {
        element.classList.add('bg-blue-50');
    }
    
    // Enable chat input
    document.getElementById('messageInput').disabled = false;
    document.getElementById('sendBtn').disabled = false;
    document.getElementById('closeChatBtn').classList.remove('hidden');
    document.getElementById('transferChatBtn').classList.remove('hidden');
    
    try {
        // Load messages
        const response = await fetch(`/admin/chat/sessions/${sessionId}/messages`);
        const data = await response.json();
        
        renderMessages(data.messages);
        document.getElementById('selectedChatInfo').textContent = 
            `Chat with ${data.user.name} • ${data.user.email}`;
    } catch (error) {
        console.error('Error loading chat:', error);
    }
}

async function loadMessages(sessionId) {
    try {
        const response = await fetch(`/admin/chat/sessions/${sessionId}/messages`);
        const data = await response.json();
        renderMessages(data.messages);
        
        // Update session info
        document.getElementById('selectedChatInfo').textContent = 
            `Chat with ${data.user.name} • ${data.user.email}`;
    } catch (error) {
        console.error('Error loading messages:', error);
    }
}

function renderMessages(messages) {
    const container = document.getElementById('chatMessages');
    if (!messages || messages.length === 0) {
        container.innerHTML = `
            <div class="text-center py-12">
                <p class="text-gray-600">No messages yet</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = messages.map(msg => `
        <div class="flex ${msg.sender_type === 'admin' ? 'justify-end' : 'justify-start'}">
            <div class="max-w-[70%] rounded-2xl p-4 ${msg.sender_type === 'admin' ? 'bg-gradient-to-r from-green-500 to-green-600 text-white' : 'bg-gray-100 text-gray-800'}">
                <div class="text-sm mb-1">
                    <span class="font-semibold">${msg.sender_type === 'admin' ? 'You' : msg.sender.name}</span>
                    <span class="opacity-75 ml-2 text-xs">${new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
                </div>
                <div class="whitespace-pre-wrap">${escapeHtml(msg.message)}</div>
            </div>
        </div>
    `).join('');
    
    // Scroll to bottom
    container.scrollTop = container.scrollHeight;
}

function handleMessageInput() {
    const input = document.getElementById('messageInput');
    const charCount = document.getElementById('charCount');
    const charCountText = document.getElementById('charCountText');
    
    if (input.value.length > 0) {
        charCount.classList.remove('hidden');
        charCountText.textContent = input.value.length;
        
        // Auto-resize
        input.style.height = 'auto';
        input.style.height = input.scrollHeight + 'px';
        
        // Send typing indicator
        sendTypingStatus(true);
    } else {
        charCount.classList.add('hidden');
    }
}

async function sendMessage(e) {
    e.preventDefault();
    
    const messageInput = document.getElementById('messageInput');
    const message = messageInput.value.trim();
    
    if (!message || !currentSessionId) return;
    
    const sendBtn = document.getElementById('sendBtn');
    sendBtn.disabled = true;
    const originalText = sendBtn.innerHTML;
    sendBtn.innerHTML = '<span>Sending...</span>';
    
    try {
        const response = await fetch('/admin/chat/send', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                session_id: currentSessionId,
                message: message
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            messageInput.value = '';
            messageInput.style.height = 'auto';
            document.getElementById('charCount').classList.add('hidden');
            loadMessages(currentSessionId);
        }
    } catch (error) {
        console.error('Error sending message:', error);
        alert('Failed to send message. Please try again.');
    } finally {
        sendBtn.disabled = false;
        sendBtn.innerHTML = originalText;
    }
}

function sendTypingStatus(isTyping) {
    if (!currentSessionId) return;
    
    fetch('/admin/chat/typing', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
            session_id: currentSessionId,
            is_typing: isTyping
        })
    });
}

function toggleAvailability() {
    isAvailable = !isAvailable;
    const btn = document.getElementById('availabilityBtn');
    const icon = document.getElementById('availabilityIcon');
    const text = document.getElementById('availabilityText');
    
    if (isAvailable) {
        btn.className = 'px-4 py-2 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white font-semibold rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2';
        icon.textContent = '✅';
        text.textContent = 'Available';
    } else {
        btn.className = 'px-4 py-2 bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white font-semibold rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center space-x-2';
        icon.textContent = '⏸️';
        text.textContent = 'Busy';
    }
    
    // Update availability status
    fetch('/admin/chat/availability', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
            is_available: isAvailable
        })
    });
}

function updateActivity() {
    fetch('/admin/update-activity', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
@endsection
