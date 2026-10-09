@extends('admin.layouts.app')

@section('title', 'Live chat')
@section('page-title', 'Live chat')
@section('page-description', 'Pick up customer conversations and reply in real time.')

@section('content')
<div class="space-y-6" x-data="liveChat">

    {{-- =========================== Toolbar ============================ --}}
    <div class="card">
        <div class="card-content flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                    <x-icon name="chat-bubble-left-right" class="h-4 w-4" />
                </span>
                <div>
                    <p class="text-sm font-medium">Support availability</p>
                    <p class="text-xs text-muted-foreground">
                        <span class="tabular-nums" x-text="stats.active">0</span> active &middot;
                        <span class="tabular-nums" x-text="stats.pending">0</span> waiting &middot;
                        <span class="tabular-nums" x-text="stats.closed">0</span> closed today
                    </p>
                </div>
            </div>

            <button type="button" class="btn btn-sm"
                    :class="available ? 'btn-primary' : 'btn-outline'"
                    :aria-pressed="available ? 'true' : 'false'"
                    @click="toggleAvailability()">
                {{-- Blade compiles a component's :name attribute at render time,
                     long before Alpine runs, so an Alpine expression here
                     ("available ? … : …") is parsed as a PHP constant and
                     throws "Undefined constant". Both icons are rendered and
                     toggled with x-show instead. --}}
                <x-icon name="check-circle" class="h-4 w-4" x-show="available" />
                <x-icon name="pause-circle" class="h-4 w-4" x-show="!available" x-cloak />
                <span x-text="available ? 'Available' : 'Busy'">Available</span>
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">

        {{-- ============================ Sidebar ======================== --}}
        <div class="space-y-6 lg:col-span-1">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title flex items-center gap-2">
                        <x-icon name="chart-bar" class="h-4 w-4 text-ink-500" />
                        Chat stats
                    </h2>
                </div>
                <div class="card-content space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="stat-label">Active chats</span>
                        <span class="text-sm font-semibold tabular-nums text-green-700" x-text="stats.active">0</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="stat-label">Waiting</span>
                        <span class="text-sm font-semibold tabular-nums text-amber-700" x-text="stats.pending">0</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="stat-label">Closed today</span>
                        <span class="text-sm font-semibold tabular-nums text-sky-700" x-text="stats.closed">0</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title flex items-center gap-2">
                        <x-icon name="users" class="h-4 w-4 text-ink-500" />
                        Online team
                    </h2>
                </div>
                <div class="card-content">
                    <template x-if="!admins.length">
                        <p class="text-sm text-muted-foreground">No other administrators online.</p>
                    </template>

                    <ul class="space-y-3">
                        <template x-for="admin in admins" :key="admin.id">
                            <li class="flex items-center justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700"
                                          x-text="initial(admin.name)"></span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium" x-text="admin.name"></p>
                                        <p class="truncate text-xs text-muted-foreground" x-text="admin.admin_role"></p>
                                    </div>
                                </div>
                                <span class="h-2 w-2 shrink-0 rounded-full bg-green-500" aria-hidden="true"></span>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title flex items-center gap-2">
                        <x-icon name="funnel" class="h-4 w-4 text-ink-500" />
                        Filters
                    </h2>
                </div>
                <div class="card-content space-y-2">
                    <span class="label">Status</span>
                    @foreach(['all' => 'All chats', 'active' => 'Active', 'pending' => 'Pending', 'closed' => 'Closed'] as $value => $label)
                        <label class="flex items-center gap-2 text-sm" for="statusFilter_{{ $value }}">
                            <input type="radio" id="statusFilter_{{ $value }}" name="statusFilter" value="{{ $value }}"
                                   class="checkbox" x-model="statusFilter"
                                   {{ $value === 'all' ? 'checked' : '' }}>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ========================== Chat pane ======================== --}}
        <div class="lg:col-span-3">
            <div class="card overflow-hidden">
                <div class="card-header flex-row items-center justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="card-title">Customer support</h2>
                        <p class="card-description truncate" x-text="current ? `Chat with ${current.name} \u2022 ${current.email}` : 'Select a chat to begin'">
                            Select a chat to begin
                        </p>
                    </div>
                    <span class="badge badge-destructive" x-show="unreadCount > 0" x-cloak x-text="unreadCount"></span>
                </div>

                <div class="flex h-[32rem]">
                    {{-- Chat list --}}
                    <div class="scrollbar-slim w-1/3 overflow-y-auto border-r border-border">
                        <template x-if="!sessions.length">
                            <div class="empty-state py-10">
                                <x-icon name="chat-bubble-oval-left" class="h-8 w-8 text-ink-300" />
                                <p class="mt-2 text-sm text-muted-foreground">No chats in this view.</p>
                            </div>
                        </template>

                        <ul class="divide-y divide-border">
                            <template x-for="session in sessions" :key="session.id">
                                <li>
                                    <button type="button" class="w-full px-4 py-3 text-left transition-colors hover:bg-ink-50"
                                            :class="currentId === session.id && 'bg-brand-50'"
                                            @click="selectChat(session.id)">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="flex min-w-0 items-center gap-2.5">
                                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface text-xs font-semibold text-ink-700 ring-1 ring-border"
                                                      x-text="initial(session.user?.name)"></span>
                                                <div class="min-w-0">
                                                    <p class="truncate text-sm font-medium" x-text="session.user?.name"></p>
                                                    <p class="text-xs tabular-nums text-muted-foreground" x-text="'#' + String(session.id).padStart(6, '0')"></p>
                                                </div>
                                            </div>
                                            <span class="badge shrink-0" :class="session.status === 'active' ? 'badge-success' : 'badge-warning'"
                                                  x-text="session.status"></span>
                                        </div>
                                        <p class="mt-2 truncate text-xs text-muted-foreground"
                                           x-text="session.last_message ? session.last_message.substring(0, 60) : 'No messages yet'"></p>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>

                    {{-- Messages --}}
                    <div class="flex w-2/3 flex-col">
                        <div class="scrollbar-slim flex-1 space-y-4 overflow-y-auto p-5" x-ref="messages">
                            <template x-if="!messages.length">
                                <div class="empty-state py-10">
                                    <x-icon name="chat-bubble-left-right" class="h-8 w-8 text-ink-300" />
                                    <p class="mt-2 text-sm text-muted-foreground"
                                       x-text="current ? 'No messages yet.' : 'Select a chat from the list to start the conversation.'"></p>
                                </div>
                            </template>

                            <template x-for="message in messages" :key="message.id">
                                <div class="flex" :class="message.sender_type === 'admin' ? 'justify-end' : 'justify-start'">
                                    <div class="max-w-[75%] rounded-xl px-4 py-2.5 text-sm"
                                         :class="message.sender_type === 'admin' ? 'bg-brand-500 text-primary-foreground' : 'bg-surface text-foreground ring-1 ring-border'">
                                        <div class="mb-1 flex items-center gap-2 text-xs"
                                             :class="message.sender_type === 'admin' ? 'text-primary-foreground/80' : 'text-muted-foreground'">
                                            <span class="font-medium" x-text="message.sender_type === 'admin' ? 'You' : (message.sender?.name ?? 'Customer')"></span>
                                            <span class="tabular-nums" x-text="formatTime(message.created_at)"></span>
                                        </div>
                                        <p class="whitespace-pre-wrap" x-text="message.message"></p>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="border-t border-border p-4">
                            <form @submit.prevent="sendMessage()" class="space-y-3">
                                <input type="hidden" x-model="currentId">
                                <textarea rows="2" maxlength="1000" x-model="draft" :disabled="!currentId"
                                          @input="notifyTyping()"
                                          placeholder="Type your response..."
                                          class="textarea resize-none disabled:bg-surface"></textarea>
                                <p class="text-right text-xs tabular-nums text-muted-foreground"
                                   x-show="draft.length" x-cloak x-text="`${draft.length}/1000`"></p>

                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-3">
                                        <button type="button" class="btn btn-ghost btn-sm text-destructive"
                                                x-show="currentId" x-cloak @click="closeChat()">
                                            <x-icon name="no-symbol" class="h-4 w-4" />
                                            End chat
                                        </button>
                                        <button type="button" class="btn btn-ghost btn-sm"
                                                x-show="currentId" x-cloak @click="transferChat()">
                                            <x-icon name="arrow-path" class="h-4 w-4" />
                                            Transfer
                                        </button>
                                    </div>

                                    <button type="submit" class="btn btn-primary btn-sm" :disabled="!currentId || !draft.trim()">
                                        <x-icon name="paper-airplane" class="h-4 w-4" />
                                        Send
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // ---------------------------------------------------------------
    // Live chat console
    // ---------------------------------------------------------------
    // Endpoints, payloads and the three-second poll are unchanged. The two
    // lists and the message thread used to be rendered by string-concatenating
    // innerHTML from fetch handlers and wired with `onclick="selectChat(...)"`,
    // which is why customer names were interpolated into markup unescaped.
    // They are now Alpine templates, so `x-text` escapes every value.
    document.addEventListener('alpine:init', () => {
        Alpine.data('liveChat', () => ({
            sessions: [],
            admins: [],
            messages: [],
            stats: { active: 0, pending: 0, closed: 0 },
            currentId: null,
            current: null,
            draft: '',
            available: true,
            statusFilter: 'all',
            _poll: null,
            _activity: null,

            get unreadCount() {
                return this.sessions.reduce((total, session) => total + (session.unread_count || 0), 0);
            },

            init() {
                this.loadSessions();
                this.loadAdmins();
                this.loadStats();

                this._poll = setInterval(() => {
                    if (this.currentId) this.loadMessages(this.currentId);
                    this.loadSessions();
                    this.loadAdmins();
                    this.loadStats();
                }, 3000);

                this._activity = setInterval(() => this.post('{{ route('admin.update.activity') }}'), 60000);

                this.$watch('statusFilter', () => this.loadSessions());
            },

            initial(name) {
                return (name || '?').charAt(0).toUpperCase();
            },

            formatTime(value) {
                return new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            },

            headers(json = false) {
                const base = {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'Accept': 'application/json',
                };

                return json ? { ...base, 'Content-Type': 'application/json' } : base;
            },

            async post(url, body) {
                return fetch(url, {
                    method: 'POST',
                    headers: this.headers(body !== undefined),
                    credentials: 'same-origin',
                    body: body === undefined ? undefined : JSON.stringify(body),
                });
            },

            async loadSessions() {
                try {
                    const response = await fetch(`{{ route('admin.chat.sessions') }}?status=${encodeURIComponent(this.statusFilter)}`, {
                        headers: this.headers(),
                        credentials: 'same-origin',
                    });
                    const data = await response.json();
                    this.sessions = data.sessions ?? [];
                } catch (error) {
                    // Transient failure: keep the previous list.
                }
            },

            async loadAdmins() {
                try {
                    const response = await fetch('{{ route('admin.chat.online-admins') }}', {
                        headers: this.headers(),
                        credentials: 'same-origin',
                    });
                    const data = await response.json();
                    this.admins = data.admins ?? [];
                } catch (error) {
                    // Transient failure: keep the previous list.
                }
            },

            async loadStats() {
                try {
                    const response = await fetch('{{ route('admin.chat.stats') }}', {
                        headers: this.headers(),
                        credentials: 'same-origin',
                    });
                    this.stats = await response.json();
                } catch (error) {
                    // Transient failure: keep the previous figures.
                }
            },

            async selectChat(sessionId) {
                this.currentId = sessionId;
                await this.loadMessages(sessionId);
            },

            async loadMessages(sessionId) {
                try {
                    const response = await fetch(`{{ route('admin.chat.messages', '') }}/${sessionId}/messages`, {
                        headers: this.headers(),
                        credentials: 'same-origin',
                    });
                    const data = await response.json();

                    this.messages = data.messages ?? [];
                    this.current = data.user ?? null;

                    this.$nextTick(() => {
                        const box = this.$refs.messages;
                        if (box) box.scrollTop = box.scrollHeight;
                    });
                } catch (error) {
                    // Transient failure: keep the previous thread.
                }
            },

            async sendMessage() {
                const message = this.draft.trim();
                if (!message || !this.currentId) return;

                try {
                    const response = await this.post('{{ route('admin.chat.send') }}', {
                        session_id: this.currentId,
                        message,
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.draft = '';
                        await this.loadMessages(this.currentId);
                    }
                } catch (error) {
                    alert('Failed to send the message. Please try again.');
                }
            },

            notifyTyping() {
                if (!this.currentId) return;

                this.post('{{ route('admin.chat.typing') }}', {
                    session_id: this.currentId,
                    is_typing: true,
                });
            },

            async toggleAvailability() {
                this.available = !this.available;
                await this.post('{{ route('admin.chat.availability') }}', { is_available: this.available });
            },

            async closeChat() {
                if (!this.currentId) return;
                if (!confirm('End this chat session?')) return;

                await this.post('{{ route('admin.chat.close') }}', { session_id: this.currentId });

                this.currentId = null;
                this.current = null;
                this.messages = [];
                await this.loadSessions();
            },

            async transferChat() {
                if (!this.currentId) return;

                const adminId = prompt('Administrator ID to transfer this chat to:');
                if (!adminId) return;

                await this.post('{{ route('admin.chat.transfer') }}', {
                    session_id: this.currentId,
                    admin_id: adminId,
                });

                await this.loadSessions();
            },
        }));
    });
</script>
@endpush
