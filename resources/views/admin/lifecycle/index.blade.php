@extends('admin.layouts.app')

@section('title', 'Customer messaging')
@section('page-title', 'Customer messaging')
@section('page-description', 'Lifecycle automations, email templates and delivery.')

@section('page-actions')
    <a href="{{ route('admin.lifecycle.deliveries') }}" class="btn btn-outline btn-sm">
        <x-icon name="queue-list" class="h-4 w-4" />
        Delivery log
    </a>
@endsection

@section('content')
<div class="space-y-6">

    @if(! $marketingEnabled)
        <div class="alert alert-warning">
            <p class="text-sm font-medium">Marketing email is paused</p>
            <p class="mt-1 text-sm">
                No marketing automation will send while the kill switch is off. Receipts, funding
                confirmations and security email continue normally — they are not consent-based and are
                not affected.
            </p>
        </div>
    @endif

    @if($bounceRate['rate_bps'] >= config('lifecycle.bounce_rate_warning_bps', 500))
        <div class="alert alert-destructive">
            <p class="text-sm font-medium">Elevated failure rate</p>
            <p class="mt-1 text-sm">
                {{ number_format($bounceRate['bounced']) }} of {{ number_format($bounceRate['total']) }}
                recent deliveries bounced or failed ({{ number_format($bounceRate['rate_bps'] / 100, 2) }}%).
                A rate this high affects every email the platform sends, including password resets —
                check the delivery log before enabling more automations.
            </p>
        </div>
    @endif

    {{-- ============================ Metrics ============================ --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach([
            ['Active automations', $snapshot['active_automations'], 'bolt'],
            ['Emails sent today', $snapshot['sent_today'], 'paper-airplane'],
            ['Queued', $snapshot['queued'] + $snapshot['scheduled'], 'clock'],
            ['Failed', $snapshot['failed'], 'exclamation-triangle'],
        ] as [$label, $value, $icon])
            <div class="card">
                <div class="card-content">
                    <div class="flex items-start justify-between gap-3">
                        <span class="stat-label">{{ $label }}</span>
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                            <x-icon :name="$icon" class="h-4 w-4" />
                        </span>
                    </div>
                    <p class="stat-value mt-2 tabular-nums">{{ number_format($value) }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="card-header">
                <h2 class="card-title">Recent automation activity</h2>
                <p class="card-description">The last fifteen messages the module has handled.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Automation</th>
                            <th>Status</th>
                            <th class="text-right">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recent as $message)
                            <tr>
                                <td>
                                    <p class="font-medium">{{ $message->user?->name ?? 'Deleted customer' }}</p>
                                    <p class="text-xs text-muted-foreground">{{ $message->to_address }}</p>
                                </td>
                                <td class="text-sm">{{ $message->automation?->name ?? '—' }}</td>
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
                                    @if($message->status_message)
                                        <p class="mt-1 text-xs text-muted-foreground">{{ Str::limit($message->status_message, 70) }}</p>
                                    @endif
                                </td>
                                <td class="text-right text-sm text-muted-foreground">
                                    {{ $message->created_at?->diffForHumans() }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted-foreground">
                                    Nothing sent yet. Create an automation to begin.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Marketing</h2>
                    <p class="card-description">A global switch, for incidents.</p>
                </div>

                <div class="card-content space-y-4">
                    <p class="text-sm text-muted-foreground">
                        Pausing stops marketing automations immediately. It never stops a receipt, a funding
                        confirmation, a password reset or a security alert.
                    </p>

                    <form method="POST" action="{{ route('admin.lifecycle.marketing-toggle', [], false) }}">
                        @csrf
                        <input type="hidden" name="enabled" value="{{ $marketingEnabled ? '0' : '1' }}">
                        <button type="submit" class="btn {{ $marketingEnabled ? 'btn-outline' : 'btn-primary' }} w-full">
                            <x-icon :name="$marketingEnabled ? 'no-symbol' : 'check-circle'" class="h-4 w-4" />
                            {{ $marketingEnabled ? 'Pause marketing email' : 'Resume marketing email' }}
                        </button>
                    </form>

                    <dl class="space-y-2 border-t border-border pt-4 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Suppressed addresses</dt>
                            <dd class="tabular-nums">{{ number_format($suppressedCount) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Marketing opt-outs</dt>
                            <dd class="tabular-nums">{{ number_format($optedOutCount) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Events awaiting processing</dt>
                            <dd class="tabular-nums">{{ number_format($snapshot['pending_events']) }}</dd>
                        </div>
                    </dl>

                    <a href="{{ route('admin.lifecycle.suppressions') }}" class="btn btn-ghost btn-sm w-full">
                        Manage suppressions
                    </a>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Automations</h2>
                </div>
                <div class="card-content space-y-2">
                    @forelse($automations as $automation)
                        <a href="{{ route('admin.lifecycle.automations.edit', $automation) }}"
                           class="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2 hover:bg-accent">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium">{{ $automation->name }}</span>
                                <span class="block truncate text-xs text-muted-foreground">{{ $automation->trigger_event }}</span>
                            </span>
                            <span class="badge {{ $automation->status === 'active' ? 'badge-success' : 'badge-neutral' }}">
                                {{ ucfirst($automation->status) }}
                            </span>
                        </a>
                    @empty
                        <p class="text-sm text-muted-foreground">No automations yet.</p>
                    @endforelse

                    <a href="{{ route('admin.lifecycle.automations') }}" class="btn btn-ghost btn-sm w-full">
                        All automations
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
