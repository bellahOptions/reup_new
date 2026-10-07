@extends('layouts.app')

@section('title', 'Live chat')

@section('content')
@php
    $me = auth()->user();
@endphp

<div class="container-page py-8"
     x-data="liveChat({
         sessionId: {{ $session?->id ?? 'null' }},
         endpoints: {
             messages: @js(route('chat.messages')),
             send: @js(route('chat.send')),
             typing: @js(route('chat.typing')),
             close: @js(route('chat.close')),
         },
         csrf: @js(csrf_token()),
         dashboard: @js(route('dashboard')),
     })"
     x-init="start()">

    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">Live chat</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Talk to a support agent in real time. Your conversation is saved to your account.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <span class="badge"
                  :class="connected ? 'badge-success' : 'badge-warning'">
                <span class="h-1.5 w-1.5 rounded-full"
                      :class="connected ? 'bg-green-500' : 'bg-amber-500'"></span>
                <span x-text="connected ? 'Connected' : 'Reconnecting'">Connected</span>
            </span>

            <button type="button"
                    @click="endChat()"
                    :disabled="!sessionId"
                    class="btn btn-outline btn-sm text-red-600 hover:bg-red-50 disabled:opacity-50">
                <x-icon name="x-mark" class="h-4 w-4" />
                End chat
            </button>
        </div>
    </div>

    @if(! $session)
        <div class="card">
            <div class="empty-state">
                <x-icon name="chat-bubble-left-right" class="h-8 w-8 text-ink-300" />
                <h2 class="mt-3 text-base font-semibold">Chat is unavailable right now</h2>
                <p class="mt-1 max-w-sm text-sm text-muted-foreground">
                    We could not open a support session for your account. Email us instead and we will reply within one business day.
                </p>
                <a href="{{ route('contact') }}" class="btn btn-primary mt-5">
                    <x-icon name="envelope" class="h-4 w-4" />
                    Contact support
                </a>
            </div>
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-3">

            {{-- Conversation --}}
            <div class="card flex flex-col lg:col-span-2" style="height: min(70vh, 640px);">
                <div class="card-header flex-row items-center justify-between">
                    <div class="min-w-0">
                        <h2 class="card-title truncate">
                            <span x-text="agentName || 'Waiting for an agent'">Waiting for an agent</span>
                        </h2>
                        <p class="card-description">
                            Session <span class="font-mono">#{{ str_pad((string) $session->id, 6, '0', STR_PAD_LEFT) }}</span>
                        </p>
                    </div>

                    <span x-show="agentTyping" x-cloak
                          class="badge badge-neutral">
                        <span class="typing-dots"><span></span><span></span><span></span></span>
                        Agent is typing
                    </span>
                </div>

                {{-- Messages --}}
                <div x-ref="scroller"
                     class="scrollbar-slim flex-1 space-y-4 overflow-y-auto p-5"
                     aria-live="polite"
                     aria-relevant="additions">
                    <template x-if="loading">
                        <div class="flex items-center justify-center py-10 text-sm text-muted-foreground">
                            <span class="mr-2 h-4 w-4 animate-spin rounded-full border-2 border-ink-200 border-t-brand-500"></span>
                            Loading conversation…
                        </div>
                    </template>

                    <template x-if="!loading && messages.length === 0">
                        <div class="empty-state py-10">
                            <x-icon name="chat-bubble-oval-left" class="h-7 w-7 text-ink-300" />
                            <p class="mt-2 text-sm text-muted-foreground">
                                Send a message and an agent will pick it up.
                            </p>
                        </div>
                    </template>

                    <template x-for="message in messages" :key="message.id">
                        <div class="flex" :class="message.mine ? 'justify-end' : 'justify-start'">
                            <div class="max-w-[80%]">
                                <div class="mb-1 flex items-center gap-2 text-xs text-muted-foreground"
                                     :class="message.mine && 'justify-end'">
                                    <span class="font-medium" x-text="message.author"></span>
                                    <span x-text="message.time"></span>
                                </div>
                                <div class="rounded-2xl px-4 py-2.5 text-sm leading-relaxed whitespace-pre-wrap"
                                     :class="message.mine
                                         ? 'rounded-br-md bg-brand-500 text-white'
                                         : 'rounded-bl-md bg-ink-100 text-ink-900'"
                                     x-text="message.body"></div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Composer --}}
                <form class="border-t p-4" @submit.prevent="send()">
                    <div class="flex items-end gap-3">
                        <div class="min-w-0 flex-1">
                            <label for="chat-message" class="sr-only">Message</label>
                            <textarea
                                id="chat-message"
                                x-ref="input"
                                x-model="draft"
                                @input="notifyTyping()"
                                @keydown.enter.prevent="send()"
                                rows="2"
                                maxlength="1000"
                                placeholder="Type your message, then press Enter…"
                                class="textarea resize-none"></textarea>
                            <p class="mt-1 text-right text-xs text-muted-foreground" x-show="draft.length > 800" x-cloak>
                                <span x-text="draft.length"></span>/1000
                            </p>
                        </div>

                        <button type="submit"
                                class="btn btn-primary"
                                :disabled="sending || !draft.trim()">
                            <x-icon name="paper-airplane" class="h-4 w-4" />
                            <span x-text="sending ? 'Sending' : 'Send'">Send</span>
                        </button>
                    </div>

                    <p class="mt-2 text-xs text-muted-foreground" x-show="error" x-cloak x-text="error"></p>
                </form>
            </div>

            {{-- Sidebar --}}
            <div class="space-y-6">
                <div class="card">
                    <div class="card-content">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-500 text-sm font-semibold text-white">
                                {{ strtoupper(substr($me->name, 0, 1)) }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">{{ $me->name }}</p>
                                <p class="truncate text-xs text-muted-foreground">{{ $me->email }}</p>
                            </div>
                        </div>

                        <dl class="mt-5 space-y-2 border-t pt-4 text-sm">
                            <div class="flex items-center justify-between">
                                <dt class="text-muted-foreground">Status</dt>
                                <dd class="font-medium">{{ ucfirst($session->status) }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt class="text-muted-foreground">Opened</dt>
                                <dd class="font-medium">{{ $session->created_at?->format('M j, Y') }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Getting a faster answer</h2>
                    </div>
                    <div class="card-content">
                        <ul class="space-y-3 text-sm">
                            @foreach([
                                ['Quote your transaction reference — it starts with TXN, FND or RFND.', 'receipt-percent'],
                                ['Describe what you expected to happen, not just what went wrong.', 'pencil-square'],
                                ['Paste the exact error text if you saw one.', 'clipboard-document-list'],
                                ['Never share your password or card details in chat.', 'lock-closed'],
                            ] as [$tip, $icon])
                                <li class="flex items-start gap-2.5">
                                    <x-icon :name="$icon" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                                    <span class="text-muted-foreground">{{ $tip }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="card">
                    <div class="card-content">
                        <p class="text-sm text-muted-foreground">
                            Prefer email? Write to
                            <a href="mailto:{{ config('services.support.email') }}" class="link font-medium">
                                {{ config('services.support.email') }}
                            </a>
                            and we will reply within one business day.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    function liveChat(config) {
        return {
            sessionId: config.sessionId,
            endpoints: config.endpoints,
            csrf: config.csrf,
            dashboard: config.dashboard,

            messages: [],
            draft: '',
            loading: true,
            sending: false,
            connected: true,
            error: '',
            agentName: '',
            agentTyping: false,

            lastId: 0,
            pollTimer: null,
            typingTimer: null,
            typingSent: false,

            start() {
                if (!this.sessionId) {
                    this.loading = false;
                    return;
                }

                this.fetchMessages();

                // 4s rather than the previous 2s: the endpoint already scopes
                // to this session, and halving the rate halves the DB load for
                // no perceptible latency difference.
                this.pollTimer = setInterval(() => this.fetchMessages(), 4000);

                // Release the interval if the tab is hidden or unloaded, so a
                // backgrounded dashboard does not poll forever.
                document.addEventListener('visibilitychange', () => {
                    if (document.hidden) {
                        clearInterval(this.pollTimer);
                    } else {
                        this.pollTimer = setInterval(() => this.fetchMessages(), 4000);
                    }
                });
            },

            async fetchMessages() {
                try {
                    const url = new URL(this.endpoints.messages, window.location.origin);
                    url.searchParams.set('session_id', this.sessionId);

                    const res = await fetch(url, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });

                    if (!res.ok) throw new Error('Request failed');

                    const data = await res.json();
                    this.connected = true;
                    this.applyMessages(data.messages || []);
                    this.applySession(data.session || {});
                } catch (e) {
                    this.connected = false;
                } finally {
                    this.loading = false;
                }
            },

            applyMessages(rows) {
                if (!rows.length) {
                    this.$nextTick(() => this.scrollToEnd());
                    return;
                }

                const shouldStick = this.isNearBottom();
                const mapped = rows.map((row) => this.mapMessage(row));

                // Replace outright: the endpoint returns the whole thread, so
                // appending produced duplicates on every poll.
                this.messages = mapped;
                this.lastId = Math.max(...mapped.map((m) => m.id), 0);

                if (shouldStick) {
                    this.$nextTick(() => this.scrollToEnd());
                }
            },

            mapMessage(row) {
                const isMine = row.sender_id === {{ auth()->id() }};

                return {
                    id: row.id,
                    mine: isMine,
                    author: row.sender?.name || (isMine ? 'You' : 'Support'),
                    body: row.message ?? '',
                    time: row.created_at
                        ? new Date(row.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                        : '',
                };
            },

            applySession(session) {
                this.agentName = session.admin_id
                    ? (session.admin?.name || 'Support agent')
                    : '';
            },

            async send() {
                const body = this.draft.trim();
                if (!body || this.sending) return;

                this.sending = true;
                this.error = '';

                try {
                    const res = await fetch(this.endpoints.send, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': this.csrf,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ session_id: this.sessionId, message: body }),
                    });

                    const data = await res.json().catch(() => ({}));

                    if (!res.ok || !data.success) {
                        throw new Error(data.message || 'Message could not be sent.');
                    }

                    this.draft = '';
                    this.typingSent = false;
                    await this.fetchMessages();
                } catch (e) {
                    this.error = e.message || 'Message could not be sent. Please try again.';
                } finally {
                    this.sending = false;
                    this.$nextTick(() => this.$refs.input?.focus());
                }
            },

            notifyTyping() {
                if (this.typingSent || !this.draft.trim()) return;

                this.typingSent = true;
                this.post(this.endpoints.typing, { session_id: this.sessionId, is_typing: true });

                clearTimeout(this.typingTimer);
                this.typingTimer = setTimeout(() => {
                    this.typingSent = false;
                    this.post(this.endpoints.typing, { session_id: this.sessionId, is_typing: false });
                }, 1500);
            },

            endChat() {
                if (!confirm('End this chat session? You can start a new one at any time.')) return;

                this.post(this.endpoints.close, { session_id: this.sessionId })
                    .then(() => { window.location.href = this.dashboard; });
            },

            post(url, payload) {
                return fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrf,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                });
            },

            isNearBottom() {
                const el = this.$refs.scroller;
                if (!el) return true;

                return el.scrollHeight - el.scrollTop - el.clientHeight < 120;
            },

            scrollToEnd() {
                const el = this.$refs.scroller;
                if (el) el.scrollTop = el.scrollHeight;
            },
        };
    }
</script>
@endpush
