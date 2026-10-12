@extends('layouts.main')

@section('title', 'Unsubscribed')

@section('content')
<main class="min-h-screen bg-surface">
    <div class="container-page flex min-h-screen items-center py-12">
        <div class="mx-auto w-full max-w-lg">
            <div class="card">
                <div class="card-content text-center sm:p-7">
                    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-600">
                        <x-icon name="check-circle" variant="solid" class="h-6 w-6" />
                    </span>

                    <h1 class="mt-4 text-xl font-semibold tracking-tight">
                        {{ $alreadyOptedOut ? 'You were already unsubscribed' : 'You are unsubscribed' }}
                    </h1>

                    <p class="mt-3 text-sm text-muted-foreground">
                        {{ $alreadyOptedOut
                            ? 'Marketing email from ' . config('app.name') . ' was already switched off for this address.'
                            : 'We will stop sending marketing email to this address.' }}
                    </p>

                    <p class="mt-3 text-sm text-muted-foreground">
                        You will still receive receipts, funding confirmations, password resets and security
                        alerts, because those are part of using your account.
                    </p>

                    <a href="{{ route('dashboard') }}" class="btn btn-primary mt-6 w-full">
                        Back to {{ config('app.name') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>
@endsection
