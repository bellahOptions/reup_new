@extends('admin.layouts.app')

@section('title', 'Rule history')
@section('page-title', 'History — ' . $rule->name)
@section('page-description', 'Every change to this rule, with what moved and who moved it.')

@section('content')
<div class="space-y-6">

    <div class="card">
        <div class="card-content">
            <dl class="grid gap-4 sm:grid-cols-4">
                <div>
                    <dt class="stat-label">Scope</dt>
                    <dd class="mt-1 text-sm">{{ $rule->scope }}</dd>
                </div>
                <div>
                    <dt class="stat-label">Applies to</dt>
                    <dd class="mt-1 text-sm">{{ $rule->subjectLabel() }}</dd>
                </div>
                <div>
                    <dt class="stat-label">Status</dt>
                    <dd class="mt-1 text-sm">{{ $rule->is_active ? 'Active' : 'Inactive' }}</dd>
                </div>
                <div>
                    <dt class="stat-label">Versions</dt>
                    <dd class="mt-1 text-sm">{{ $versions->total() }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Version history</h2>
            <p class="card-description">
                The current state is the newest entry. Orders already priced keep the figures recorded on their
                own pricing snapshot, so a change here never restates a completed sale.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Version</th>
                        <th>When</th>
                        <th>By</th>
                        <th>What changed</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($versions as $version)
                        <tr>
                            <td class="tabular-nums">v{{ $version->version }}</td>
                            <td class="whitespace-nowrap text-xs">
                                {{ $version->created_at?->format('d M Y H:i') }}
                            </td>
                            <td class="text-sm">{{ $version->changedBy?->name ?? 'System' }}</td>
                            <td>
                                @php $summary = $version->changeSummary(); @endphp
                                @if($summary === [])
                                    <span class="text-muted-foreground">Created</span>
                                @else
                                    <ul class="space-y-0.5 text-xs">
                                        @foreach($summary as $line)
                                            <li class="tabular-nums">{{ $line }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                            <td class="text-xs text-muted-foreground">{{ $version->reason ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted-foreground">
                                No history recorded yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($versions->hasPages())
            <div class="card-content border-t">{{ $versions->links() }}</div>
        @endif
    </div>

    <div class="flex gap-2">
        <a href="{{ route('admin.pricing.edit', $rule) }}" class="btn btn-primary btn-sm">Edit rule</a>
        <a href="{{ route('admin.pricing.rules') }}" class="btn btn-outline btn-sm">Back to rules</a>
    </div>
</div>
@endsection
