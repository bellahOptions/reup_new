@extends('admin.layouts.app')

@section('title', 'Edit customer')
@section('page-title', 'Edit customer')
@section('page-description', 'Correct the customer record. Changing the email re-verifies the account.')

@section('page-actions')
    <a href="{{ route('admin.users.show', $user) }}" class="btn btn-outline btn-sm">
        <x-icon name="arrow-left" class="h-4 w-4" />
        Back to customer
    </a>
@endsection

@section('content')
<div class="mx-auto max-w-2xl space-y-6">

    @if($errors->any())
        <div class="card border-red-200">
            <div class="card-content flex items-start gap-2.5">
                <x-icon name="exclamation-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                <ul class="min-w-0 space-y-0.5">
                    @foreach($errors->all() as $error)
                        <li class="text-sm text-red-700">{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.users.update', $user, false) }}">
        @csrf
        @method('PUT')

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Customer record</h2>
                <p class="card-description">Reference #{{ str_pad((string) $user->id, 6, '0', STR_PAD_LEFT) }}</p>
            </div>

            <div class="card-content space-y-5">
                <div>
                    <label for="name" class="label">Full name</label>
                    <input id="name" name="name" type="text" required
                           value="{{ old('name', $user->name) }}"
                           class="input mt-1.5 @error('name') input-error @enderror">
                </div>

                <div>
                    <label for="email" class="label">Email address</label>
                    <input id="email" name="email" type="email" required
                           value="{{ old('email', $user->email) }}"
                           class="input mt-1.5 @error('email') input-error @enderror">
                    @if($user->email_verified_at)
                        <p class="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                            <x-icon name="check-circle" variant="solid" class="h-3.5 w-3.5 text-green-600" />
                            Verified {{ $user->email_verified_at->format('M j, Y') }} — changing the address resets this.
                        </p>
                    @else
                        <p class="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                            <x-icon name="exclamation-triangle" class="h-3.5 w-3.5 text-amber-600" />
                            This address is not verified.
                        </p>
                    @endif
                </div>

                <div>
                    <label for="phone" class="label">
                        Phone <span class="font-normal text-muted-foreground">(optional)</span>
                    </label>
                    <input id="phone" name="phone" type="tel"
                           value="{{ old('phone', $user->phone) }}"
                           class="input mt-1.5 @error('phone') input-error @enderror">
                </div>
            </div>

            <div class="card-footer justify-between">
                <a href="{{ route('admin.users.show', $user) }}" class="btn btn-ghost btn-sm">Cancel</a>
                <button type="submit" class="btn btn-primary btn-sm">
                    <x-icon name="check" class="h-4 w-4" />
                    Save changes
                </button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Account state</h2>
            <p class="card-description">Read-only. Use the customer page to change these.</p>
        </div>
        <div class="card-content">
            <dl class="space-y-3 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-muted-foreground">Status</dt>
                    <dd>
                        <span class="badge {{ $user->status === 'suspended' ? 'badge-destructive' : 'badge-success' }}">
                            {{ ucfirst($user->status ?? 'active') }}
                        </span>
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-muted-foreground">Wallet balance</dt>
                    <dd class="font-medium tabular-nums">
                        &#8358;{{ number_format((float) $user->wallet_balance, 2) }}
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-muted-foreground">Joined</dt>
                    <dd class="font-medium">{{ $user->created_at?->format('M j, Y') ?? '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-muted-foreground">Last sign-in</dt>
                    <dd class="font-medium">{{ $user->last_login_at?->diffForHumans() ?? 'Never' }}</dd>
                </div>
            </dl>
        </div>
    </div>
</div>
@endsection
