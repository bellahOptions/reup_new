<x-guest-layout>
    @section('title', 'Verify your email')

    <div class="card">
        <div class="card-content sm:p-7">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-brand-600">
                <x-icon name="envelope" class="h-5 w-5" />
            </span>

            <h1 class="mt-5 text-2xl font-semibold tracking-tight">Verify your email</h1>
            <p class="mt-1.5 text-sm text-muted-foreground">
                We sent a verification link to
                <span class="font-medium text-foreground">{{ auth()->user()->email }}</span>.
                Open it to activate payments.
            </p>

            @if(session('status') == 'verification-link-sent')
                <div class="mt-5 flex items-start gap-2.5 rounded-lg border border-brand-200 bg-accent px-3.5 py-3">
                    <x-icon name="check-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
                    <p class="text-sm text-accent-foreground">
                        A fresh verification link is on its way. It can take a minute to arrive.
                    </p>
                </div>
            @endif

            <ol class="mt-6 space-y-3 border-t border-border pt-6">
                @foreach([
                    ['Open the email from ' . config('app.name', 'ReUp') . ' with the subject "Verify Your Email Address".', 'envelope'],
                    ['Click the verification button or link inside it.', 'link'],
                    ['You are returned here with full access to your wallet.', 'shield-check'],
                ] as [$step, $icon])
                    <li class="flex items-start gap-3">
                        <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-ink-100 text-ink-600">
                            <x-icon :name="$icon" class="h-3.5 w-3.5" />
                        </span>
                        <span class="text-sm text-muted-foreground">{{ $step }}</span>
                    </li>
                @endforeach
            </ol>

            <p class="mt-6 text-xs text-muted-foreground">
                Nothing in your inbox? Check spam, then
                <a href="{{ route('contact') }}" class="link">contact support</a>.
            </p>
        </div>

        <div class="card-footer items-center justify-between gap-3">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit" class="btn btn-outline btn-sm">
                    <x-icon name="arrow-path" class="h-4 w-4" />
                    Resend link
                </button>
            </form>

            {{-- Logout is POST-only; this used to be a GET <a href>, which threw
                 MethodNotAllowedHttpException. --}}
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm text-muted-foreground">
                    <x-icon name="arrow-right-on-rectangle" class="h-4 w-4" />
                    Sign out
                </button>
            </form>
        </div>
    </div>
</x-guest-layout>
