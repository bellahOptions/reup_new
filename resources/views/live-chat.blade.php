@extends('layouts.app')
@section('content')
<main class="min-h-screen bg-gradient-to-br from-gray-50 to-green-50/30">
    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Page Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center shadow-lg">
                            <span class="text-2xl">💬</span>
                        </div>
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900">Live Chat Support</h1>
                            <p class="text-gray-600 mt-1">Real-time chat with our support team</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <button id="closeChatBtn" class="px-4 py-2 border border-red-300 text-red-600 rounded-lg hover:bg-red-50 transition-colors">
                            End Chat
                        </button>
                        <div class="flex items-center space-x-2 bg-green-100 text-green-800 px-3 py-2 rounded-lg">
                            <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                            <span class="text-sm font-semibold">Connected</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <!-- Chat Sidebar -->
                <div class="lg:col-span-1 space-y-6">
                    <!-- User Info Card -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200/60 p-6">
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-full flex items-center justify-center text-white font-bold">
                                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-900">{{ auth()->user()->name }}</h3>
                                <p class="text-sm text-gray-600">{{ auth()->user()->email }}</p>
                            </div>
                        </div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Chat ID:</span>
                                <span class="font-mono font-semibold">#{{ str_pad($session->id, 6, '0', STR_PAD_LEFT) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Status:</span>
                                <span class="font-semibold {{ $session->status === 'active' ? 'text-green-600' : 'text-yellow-600' }}">
                                    {{ ucfirst($session->status) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Admin Info Card -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">👨‍💼</span>
                            Support Agent
                        </h3>
                        <div id="adminInfo" class="{{ $session->admin_id ? '' : 'hidden' }}">
                            <div class="flex items-center space-x-3 mb-4">
                                <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold">
                                    <span>A</span>
                                </div>
                                <div>
                                    <h4 id="adminName" class="font-bold text-gray-900">Loading...</h4>
                                    <p class="text-sm text-gray-600">Support Team</p>
                                </div>
                            </div>
                            <div class="flex items-center text-sm text-gray-600">
                                <span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                                <span>Online</span>
                            </div>
                        </div>
                        <div id="waitingForAdmin" class="{{ $session->admin_id ? 'hidden' : '' }}">
                            <div class="text-center py-4">
                                <div class="w-16 h-16 bg-yellow-100 rounded-full mx-auto mb-3 flex items-center justify-center">
                                    <span class="text-2xl">⏳</span>
                                </div>
                                <p class="text-sm text-gray-700 font-semibold mb-1">Waiting for agent</p>
                                <p class="text-xs text-gray-500">An agent will be with you shortly</p>
                            </div>
                        </div>
                    </div>

                    <!-- Chat Tips -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200/60 p-6">
                        <h3 class="font-bold text-gray-900 mb-4 flex items-center">
                            <span class="text-xl mr-2">💡</span>
                            Chat Tips
                        </h3>
                        <ul class="space-y-3 text-sm text-gray-600">
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">•</span>
                                <span>Be descriptive with your issue</span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">•</span>
                                <span>Have your transaction ID ready</span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">•</span>
                                <span>Typing indicator shows when agent is responding</span>
                            </li>
                            <li class="flex items-start">
                                <span class="text-green-500 mr-2">•</span>
                                <span>Chats are saved for future reference</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Main Chat Area -->
                <div class="lg:col-span-3">
                    <div class="bg-white rounded-2xl shadow-lg border border-gray-200/60 h-[calc(100vh-200px)] flex flex-col">
                        <!-- Chat Header -->
                        <div class="border-b border-gray-200 p-4 md:p-6">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h2 class="text-xl font-bold text-gray-900">Live Support Chat</h2>
                                    <p class="text-sm text-gray-600 mt-1">
                                        <span id="agentStatus">
                                            {{ $session->admin_id ? 'Connected with support agent' : 'Connecting you with an agent...' }}
                                        </span>
                                    </p>
                                </div>
                                <div class="flex items-center space-x-4">
                                    <div class="hidden md:block">
                                        <div id="typingIndicator" class="hidden text-sm text-gray-500">
                                            <span class="flex items-center">
                                                <span class="typing-dots">
                                                    <span>.</span><span>.</span><span>.</span>
                                                </span>
                                                <span class="ml-2">Agent is typing</span>
                                            </span>
                                        </div>
                                    </div>
                                    <span id="unreadBadge" class="hidden bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full">0</span>
                                </div>
                            </div>
                        </div>

                        <!-- Messages Container -->
                        <div id="chatMessages" class="flex-1 overflow-y-auto p-4 md:p-6 space-y-4">
                            <!-- Messages will be loaded here via AJAX -->
                            <div class="text-center py-8">
                                <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-green-600"></div>
                                <p class="text-gray-600 mt-2 text-sm">Loading chat...</p>
                            </div>
                        </div>

                        <!-- Chat Input -->
                        <div class="border-t border-gray-200 p-4 md:p-6">
                            <form id="chatForm" method="POST" class="space-y-3">
                                @csrf
                                <input type="hidden" id="sessionId" value="{{ $session->id }}">
                                
                                <div class="flex space-x-3">
                                    <div class="flex-1">
                                        <textarea id="messageInput" 
                                            rows="1"
                                            placeholder="Type your message here..."
                                            class="block w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200 resize-none"
                                            maxlength="1000"></textarea>
                                        <p id="charCount" class="text-xs text-gray-500 text-right mt-1 hidden">
                                            <span id="charCountText">0</span>/1000
                                        </p>
                                    </div>
                                    <button type="submit" 
                                            id="sendBtn"
                                            class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-bold px-6 py-3 rounded-xl shadow-lg hover:shadow-xl transition-all duration-200 flex-shrink-0">
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
                                    <div>
                                        <span>Chat will be saved automatically</span>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.typing-dots span {
    animation: typing 1.4s infinite;
    margin: 0 1px;
}
.typing-dots span:nth-child(2) {
    animation-delay: 0.2s;
}
.typing-dots span:nth-child(3) {
    animation-delay: 0.4s;
}
@keyframes typing {
    0%, 60%, 100% { transform: translateY(0); }
    30% { transform: translateY(-5px); }
}

.message-bubble {
    @apply max-w-[70%] rounded-2xl p-4;
}
.user-message {
    @apply bg-gradient-to-r from-green-500 to-green-600 text-white ml-auto;
}
.admin-message {
    @apply bg-gray-100 text-gray-800 mr-auto;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sessionId = document.getElementById('sessionId').value;
    const chatMessages = document.getElementById('chatMessages');
    const messageInput = document.getElementById('messageInput');
    const chatForm = document.getElementById('chatForm');
    const sendBtn = document.getElementById('sendBtn');
    const typingIndicator = document.getElementById('typingIndicator');
    const charCount = document.getElementById('charCount');
    const charCountText = document.getElementById('charCountText');
    const adminInfo = document.getElementById('adminInfo');
    const waitingForAdmin = document.getElementById('waitingForAdmin');
    const agentStatus = document.getElementById('agentStatus');
    const closeChatBtn = document.getElementById('closeChatBtn');
    
    let isTyping = false;
    let typingTimeout;
    let pollInterval;
    let lastMessageId = 0;

    // Auto-resize textarea
    messageInput.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
        
        // Show character count
        if (this.value.length > 0) {
            charCount.classList.remove('hidden');
            charCountText.textContent = this.value.length;
        } else {
            charCount.classList.add('hidden');
        }
        
        // Send typing status
        if (!isTyping && this.value.length > 0) {
            sendTypingStatus(true);
            isTyping = true;
        }
        
        clearTimeout(typingTimeout);
        typingTimeout = setTimeout(() => {
            if (isTyping) {
                sendTypingStatus(false);
                isTyping = false;
            }
        }, 1000);
    });

    // Send typing status
    function sendTypingStatus(status) {
        fetch('/chat/typing', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                session_id: sessionId,
                is_typing: status
            })
        });
    }

    // Load messages
    async function loadMessages() {
        try {
            const response = await fetch(`/chat/messages?session_id=${sessionId}`);
            const data = await response.json();
            
            if (data.messages && data.messages.length > 0) {
                renderMessages(data.messages);
                updateAdminInfo(data.session);
                
                // Store last message ID
                const lastMsg = data.messages[data.messages.length - 1];
                lastMessageId = lastMsg.id;
            }
        } catch (error) {
            console.error('Error loading messages:', error);
        }
    }

    // Render messages
    function renderMessages(messages) {
        chatMessages.innerHTML = '';
        
        messages.forEach(msg => {
            const isUser = msg.sender_type === 'user';
            const time = new Date(msg.created_at).toLocaleTimeString([], { 
                hour: '2-digit', 
                minute: '2-digit' 
            });
            
            const messageDiv = document.createElement('div');
            messageDiv.className = `flex ${isUser ? 'justify-end' : 'justify-start'}`;
            messageDiv.innerHTML = `
                <div class="message-bubble ${isUser ? 'user-message' : 'admin-message'}">
                    <div class="text-sm mb-1">
                        <span class="font-semibold">${msg.sender?.name || 'System'}</span>
                        <span class="opacity-75 ml-2 text-xs">${time}</span>
                    </div>
                    <div class="whitespace-pre-wrap">${escapeHtml(msg.message)}</div>
                </div>
            `;
            chatMessages.appendChild(messageDiv);
        });
        
        // Scroll to bottom
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    // Update admin info
    function updateAdminInfo(session) {
        if (session.admin_id) {
            adminInfo.classList.remove('hidden');
            waitingForAdmin.classList.add('hidden');
            agentStatus.textContent = 'Connected with support agent';
            
            // Fetch admin details
            fetch('/chat/admins')
                .then(res => res.json())
                .then(data => {
                    if (data.admins && data.admins.length > 0) {
                        const admin = data.admins.find(a => a.id == session.admin_id);
                        if (admin) {
                            document.getElementById('adminName').textContent = admin.name;
                        }
                    }
                });
        } else {
            adminInfo.classList.add('hidden');
            waitingForAdmin.classList.remove('hidden');
            agentStatus.textContent = 'Connecting you with an agent...';
        }
    }

    // Send message
    chatForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const message = messageInput.value.trim();
        if (!message) return;
        
        // Disable send button
        sendBtn.disabled = true;
        const originalText = sendBtn.innerHTML;
        sendBtn.innerHTML = '<span>Sending...</span><span class="animate-spin">⏳</span>';
        
        try {
            const response = await fetch('/chat/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    session_id: sessionId,
                    message: message
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                messageInput.value = '';
                messageInput.style.height = 'auto';
                charCount.classList.add('hidden');
                loadMessages();
            }
        } catch (error) {
            console.error('Error sending message:', error);
            alert('Failed to send message. Please try again.');
        } finally {
            sendBtn.disabled = false;
            sendBtn.innerHTML = originalText;
        }
    });

    // Close chat
    closeChatBtn.addEventListener('click', function() {
        if (confirm('Are you sure you want to end this chat session?')) {
            fetch('/chat/close', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    session_id: sessionId
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.href = '/dashboard';
                }
            });
        }
    });

    // Poll for new messages
    // Replace the pollMessages function in user chat
function pollMessages() {
    setInterval(async () => {
        try {
            const response = await fetch(`/chat/messages?session_id=${sessionId}&after=${lastMessageId}`);
            const data = await response.json();
            
            if (data.messages && data.messages.length > 0) {
                // Check for new messages
                const newMessages = data.messages.filter(msg => msg.id > lastMessageId);
                if (newMessages.length > 0) {
                    // Append new messages
                    newMessages.forEach(msg => {
                        renderMessages([msg]);
                        lastMessageId = Math.max(lastMessageId, msg.id);
                    });
                }
                
                // Update admin info if changed
                if (data.session && data.session.admin_id) {
                    updateAdminInfo(data.session);
                }
            }
        } catch (error) {
            console.error('Error polling messages:', error);
        }
    }, 2000); // Poll every 2 seconds
}

    // Utility function
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initial load
    loadMessages();
    pollMessages();
});
</script>
@endsection