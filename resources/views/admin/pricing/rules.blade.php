@extends('admin.layouts.app')

@section('title', 'Pricing rules')
@section('page-title', 'Pricing rules')
@section('page-description', 'The rule hierarchy that decides customer prices. The most specific active rule wins.')

@section('content')
<div class="space-y-6">

    {{-- ============================ Hierarchy ============================ --}}
    <div class="card">
        <div class="card-content">
            <h2 class="text-sm font-semibold">How a price is resolved</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Rules are checked from the most specific to the most general. The first active match applies,
                so a rule pinned to one provider product beats the product rule, which beats the category rule,
                which beats the global default.
            </p>
            <ol class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                @foreach([
                    'provider_product' => 'Individual provider product',
                    'product' => 'Product',
                    'provider' => 'Provider',
                    'category' => 'Service category',
                    'global' => 'Global default',
                ] as $scope => $label)
                    <li>
                        <a href="{{ route('admin.pricing.rules', ['scope' => $scope]) }}"
                           class="badge {{ $scope === request('scope') ? 'badge-primary' : 'badge-neutral' }}">
                            {{ $label }}
                        </a>
                    </li>
                    @if(! $loop->last)
                        <li class="text-muted-foreground">&rsaquo;</li>
                    @endif
                @endforeach
            </ol>
        </div>
    </div>

    {{-- ============================ Actions ============================ --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.pricing.rules') }}" class="btn btn-outline btn-sm">All rules</a>
            <a href="{{ route('admin.pricing.bulk') }}" class="btn btn-outline btn-sm">Bulk update</a>
        </div>
        <a href="{{ route('admin.pricing.create') }}" class="btn btn-primary btn-sm">New pricing rule</a>
    </div>

    {{-- ============================ Table ============================ --}}
    <div class="card">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Rule</th>
                        <th>Applies to</th>
                        <th>Markup</th>
                        <th class="text-right">Min profit</th>
                        <th class="text-right">Min margin</th>
                        <th>Rounding</th>
                        <th class="text-right">Priority</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rules as $rule)
                        <tr>
                            <td>
                                <span class="font-medium">{{ $rule->name }}</span>
                                <span class="block text-xs text-muted-foreground">{{ $rule->scope }}</span>
                            </td>
                            <td>{{ $rule->subjectLabel() }}</td>
                            <td class="tabular-nums">
                                @switch($rule->markup_type)
                                    @case('percentage')
                                        {{ number_format($rule->markup_percentage_bps / 100, 2) }}%
                                        @break
                                    @case('fixed')
                                        ₦{{ number_format($rule->markup_fixed_minor / 100, 2) }}
                                        @break
                                    @case('percentage_plus_fixed')
                                        {{ number_format($rule->markup_percentage_bps / 100, 2) }}%
                                        + ₦{{ number_format($rule->markup_fixed_minor / 100, 2) }}
                                        @break
                                    @default
                                        None
                                @endswitch
                            </td>
                            <td class="text-right tabular-nums">
                                {{ $rule->minimum_profit_minor > 0 ? '₦' . number_format($rule->minimum_profit_minor / 100, 2) : '—' }}
                            </td>
                            <td class="text-right tabular-nums">
                                {{ $rule->minimum_margin_bps > 0 ? number_format($rule->minimum_margin_bps / 100, 2) . '%' : '—' }}
                            </td>
                            <td class="tabular-nums">
                                {{ $rule->rounding_step_minor > 0
                                    ? '₦' . number_format($rule->rounding_step_minor / 100, 0) . ' ' . $rule->rounding_mode
                                    : 'None' }}
                            </td>
                            <td class="text-right tabular-nums">{{ $rule->priority }}</td>
                            <td>
                                <span class="badge {{ $rule->is_active ? 'badge-success' : 'badge-neutral' }}">
                                    {{ $rule->is_active ? 'Active' : 'Inactive' }}
                                </span>
                                @if($rule->allow_negative_margin)
                                    <span class="badge badge-warning mt-1">Allows loss</span>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('admin.pricing.edit', $rule) }}" class="link text-xs">Edit</a>
                                <a href="{{ route('admin.pricing.versions', $rule) }}" class="link ml-2 text-xs">History</a>

                                <form method="POST" action="{{ route('admin.pricing.toggle', $rule) }}" class="inline">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="link ml-2 text-xs">
                                        {{ $rule->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>

                                <form method="POST" action="{{ route('admin.pricing.destroy', $rule) }}"
                                      class="inline"
                                      onsubmit="return confirm('Delete this pricing rule? Its version history will go with it.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="link ml-2 text-xs text-red-600">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted-foreground">
                                No pricing rules yet. Nothing can be sold until a global default exists.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($rules->hasPages())
            <div class="card-content border-t">{{ $rules->links() }}</div>
        @endif
    </div>

    <p class="text-xs text-muted-foreground">
        Every change is versioned and attributed. A price already charged is recorded on the order's pricing
        snapshot and is never recalculated when a rule changes.
    </p>
</div>
@endsection
