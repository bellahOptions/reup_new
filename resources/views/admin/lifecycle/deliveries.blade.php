@extends('admin.layouts.app')

@section('title', 'Delivery log')
@section('page-title', 'Delivery log')
@section('page-description', 'Every message the module has scheduled, sent or refused.')

@section('content')
<div class="space-y-6">

    {{--
        The status vocabulary, explained on the page.

        `accepted` and `delivered` are the pair people confuse, and the confusion
        matters: accepted means the mail transport took the message, delivered means a
        provider confirmed it arrived. Showing the two without explaining them would
        invite an operator to report the first as the second.
    --}}
    <div class="card">
        <div class="card-content">
            <dl class="grid grid-cols-2 gap-3 text-xs sm:grid-cols-4 lg:grid-cols-8">
                @foreach([
                    'scheduled' => 'Waiting for its send time',
                    'queued' => 'Handed to the queue',
                    'sending' => 'A worker has claimed it',
                    'accepted' => 'Transport took it — not confirmed delivered',
                    'delivered' => 'Provider confirmed arrival',
                    'bounced' => 'Provider confirmed non-delivery',
                    'suppressed' => 'Address blocked or opted out',
                    'failed' => 'Every attempt was refused',
                ] as $status => $description)
                    <div>
                        <dt class="font-medium">
                            {{ ucfirst($status) }}
                            <span class="text-muted-foreground tabular-nums">({{ number_format($counts[$status] ?? 0) }})</span>
                        </dt>
                        <dd class="mt-0.5 text-muted-foreground">{{ $description }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Filter</h2>
        </div>

        <div class="card-content">
            <form method="GET" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div>
                    <label for="search" class="label">Customer or subject</label>
                    <input id="search" name="search" type="text" value="{{ $filters['search'] ?? '' }}"
                           placeholder="Name, email or subject" class="input mt-1.5">
                </div>

                <div>
                    <label for="status" class="label">Status</label>
                    <select id="status" name="status" class="select mt-1.5">
                        <option value="">All statuses</option>
                        @foreach(array_keys($counts) as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>
                                {{ ucfirst($status) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="automation_id" class="label">Automation</label>
                    <select id="automation_id" name="automation_id" class="select mt-1.5">
                        <option value="">All automations</option>
                        @foreach($automations as $automation)
                            <option value="{{ $automation->id }}"
                                    @selected((int) ($filters['automation_id'] ?? 0) === $automation->id)>
                                {{ $automation->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="from" class="label">From</label>
                    <input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="input mt-1.5">
                </div>

                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <label for="to" class="label">To</label>
                        <input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="input mt-1.5">
                    </div>
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th class="text-right">Scheduled</th>
                        <th class="text-right">Attempts</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($messages as $message)
                        <tr>
                            <td>
                                <p class="font-medium">{{ $message->user?->name ?? 'Deleted customer' }}</p>
                                <p class="text-xs text-muted-foreground">{{ $message->to_address }}</p>
                            </td>
                            <td>
                                <p class="text-sm font-medium">{{ Str::limit($message->subject, 60) }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ $message->automation?->name ?? '—' }}
                                    &middot; {{ $message->template_key }} v{{ $message->template_version }}
                                    &middot; {{ ucfirst($message->category) }}
                                </p>
                            </td>
                            <td>
                                @php
                                    $tone = match ($message->status) {
                                        'delivered' => 'badge-success',
                                        'accepted' => 'badge-info',
                                        'scheduled', 'queued', 'sending' => 'badge-warning',
                                        'suppressed', 'cancelled' => 'badge-neutral',
                                        default => 'badge-destructive',
                                    };
                                @endphp
                                <span class="badge {{ $tone }}">{{ $message->statusLabel() }}</span>

                                @if($message->accepted_at && $message->status === 'accepted')
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        Accepted {{ $message->accepted_at->diffForHumans() }}, not confirmed delivered
                                    </p>
                                @endif

                                @if($message->status_message)
                                    <p class="mt-1 text-xs text-muted-foreground">{{ Str::limit($message->status_message, 80) }}</p>
                                @endif
                            </td>
                            <td class="text-right text-sm text-muted-foreground">
                                {{ $message->scheduled_for?->format('d M H:i') }}
                            </td>
                            <td class="text-right tabular-nums">{{ $message->attempts }}</td>
                            <td class="text-right">
                                @if($message->isRetryable() && auth()->user()->hasPermission('send_lifecycle'))
                                    <form method="POST" action="{{ route('admin.lifecycle.deliveries.retry', $message, false) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-ghost btn-sm">
                                            <x-icon name="arrow-path" class="h-4 w-4" />
                                            Retry
                                        </button>
                                    </form>
                                @else
                                    <span class="text-xs text-muted-foreground">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted-foreground">
                                No deliveries match this filter.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($messages->hasPages())
            <div class="card-footer">
                {{ $messages->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
