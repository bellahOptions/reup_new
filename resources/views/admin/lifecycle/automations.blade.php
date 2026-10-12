@extends('admin.layouts.app')

@section('title', 'Automations')
@section('page-title', 'Automations')
@section('page-description', 'Triggers, conditions and timing for lifecycle email.')

@section('page-actions')
    @if(auth()->user()->hasPermission('manage_lifecycle'))
        <a href="{{ route('admin.lifecycle.automations.create') }}" class="btn btn-primary btn-sm">
            <x-icon name="plus" class="h-4 w-4" />
            New automation
        </a>
    @endif
@endsection

@section('content')
<div class="space-y-6">
    <div class="alert alert-warning">
        <p class="text-sm font-medium">Automations are created disabled</p>
        <p class="mt-1 text-sm">
            A new automation does nothing until its status is set to <strong>Active</strong>. Nothing in this
            module mails a customer by default — activating one is the step that starts it, and it is audited.
        </p>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Automations</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Automation</th>
                        <th>Trigger</th>
                        <th>Template</th>
                        <th>Timing</th>
                        <th>Status</th>
                        <th class="text-right">Sent</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($automations as $automation)
                        <tr>
                            <td>
                                <a href="{{ route('admin.lifecycle.automations.edit', $automation) }}" class="font-medium link">
                                    {{ $automation->name }}
                                </a>
                                <p class="text-xs text-muted-foreground">{{ $automation->key }}</p>
                            </td>
                            <td class="text-sm">
                                {{ $triggers[$automation->trigger_event]['label'] ?? $automation->trigger_event }}
                            </td>
                            <td class="text-sm">{{ $automation->template?->name ?? '—' }}</td>
                            <td class="text-sm text-muted-foreground">
                                @if($automation->delay_minutes > 0)
                                    {{ $automation->delay_minutes >= 1440
                                        ? round($automation->delay_minutes / 1440, 1) . ' days later'
                                        : ($automation->delay_minutes >= 60
                                            ? round($automation->delay_minutes / 60, 1) . ' hours later'
                                            : $automation->delay_minutes . ' minutes later') }}
                                @else
                                    Immediately
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $automation->status === 'active' ? 'badge-success' : ($automation->status === 'paused' ? 'badge-warning' : 'badge-neutral') }}">
                                    {{ ucfirst($automation->status) }}
                                </span>
                                @if($automation->blockingReason() && $automation->status === 'active')
                                    <p class="mt-1 text-xs text-muted-foreground">{{ $automation->blockingReason() }}</p>
                                @endif
                            </td>
                            <td class="text-right tabular-nums">{{ number_format($automation->sent_count) }}</td>
                            <td class="text-right">
                                <a href="{{ route('admin.lifecycle.automations.edit', $automation) }}"
                                   class="btn btn-ghost btn-sm">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted-foreground">
                                No automations yet. Run <code>php artisan db:seed --class=LifecycleSeeder</code>
                                to create the five recommended examples as disabled drafts.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Available triggers</h2>
            <p class="card-description">What an automation can listen for.</p>
        </div>
        <div class="card-content">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach($triggers as $name => $trigger)
                    <div class="rounded-lg border border-border p-3">
                        <p class="font-mono text-xs font-medium">{{ $name }}</p>
                        <p class="mt-1 text-sm">{{ $trigger['label'] }}</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">{{ $trigger['description'] }}</p>
                        @if($trigger['payload'] !== [])
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                Supplies:
                                @foreach($trigger['payload'] as $key)
                                    <span class="font-mono">{{ $key }}</span>@if(! $loop->last), @endif
                                @endforeach
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
