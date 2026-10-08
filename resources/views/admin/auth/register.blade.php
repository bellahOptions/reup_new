<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')

    <title>Create the founding administrator — {{ config('app.name', 'ReUp') }}</title>
    <meta name="robots" content="noindex, nofollow">
</head>
<body class="min-h-screen bg-surface">
    <div class="flex min-h-screen items-center justify-center px-4 py-12">
        <div class="w-full max-w-lg">

            <div class="mb-8 text-center">
                <img src="{{ asset('images/reup-03.svg') }}" alt="ReUp" class="mx-auto h-7 w-auto">
            </div>

            <div class="card">
                <div class="card-content sm:p-7">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-brand-600">
                        <x-icon name="shield-check" class="h-5 w-5" />
                    </span>

                    <h1 class="mt-5 text-2xl font-semibold tracking-tight">Create the founding administrator</h1>
                    <p class="mt-1.5 text-sm text-muted-foreground">
                        No administrator exists on this installation yet. This account becomes
                        the super admin and is the only one that can create further
                        administrators. The form closes permanently once it is submitted.
                    </p>

                    @if($errors->any())
                        <div class="mt-6 flex items-start gap-2.5 rounded-lg border border-red-200 bg-red-50 px-3.5 py-3">
                            <x-icon name="exclamation-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                            <ul class="min-w-0 space-y-0.5">
                                @foreach($errors->all() as $error)
                                    <li class="text-sm text-red-700">{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.register.post', [], false) }}" class="mt-6 space-y-5">
                        @csrf

                        <div>
                            <label for="name" class="label">Full name</label>
                            <input id="name" name="name" type="text" required autofocus
                                   autocomplete="name" value="{{ old('name') }}"
                                   class="input mt-1.5 @error('name') input-error @enderror">
                        </div>

                        <div>
                            <label for="email" class="label">Email address</label>
                            <input id="email" name="email" type="email" required
                                   autocomplete="username" value="{{ old('email') }}"
                                   class="input mt-1.5 @error('email') input-error @enderror">
                        </div>

                        <div>
                            <label for="phone" class="label">Phone <span class="font-normal text-muted-foreground">(optional)</span></label>
                            <input id="phone" name="phone" type="tel" autocomplete="tel"
                                   value="{{ old('phone') }}"
                                   class="input mt-1.5 @error('phone') input-error @enderror">
                        </div>

                        <div>
                            <label for="password" class="label">Password</label>
                            <input id="password" name="password" type="password" required
                                   autocomplete="new-password" placeholder="At least 12 characters"
                                   class="input mt-1.5 @error('password') input-error @enderror">
                            @error('password')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="password_confirmation" class="label">Confirm password</label>
                            <input id="password_confirmation" name="password_confirmation" type="password"
                                   required autocomplete="new-password"
                                   class="input mt-1.5">
                        </div>

                        <button type="submit" class="btn btn-primary w-full">
                            <x-icon name="lock-closed" class="h-4 w-4" />
                            Create administrator
                        </button>
                    </form>
                </div>
            </div>

            <p class="mt-6 text-center text-xs text-muted-foreground">
                Already have an account?
                <a href="{{ route('admin.login') }}" class="link font-medium">Sign in</a>
            </p>
        </div>
    </div>
</body>
</html>
