@extends('admin.layouts.app')

@section('title', 'Contact Messages')
@section('page-title', 'Messages')
@section('page-description', 'Customer enquiries submitted through the contact form.')

@section('content')
<div class="space-y-6">

    {{-- ============================ Metrics ============================ --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Total messages</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <x-icon name="envelope" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['total'] ?? 0) }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Unread</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-700">
                        <x-icon name="inbox" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['unread'] ?? 0) }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Awaiting response</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-50 text-red-700">
                        <x-icon name="clock" variant="solid" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['pending'] ?? 0) }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <div class="flex items-start justify-between gap-3">
                    <span class="stat-label">Received today</span>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-green-50 text-green-700">
                        <x-icon name="inbox-stack" class="h-4 w-4" />
                    </span>
                </div>
                <p class="stat-value mt-2">{{ number_format($stats['today'] ?? 0) }}</p>
            </div>
        </div>
    </div>

    {{-- ============================ Inbox ============================= --}}
    <div class="card">
        <div class="card-header flex-row flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="card-title">All messages</h2>
                <p class="card-description tabular-nums">
                    Showing {{ $messages->firstItem() ?? 0 }}&ndash;{{ $messages->lastItem() ?? 0 }} of {{ number_format($messages->total()) }}
                </p>
            </div>
        </div>

        @if($messages->count())
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Sender</th>
                            <th>Subject</th>
                            <th>Message</th>
                            <th>Status</th>
                            <th>Received</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($messages as $message)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700">
                                            {{ strtoupper(substr($message->name ?? 'U', 0, 1)) }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate font-medium">
                                                {{ $message->name }}
                                                @unless($message->is_read)
                                                    <span class="sr-only">(unread)</span>
                                                @endunless
                                            </p>
                                            <p class="truncate text-xs text-muted-foreground">{{ $message->email }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="font-medium">{{ $message->subject }}</td>
                                <td class="max-w-md">
                                    <p class="truncate text-sm text-muted-foreground">{{ Str::limit($message->message, 60) }}</p>
                                </td>
                                <td>
                                    <div class="flex flex-wrap items-center gap-1">
                                        @if(!$message->is_read)
                                            <span class="badge badge-warning">
                                                <x-icon name="envelope" class="h-3 w-3" />
                                                Unread
                                            </span>
                                        @else
                                            <span class="badge badge-info">
                                                <x-icon name="check" class="h-3 w-3" />
                                                Read
                                            </span>
                                        @endif

                                        @if($message->is_responded)
                                            <span class="badge badge-success">
                                                <x-icon name="paper-airplane" class="h-3 w-3" />
                                                Responded
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-sm text-muted-foreground">
                                    <span class="tabular-nums">{{ $message->created_at->format('M d, Y') }}</span><br>
                                    <span class="text-xs tabular-nums">{{ $message->created_at->format('h:i A') }}</span>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end">
                                        <a href="{{ route('admin.contact.show', $message->id) }}" class="btn btn-outline btn-sm">
                                            <x-icon name="eye" class="h-4 w-4" />
                                            View
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($messages->hasPages())
                <div class="border-t border-border p-4">
                    {{ $messages->withQueryString()->links() }}
                </div>
            @endif
        @else
            <div class="empty-state">
                <x-icon name="envelope" class="h-10 w-10 text-ink-300" />
                <p class="mt-3 font-medium">No contact messages yet</p>
                <p class="mt-1 text-sm text-muted-foreground">Enquiries from the contact form will appear here.</p>
            </div>
        @endif
    </div>
</div>
@endsection
