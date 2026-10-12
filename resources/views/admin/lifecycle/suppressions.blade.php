@extends('admin.layouts.app')

@section('title', 'Suppressions')
@section('page-title', 'Suppressions')
@section('page-description', 'Addresses the platform will not mail, and why.')

@section('content')
<div class="space-y-6">

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">How this differs from consent</h2>
        </div>
        <div class="card-content">
            <p class="text-sm text-muted-foreground">
                <strong>Consent</strong> is the customer's own decision, recorded when they unsubscribe.
                <strong>Suppression</strong> is the platform's block, usually learned from the mail provider: a hard
                bounce means the mailbox does not exist, and continuing to send to it is how the sending domain
                acquires a reputation problem that then affects every other email, including password resets.
            </p>
            <p class="mt-2 text-sm text-muted-foreground">
                A <strong>manual</strong> suppression blocks transactional mail as well as marketing. An
                <strong>unsubscribe</strong> blocks marketing only — a customer who opts out of promotions must
                still receive a receipt.
            </p>
        </div>
    </div>

    @if(auth()->user()->hasPermission('manage_lifecycle'))
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Add a suppression</h2>
            </div>

            <div class="card-content">
                <form method="POST" action="{{ route('admin.lifecycle.suppressions.store', [], false) }}"
                      class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    @csrf

                    <div>
                        <label for="email" class="label">Address</label>
                        <input id="email" name="email" type="email" required class="input mt-1.5">
                    </div>

                    <div>
                        <label for="reason" class="label">Reason</label>
                        <select id="reason" name="reason" class="select mt-1.5">
                            <option value="manual">Manual — blocks everything</option>
                            <option value="bounce">Bounce — blocks everything</option>
                            <option value="complaint">Complaint — blocks everything</option>
                        </select>
                    </div>

                    <div>
                        <label for="note" class="label">Note</label>
                        <input id="note" name="note" type="text" maxlength="255" class="input mt-1.5">
                    </div>

                    <div class="flex items-end">
                        <button type="submit" class="btn btn-primary w-full">Suppress</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Suppressed addresses</h2>
        </div>

        <div class="card-content pb-0">
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <div class="min-w-[16rem] flex-1">
                    <label for="search" class="label">Search</label>
                    <input id="search" name="search" type="text" value="{{ $search }}" class="input mt-1.5">
                </div>
                <button type="submit" class="btn btn-outline">Search</button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Address</th>
                        <th>Reason</th>
                        <th>Note</th>
                        <th class="text-right">Added</th>
                        @if(auth()->user()->hasPermission('manage_lifecycle'))
                            <th class="text-right">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($suppressions as $suppression)
                        <tr>
                            <td class="font-medium">{{ $suppression->email }}</td>
                            <td>
                                <span class="badge {{ in_array($suppression->reason, ['bounce', 'complaint'], true) ? 'badge-destructive' : 'badge-neutral' }}">
                                    {{ ucfirst($suppression->reason) }}
                                </span>
                            </td>
                            <td class="text-sm text-muted-foreground">{{ $suppression->note ?? '—' }}</td>
                            <td class="text-right text-sm text-muted-foreground">
                                {{ $suppression->created_at?->format('d M Y H:i') }}
                            </td>
                            @if(auth()->user()->hasPermission('manage_lifecycle'))
                                <td class="text-right">
                                    <form method="POST" action="{{ route('admin.lifecycle.suppressions.destroy', $suppression, false) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-ghost btn-sm"
                                                onclick="return confirm('Allow email to this address again?');">
                                            Remove
                                        </button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted-foreground">Nothing suppressed.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($suppressions->hasPages())
            <div class="card-footer">{{ $suppressions->links() }}</div>
        @endif
    </div>
</div>
@endsection
