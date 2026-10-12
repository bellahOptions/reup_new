@extends('layouts.main')

@section('title', 'Email preferences')

@section('content')
<main class="min-h-screen bg-surface">
    <div class="container-page flex min-h-screen items-center py-12">
        <div class="mx-auto w-full max-w-lg">
            <div class="card">
                <div class="card-content sm:p-7">
                    <h1 class="text-xl font-semibold tracking-tight">Marketing email preferences</h1>

                    <p class="mt-3 text-sm text-muted-foreground">
                        Hello {{ $user->first_name ?: $user->name }}. You can stop marketing email from
                        {{ config('app.name') }} here.
                    </p>

                    <div class="mt-5 rounded-lg border border-border bg-surface-muted p-4">
                        <p class="text-sm font-medium">What this stops</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Offers, tips and reminders about funding your wallet or referring friends.
                        </p>

                        <p class="mt-3 text-sm font-medium">What it does not stop</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Receipts, funding confirmations, password resets and security alerts. Those are part of
                            using your account, and we have to send them.
                        </p>
                    </div>

                    @if($alreadyOptedOut)
                        <p class="mt-5 text-sm text-muted-foreground">
                            You are already unsubscribed from marketing email.
                        </p>

                        <a href="{{ route('dashboard') }}" class="btn btn-outline mt-5 w-full">Back to {{ config('app.name') }}</a>
                    @else
                        <form method="POST" action="{{ URL::signedRoute('lifecycle.unsubscribe.confirm', ['user' => $user->getKey()]) }}" class="mt-5">
                            @csrf
                            <button type="submit" class="btn btn-primary w-full">
                                Unsubscribe from marketing email
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <p class="mt-4 text-center text-xs text-muted-foreground">
                {{ config('app.name') }} is operated by Bellah Options (BN3668420).
            </p>
        </div>
    </div>
</main>
@endsection
