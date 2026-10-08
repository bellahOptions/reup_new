@extends('admin.layouts.app')

@section('title', 'Message details')
@section('page-title', 'Message details')
@section('page-description', $message->subject)

@section('page-actions')
    <a href="{{ route('admin.contact.index') }}" class="btn btn-outline btn-sm">
        <x-icon name="arrow-left" class="h-4 w-4" />
        All messages
    </a>
@endsection

@section('content')
<div class="space-y-6" x-data="contactMessage">

    {{-- ========================== The message ========================= --}}
    <div class="card">
        <div class="card-header flex-row flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="card-title">{{ $message->subject }}</h2>
                <p class="card-description tabular-nums">
                    Reference #{{ str_pad($message->id, 6, '0', STR_PAD_LEFT) }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if($message->is_read)
                    <span class="badge badge-success">
                        <x-icon name="check" class="h-3 w-3" />
                        Read
                    </span>
                @else
                    <span class="badge badge-warning">
                        <x-icon name="inbox" class="h-3 w-3" />
                        Unread
                    </span>
                @endif

                {{-- Bound to Alpine so the reply handler can flip it without a reload. --}}
                <span class="badge" :class="responded ? 'badge-success' : 'badge-destructive'">
                    <x-icon :name="responded ? 'envelope' : 'clock'" class="h-3 w-3" />
                    <span x-text="responded ? 'Responded' : 'Pending'">{{ $message->is_responded ? 'Responded' : 'Pending' }}</span>
                </span>
            </div>
        </div>

        <div class="card-content space-y-5">
            <div class="flex items-center gap-3">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brand-50 text-lg font-semibold text-brand-700">
                    {{ substr($message->name, 0, 1) }}
                </span>
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ $message->name }}</p>
                    <div class="mt-0.5 flex flex-wrap items-center gap-3 text-sm">
                        <a href="mailto:{{ $message->email }}" class="link flex items-center gap-1.5">
                            <x-icon name="envelope" class="h-4 w-4" />
                            {{ $message->email }}
                        </a>

                        @if($message->user)
                            <a href="{{ route('admin.users.show', $message->user->id) }}" class="link flex items-center gap-1.5">
                                <x-icon name="user" class="h-4 w-4" />
                                Registered customer
                            </a>
                        @else
                            <span class="flex items-center gap-1.5 text-muted-foreground">
                                <x-icon name="user" class="h-4 w-4" />
                                Guest
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="whitespace-pre-wrap text-sm leading-relaxed">{{ $message->message }}</p>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4 text-sm text-muted-foreground">
                <span class="flex items-center gap-1.5">
                    <x-icon name="calendar" class="h-4 w-4" />
                    <span class="tabular-nums">Received {{ $message->created_at->format('M d, Y \a\t h:i A') }}</span>
                </span>
                <span>{{ $message->created_at->diffForHumans() }}</span>
            </div>
        </div>
    </div>

    {{-- ========================= Reply history ======================== --}}
    @if($message->replies && $message->replies->count() > 0)
        <div class="card">
            <div class="card-header">
                <h2 class="card-title flex items-center gap-2">
                    <x-icon name="inbox-stack" class="h-4 w-4 text-ink-500" />
                    Reply history
                </h2>
                <p class="card-description tabular-nums">{{ $message->replies->count() }} {{ Str::plural('reply', $message->replies->count()) }}</p>
            </div>

            <div class="card-content space-y-4">
                @foreach($message->replies->sortByDesc('sent_at') as $reply)
                    <div class="rounded-lg border border-border p-4">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="text-sm font-medium">{{ $reply->subject }}</p>
                                <p class="text-xs text-muted-foreground">Sent by {{ $reply->admin->name ?? 'Admin' }}</p>
                            </div>
                            <span class="text-xs tabular-nums text-muted-foreground">{{ $reply->sent_at->format('M d, Y h:i A') }}</span>
                        </div>
                        <p class="mt-3 whitespace-pre-wrap rounded-lg bg-surface p-3 text-sm">{{ $reply->message }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- ========================== Quick reply ====================== --}}
        <div class="lg:col-span-2">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title flex items-center gap-2">
                        <x-icon name="paper-airplane" class="h-4 w-4 text-ink-500" />
                        Quick reply
                    </h2>
                    <p class="card-description">Sent to {{ $message->email }} from the configured support address.</p>
                </div>

                <form id="replyForm" action="{{ route('admin.contact.reply', $message->id, false) }}" method="POST"
                      @submit.prevent="sendReply($event)">
                    @csrf

                    <div class="card-content space-y-4">
                        <div>
                            <label for="reply_subject" class="label mb-2">Subject</label>
                            <input type="text" id="reply_subject" name="subject" class="input"
                                   value="Re: {{ $message->subject }}" required>
                        </div>

                        <div>
                            <label for="reply_message" class="label mb-2">Your response</label>
                            <textarea id="reply_message" name="message" rows="8" class="textarea resize-none"
                                      x-model="reply" placeholder="Type your response here..." required></textarea>
                            <p class="mt-2 text-xs text-muted-foreground">Your response will be sent to {{ $message->email }}.</p>
                        </div>

                        <div>
                            <span class="label mb-2">Quick templates</span>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="btn btn-outline btn-sm" @click="insertTemplate('greeting')">Greeting</button>
                                <button type="button" class="btn btn-outline btn-sm" @click="insertTemplate('thanks')">Thank you</button>
                                <button type="button" class="btn btn-outline btn-sm" @click="insertTemplate('support')">Support info</button>
                                <button type="button" class="btn btn-outline btn-sm" @click="insertTemplate('closing')">Closing</button>
                            </div>
                        </div>

                        <p class="rounded-lg border px-3 py-2 text-sm"
                           :class="status.type === 'success' ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700'"
                           x-show="status.message" x-cloak x-text="status.message"></p>
                    </div>

                    <div class="card-footer justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <button type="submit" class="btn btn-primary" :disabled="sending">
                                <x-icon name="paper-airplane" class="h-4 w-4" />
                                <span x-text="sending ? 'Sending...' : 'Send reply'">Send reply</span>
                            </button>
                            <button type="button" class="btn btn-outline btn-icon" aria-label="Copy email address"
                                    @click="copyEmail('{{ $message->email }}')">
                                <x-icon name="document-duplicate" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        {{-- ============================ Sidebar ======================== --}}
        <div class="space-y-6 lg:col-span-1">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title flex items-center gap-2">
                        <x-icon name="bolt" class="h-4 w-4 text-ink-500" />
                        Actions
                    </h2>
                </div>

                <div class="card-content space-y-2">
                    @if(!$message->is_read)
                        <button type="button" class="btn btn-outline w-full" @click="markAsRead({{ $message->id }})">
                            <x-icon name="check-circle" class="h-4 w-4" />
                            Mark as read
                        </button>
                    @endif

                    @if(!$message->is_responded)
                        <button type="button" class="btn btn-outline w-full" @click="markAsResponded({{ $message->id }})">
                            <x-icon name="check" class="h-4 w-4" />
                            Mark as responded
                        </button>
                    @endif

                    <a href="mailto:{{ $message->email }}" class="btn btn-outline w-full">
                        <x-icon name="envelope" class="h-4 w-4" />
                        Open in email client
                    </a>

                    <button type="button" class="btn btn-outline w-full" @click="window.print()">
                        <x-icon name="printer" class="h-4 w-4" />
                        Print
                    </button>

                    <button type="button" class="btn btn-destructive w-full" @click="deleteMessage({{ $message->id }})">
                        <x-icon name="trash" class="h-4 w-4" />
                        Delete message
                    </button>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title flex items-center gap-2">
                        <x-icon name="information-circle" class="h-4 w-4 text-ink-500" />
                        Information
                    </h2>
                </div>

                <div class="card-content space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-3 border-b border-border pb-3">
                        <span class="stat-label">Subject</span>
                        <span class="text-right font-medium">{{ $message->subject }}</span>
                    </div>
                    <div class="flex items-start justify-between gap-3 border-b border-border pb-3">
                        <span class="stat-label">Status</span>
                        <span class="font-medium" :class="responded ? 'text-green-700' : 'text-amber-700'"
                              x-text="responded ? 'Responded' : 'Pending'">{{ $message->is_responded ? 'Responded' : 'Pending' }}</span>
                    </div>
                    <div class="flex items-start justify-between gap-3 border-b border-border pb-3">
                        <span class="stat-label">Received</span>
                        <span class="font-medium">{{ $message->created_at->diffForHumans() }}</span>
                    </div>
                    <div class="flex items-start justify-between gap-3 border-b border-border pb-3">
                        <span class="stat-label">IP address</span>
                        <span class="font-medium tabular-nums">{{ request()->ip() }}</span>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <span class="stat-label">Customer type</span>
                        <span class="font-medium">{{ $message->user ? 'Registered' : 'Guest' }}</span>
                    </div>
                </div>
            </div>

            @if($message->user)
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title flex items-center gap-2">
                            <x-icon name="user" class="h-4 w-4 text-ink-500" />
                            Customer account
                        </h2>
                    </div>

                    <div class="card-content space-y-4">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700">
                                {{ substr($message->user->name, 0, 1) }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-medium">{{ $message->user->name }}</p>
                                <p class="text-xs tabular-nums text-muted-foreground">ID #{{ str_pad($message->user->id, 6, '0', STR_PAD_LEFT) }}</p>
                            </div>
                        </div>

                        <dl class="space-y-2 border-t border-border pt-3 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <dt class="stat-label">Email</dt>
                                <dd class="truncate font-medium">{{ $message->user->email }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <dt class="stat-label">Phone</dt>
                                <dd class="font-medium tabular-nums">{{ $message->user->phone ?? 'N/A' }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <dt class="stat-label">Wallet</dt>
                                <dd class="font-medium tabular-nums">&#8358;{{ number_format($message->user->wallet_balance ?? 0, 2) }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <dt class="stat-label">Joined</dt>
                                <dd class="font-medium">{{ $message->user->created_at->format('M Y') }}</dd>
                            </div>
                        </dl>

                        <a href="{{ route('admin.users.show', $message->user->id) }}" class="btn btn-outline w-full">
                            <x-icon name="arrow-up-right" class="h-4 w-4" />
                            View full profile
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // ---------------------------------------------------------------
    // Contact message actions
    // ---------------------------------------------------------------
    // Endpoints and payloads are unchanged. The reply form, the status
    // buttons and the reply templates were previously wired with
    // `onclick="..."` globals and innerHTML string building; they now run
    // inside one Alpine component, so the reply status badge updates
    // in place instead of being rewritten from a query selector.
    document.addEventListener('alpine:init', () => {
        Alpine.data('contactMessage', () => ({
            responded: @json((bool) $message->is_responded),
            reply: '',
            sending: false,
            status: { type: '', message: '' },
            templates: {
                greeting: @json("Dear {$message->name},\n\nThank you for contacting us. "),
                thanks: @json("Thank you for reaching out to us. We appreciate your message and will assist you promptly.\n\n"),
                support: @json("Our support team is here to help you. If you need further assistance, please contact us at:\n\nEmail: reup.bellahoptions@gmail.com\nPhone: +234 903 141 2354\nLive chat: available 24/7 on our website\n\n"),
                closing: @json("\n\nBest regards,\nThe " . config('app.name') . " Support Team"),
            },

            insertTemplate(name) {
                const textarea = document.getElementById('reply_message');
                if (!textarea) return;

                const template = this.templates[name] ?? '';
                const start = textarea.selectionStart ?? textarea.value.length;

                this.reply = textarea.value.substring(0, start) + template + textarea.value.substring(start);

                this.$nextTick(() => {
                    const caret = start + template.length;
                    textarea.focus();
                    textarea.setSelectionRange(caret, caret);
                });
            },

            async sendReply(event) {
                const form = event.target;
                this.sending = true;
                this.status = { type: '', message: '' };

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                    });

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'Failed to send the reply.');
                    }

                    this.responded = true;
                    this.reply = '';
                    this.status = { type: 'success', message: data.message || 'Reply sent.' };

                    setTimeout(() => window.location.reload(), 2000);
                } catch (error) {
                    this.status = { type: 'error', message: error.message || 'Failed to send the reply.' };
                } finally {
                    this.sending = false;
                }
            },

            async markAsRead(id) {
                await this.post(`{{ route('admin.contact.mark-read', '') }}/${id}`, true);
            },

            async markAsResponded(id) {
                this.responded = true;
                await this.post(`{{ route('admin.contact.mark-responded', '') }}/${id}`, true);
            },

            async post(url, reload = false) {
                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                    });

                    if (reload && response.ok) {
                        window.location.reload();
                    }
                } catch (error) {
                    alert('The action failed. Please try again.');
                }
            },

            async deleteMessage(id) {
                if (!confirm('Are you sure you want to delete this message? This cannot be undone.')) return;

                try {
                    const response = await fetch(`{{ route('admin.contact.delete', '') }}/${id}`, {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                    });

                    if (!response.ok) throw new Error('Failed to delete the message.');

                    window.location.href = '{{ route('admin.contact.index') }}';
                } catch (error) {
                    alert('Failed to delete the message. Please try again.');
                }
            },

            copyEmail(email) {
                navigator.clipboard.writeText(email)
                    .then(() => alert('Email address copied.'))
                    .catch(() => alert('Failed to copy the email address.'));
            },
        }));
    });
</script>
@endpush
