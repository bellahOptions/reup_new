@extends('layouts.app')
@section('title', 'Profile settings')
@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Profile settings
    |--------------------------------------------------------------------------
    | ProfileController::index() supplies `$user` and `$states`.
    |
    | Rewritten onto the design system (see docs/UI_RUNBOOK.md): the 24 emoji
    | that used to carry meaning here are now <x-icon> glyphs, the gradient /
    | shadow-xl / hover:scale markup is gone, the inline <style> block is gone
    | and the hand-rolled DOM script is replaced by Alpine.
    |
    | Every icon name below is whitelisted in components/icon.blade.php, and
    | `variant="solid"` is only used for names present in its $solid array — an
    | unknown solid name falls back to an outline path drawn with fill, which
    | renders as a blob.
    |
    | `$user->profile_completion_percentage` and `$user->age` are model accessors
    | (app/Models/User.php), so nothing is recomputed in the view.
    | `$user->phone_verified_at` is a datetime and stays null until the Alpine
    | verification card completes a round trip.
    */
    $completion = (int) $user->profile_completion_percentage;
    $emailVerified = $user->email_verified_at !== null;
    $phoneVerified = $user->phone_verified_at !== null;

    $prefs = $user->getNotificationPreferences();

    // The four cheapest wins, mirroring the weighted scoring in
    // User::getProfileCompletionPercentageAttribute().
    $checklist = [
        ['label' => 'Phone number', 'done' => ! empty($user->phone)],
        ['label' => 'WhatsApp number', 'done' => ! empty($user->whatsapp)],
        ['label' => 'Date of birth', 'done' => ! empty($user->birthday)],
        ['label' => 'Address', 'done' => ! empty($user->address)],
    ];

    // Field names must stay exactly as ProfileController::updateNotifications()
    // validates them: notifications[group][option].
    $notificationGroups = [
        [
            'key' => 'email',
            'label' => 'Email notifications',
            'icon' => 'envelope',
            'options' => [
                ['key' => 'transactions', 'label' => 'Transaction updates'],
                ['key' => 'promotions', 'label' => 'Promotions & offers'],
                ['key' => 'security', 'label' => 'Security alerts'],
            ],
        ],
        [
            'key' => 'sms',
            'label' => 'SMS notifications',
            'icon' => 'device-phone-mobile',
            'options' => [
                ['key' => 'transactions', 'label' => 'Transaction updates'],
                ['key' => 'security', 'label' => 'Security alerts'],
            ],
        ],
        [
            'key' => 'push',
            'label' => 'Push notifications',
            'icon' => 'megaphone',
            'options' => [
                ['key' => 'transactions', 'label' => 'Transaction updates'],
                ['key' => 'promotions', 'label' => 'Promotions & offers'],
                ['key' => 'security', 'label' => 'Security alerts'],
            ],
        ],
    ];
@endphp

<main class="min-h-screen bg-surface">
    <div class="container-page py-8 md:py-12">

        {{-- Phone-number alert. The layout suppresses its own nudge on /profile
             because this one is the actionable version. --}}
        @if($user->requires_phone_update)
            <div class="mb-6 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <x-icon name="device-phone-mobile" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                <div class="min-w-0 text-sm text-amber-900">
                    <p class="font-semibold">Add your phone number</p>
                    <p class="mt-1">
                        A phone number lets us confirm your airtime and data purchases and reach you about
                        transactions. Add and verify it below.
                    </p>
                </div>
            </div>
        @endif

        {{-- Validation summary. Field-level messages are also printed under
             each control; this catches anything without a matching field. --}}
        @if($errors->any())
            <div class="mb-6 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4">
                <x-icon name="exclamation-triangle" variant="solid" class="mt-0.5 h-5 w-5 shrink-0 text-destructive" />
                <div class="min-w-0 text-sm text-red-800">
                    <p class="font-semibold">We could not save your changes.</p>
                    <ul class="mt-1.5 list-disc space-y-1 pl-5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        {{-- Page heading --}}
        <header class="mb-8 flex flex-wrap items-end justify-between gap-4 md:mb-10">
            <div>
                <h1 class="mt-2 text-2xl font-semibold md:text-3xl">Profile settings</h1>
                <p class="mt-2 max-w-2xl text-sm text-muted-foreground md:text-base">
                    Keep your personal details, verification status and notification preferences up to date.
                </p>
            </div>
            <span class="badge badge-primary shrink-0">
                <x-icon name="chart-bar" class="h-3.5 w-3.5" />
                {{ $completion }}% complete
            </span>
        </header>

        {{--
            One Alpine scope for the whole page body.

            The avatar preview sits in the identity card above, and the colour /
            symbol inputs sit in the Avatar section of the profile form below.
            Those are two different elements, so they originally had two separate
            `x-data` blocks — and the preview therefore never reacted to a
            selection, because each block owned its own copy of `color`.

            Hoisting the state here makes both read and write the same value.
            This wrapper is outside both forms, so it cannot interfere with
            form submission.
        --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3"
             x-data="{
                color: @js($user->avatar_color ?: config('avatars.default_color')),
                icon: @js($user->avatar_icon),
                palette: @js(collect(config('avatars.colors'))->map(fn ($c) => ['from' => $c['from'], 'to' => $c['to'], 'ink' => $c['ink'] ?? 'dark'])),
                get previewStyle() {
                    const c = this.palette[this.color] || this.palette[@js(config('avatars.default_color'))];
                    if (!c) return 'background-color:#e5e7eb;color:#374151;';
                    return 'background-image:linear-gradient(135deg,' + c.from + ',' + c.to + ');color:' + (c.ink === 'light' ? '#78350f' : '#ffffff') + ';';
                }
             }">
            <div class="space-y-6 lg:col-span-2">

                {{-- Identity: avatar, name, verification status --}}
                <section class="card">
                    <div class="card-content flex flex-col gap-6 sm:flex-row sm:items-center">
                        {{--
                            Avatar preview, driven by the shared `color`/`icon`
                            state above — so it reflects the choice made in the
                            Avatar section below before the form is saved.
                        --}}
                        <div class="shrink-0">
                            <div class="flex h-24 w-24 items-center justify-center overflow-hidden rounded-2xl border border-border md:h-28 md:w-28"
                                 :style="previewStyle">
                                <template x-if="icon">
                                    <span class="flex items-center justify-center">
                                        <x-icon ::name="icon" class="h-12 w-12 md:h-14 md:w-14" :stroke-width="1.8" />
                                    </span>
                                </template>
                                <span x-show="!icon" class="text-3xl font-semibold md:text-4xl">
                                    {{ strtoupper(substr($user->name, 0, 1)) }}
                                </span>
                            </div>
                            @if($user->profile_picture)
                                <p class="mt-2 max-w-[7rem] text-xs text-muted-foreground">
                                    Showing your uploaded photo elsewhere.
                                </p>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-lg font-semibold">{{ $user->name }}</h2>
                            <p class="mt-1 break-all text-sm text-muted-foreground">{{ $user->email }}</p>

                            <dl class="mt-4 grid grid-cols-1 gap-2.5 text-sm sm:grid-cols-2">
                                <div class="flex items-center gap-2">
                                    <x-icon name="user" class="h-4 w-4 shrink-0 text-ink-400" />
                                    <dt class="text-muted-foreground">Member since</dt>
                                    <dd class="font-medium">{{ $user->created_at->format('M Y') }}</dd>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if($emailVerified)
                                        <x-icon name="check-circle" variant="solid" class="h-4 w-4 shrink-0 text-brand-600" />
                                    @else
                                        <x-icon name="no-symbol" class="h-4 w-4 shrink-0 text-ink-400" />
                                    @endif
                                    <dt class="text-muted-foreground">Email</dt>
                                    <dd class="font-medium">{{ $emailVerified ? 'Verified' : 'Not verified' }}</dd>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if($phoneVerified)
                                        <x-icon name="check-circle" variant="solid" class="h-4 w-4 shrink-0 text-brand-600" />
                                    @else
                                        <x-icon name="no-symbol" class="h-4 w-4 shrink-0 text-ink-400" />
                                    @endif
                                    <dt class="text-muted-foreground">Phone</dt>
                                    <dd class="font-medium">{{ $phoneVerified ? 'Verified' : 'Not verified' }}</dd>
                                </div>
                            </dl>

                            @error('profile_picture')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </section>

                {{-- Personal information --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title flex items-center gap-2">
                            <x-icon name="user" class="h-4 w-4 text-brand-600" />
                            Personal information
                        </h2>
                        <p class="card-description">These details appear on receipts and support tickets.</p>
                    </div>

                    <form id="profileForm" method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')

                        <div class="card-content space-y-6">
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div class="space-y-1.5">
                                    <label class="label" for="name">Full name <span class="text-destructive">*</span></label>
                                    <input type="text"
                                           id="name"
                                           name="name"
                                           value="{{ old('name', $user->name) }}"
                                           class="input @error('name') input-error @enderror"
                                           autocomplete="name"
                                           required>
                                    @error('name')
                                        <p class="field-error">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="space-y-1.5">
                                    <label class="label" for="email">Email address <span class="text-destructive">*</span></label>
                                    <input type="email"
                                           id="email"
                                           name="email"
                                           value="{{ old('email', $user->email) }}"
                                           class="input @error('email') input-error @enderror"
                                           autocomplete="email"
                                           required>
                                    @error('email')
                                        <p class="field-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div class="space-y-1.5">
                                    <label class="label" for="phone">
                                        Phone number
                                        @if(empty($user->phone))
                                            <span class="text-destructive">* required</span>
                                        @endif
                                    </label>
                                    <div class="relative">
                                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-400">
                                            <x-icon name="phone" class="h-4 w-4" />
                                        </span>
                                        <input type="tel"
                                               id="phone"
                                               name="phone"
                                               value="{{ old('phone', $user->phone) }}"
                                               placeholder="08012345678"
                                               inputmode="numeric"
                                               autocomplete="tel"
                                               class="input pl-10 tabular-nums @error('phone') input-error @enderror">
                                    </div>
                                    <p class="text-xs text-muted-foreground">Format: 08012345678</p>
                                    @error('phone')
                                        <p class="field-error">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="space-y-1.5">
                                    <label class="label" for="whatsapp">WhatsApp number</label>
                                    <div class="relative">
                                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-400">
                                            <x-icon name="chat-bubble-left-right" class="h-4 w-4" />
                                        </span>
                                        <input type="tel"
                                               id="whatsapp"
                                               name="whatsapp"
                                               value="{{ old('whatsapp', $user->whatsapp) }}"
                                               placeholder="08012345678"
                                               inputmode="numeric"
                                               autocomplete="tel-national"
                                               class="input pl-10 tabular-nums @error('whatsapp') input-error @enderror">
                                    </div>
                                    <p class="text-xs text-muted-foreground">Optional &mdash; used for WhatsApp notifications.</p>
                                    @error('whatsapp')
                                        <p class="field-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div class="space-y-1.5">
                                    <label class="label" for="birthday">Date of birth</label>
                                    <div class="relative">
                                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-400">
                                            <x-icon name="calendar" class="h-4 w-4" />
                                        </span>
                                        <input type="date"
                                               id="birthday"
                                               name="birthday"
                                               value="{{ old('birthday', $user->birthday ? $user->birthday->format('Y-m-d') : '') }}"
                                               max="{{ now()->subYears(18)->format('Y-m-d') }}"
                                               class="input pl-10 tabular-nums @error('birthday') input-error @enderror">
                                    </div>
                                    @if($user->age)
                                        <p class="text-xs text-muted-foreground">{{ $user->age }} years old</p>
                                    @endif
                                    @error('birthday')
                                        <p class="field-error">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="space-y-1.5">
                                    <label class="label" for="gender">Gender</label>
                                    <select id="gender"
                                            name="gender"
                                            class="select @error('gender') input-error @enderror">
                                        <option value="">Select gender</option>
                                        <option value="male" {{ old('gender', $user->gender) == 'male' ? 'selected' : '' }}>Male</option>
                                        <option value="female" {{ old('gender', $user->gender) == 'female' ? 'selected' : '' }}>Female</option>
                                        <option value="other" {{ old('gender', $user->gender) == 'other' ? 'selected' : '' }}>Other</option>
                                    </select>
                                    @error('gender')
                                        <p class="field-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="space-y-1.5">
                                <label class="label" for="address">Address</label>
                                <textarea id="address"
                                          name="address"
                                          rows="2"
                                          class="textarea @error('address') input-error @enderror">{{ old('address', $user->address) }}</textarea>
                                @error('address')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div class="space-y-1.5">
                                    <label class="label" for="state">State</label>
                                    <select id="state"
                                            name="state"
                                            class="select @error('state') input-error @enderror">
                                        <option value="">Select state</option>
                                        @foreach($states as $state)
                                            <option value="{{ $state }}" {{ old('state', $user->state) == $state ? 'selected' : '' }}>
                                                {{ $state }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('state')
                                        <p class="field-error">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="space-y-1.5">
                                    <label class="label" for="city">City</label>
                                    <input type="text"
                                           id="city"
                                           name="city"
                                           value="{{ old('city', $user->city) }}"
                                           class="input @error('city') input-error @enderror">
                                    @error('city')
                                        <p class="field-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        {{-- ------------------------------------------------------------------
                             Appearance: avatar colour + optional glyph.

                             Inside #profileForm, so it saves with the rest of the
                             personal details — one Save button, not two.
                        ------------------------------------------------------------------ --}}
                        {{-- No local scope: `color` and `icon` are inherited from
                             the shared Alpine scope wrapping the page body, which
                             is what keeps the preview above in sync. --}}
                        <div class="border-t border-border px-5 py-5 sm:px-6">
                            <h3 class="text-sm font-semibold">Avatar</h3>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Pick a colour, and optionally a symbol. Leave the symbol unset to
                                show your initials.
                            </p>

                            <fieldset class="mt-4">
                                <legend class="label">Colour</legend>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach(config('avatars.colors') as $key => $option)
                                        <label class="cursor-pointer">
                                            <input type="radio"
                                                   name="avatar_color"
                                                   value="{{ $key }}"
                                                   class="peer sr-only"
                                                   x-model="color"
                                                   @checked(($user->avatar_color ?: config('avatars.default_color')) === $key)>
                                            <span class="flex h-9 w-9 items-center justify-center rounded-full ring-offset-2 transition-shadow peer-checked:ring-2 peer-checked:ring-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500"
                                                  style="background-image:linear-gradient(135deg,{{ $option['from'] }},{{ $option['to'] }});">
                                                <x-icon name="check" class="h-4 w-4 text-white opacity-0 transition-opacity peer-checked:opacity-100" />
                                            </span>
                                            <span class="sr-only">{{ $option['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('avatar_color')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </fieldset>

                            <fieldset class="mt-5">
                                <legend class="label">Symbol</legend>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <label class="cursor-pointer">
                                        <input type="radio" name="avatar_icon" value="" class="peer sr-only"
                                               x-model="icon" @checked(! $user->avatar_icon)>
                                        <span class="flex h-9 min-w-[4.5rem] items-center justify-center rounded-full border border-border px-3 text-xs font-medium text-muted-foreground transition-colors peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-700">
                                            Initials
                                        </span>
                                    </label>

                                    @foreach(config('avatars.icons') as $key => $label)
                                        <label class="cursor-pointer" title="{{ $label }}">
                                            <input type="radio" name="avatar_icon" value="{{ $key }}" class="peer sr-only"
                                                   x-model="icon" @checked($user->avatar_icon === $key)>
                                            <span class="flex h-9 w-9 items-center justify-center rounded-full border border-border text-ink-600 transition-colors peer-checked:border-brand-500 peer-checked:bg-brand-50 peer-checked:text-brand-700">
                                                <x-icon :name="$key" class="h-4 w-4" />
                                            </span>
                                            <span class="sr-only">{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('avatar_icon')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </fieldset>
                        </div>

                        <div class="card-footer flex-col items-stretch gap-3 sm:flex-row sm:justify-end">
                            <p class="text-xs text-muted-foreground sm:mr-auto">
                                Your avatar is drawn in the browser — nothing is uploaded.
                            </p>
                            <button type="submit" class="btn btn-primary">
                                <x-icon name="document-check" class="h-4 w-4" />
                                Save changes
                            </button>
                        </div>
                    </form>
                </section>

                {{-- Phone verification. Alpine owns the whole round trip: request a
                     code, reveal the 6-digit field, then reload on success so the
                     server-rendered verification badges above pick up the change. --}}
                <section class="card" x-data="profilePhoneVerification">
                    <div class="card-header flex-row items-start justify-between gap-3">
                        <div>
                            <h2 class="card-title flex items-center gap-2">
                                <x-icon name="device-phone-mobile" class="h-4 w-4 text-brand-600" />
                                Phone verification
                            </h2>
                            <p class="card-description">Confirm the number we should use for transaction alerts.</p>
                        </div>
                        @if($phoneVerified)
                            <span class="badge badge-success shrink-0">
                                <x-icon name="check-circle" variant="solid" class="h-3.5 w-3.5" />
                                Verified
                            </span>
                        @elseif($user->phone)
                            <span class="badge badge-warning shrink-0">
                                <x-icon name="clock" class="h-3.5 w-3.5" />
                                Pending
                            </span>
                        @else
                            <span class="badge badge-neutral shrink-0">
                                <x-icon name="no-symbol" class="h-3.5 w-3.5" />
                                No number
                            </span>
                        @endif
                    </div>

                    <div class="card-content space-y-4">
                        @if($phoneVerified)
                            <p class="flex items-start gap-2 rounded-lg border border-brand-200 bg-accent p-3 text-sm text-accent-foreground">
                                <x-icon name="check-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0" />
                                <span>
                                    <span class="font-medium tabular-nums">{{ $user->phone }}</span>
                                    was verified {{ $user->phone_verified_at->format('M j, Y') }}.
                                    Verifying a new number replaces it.
                                </span>
                            </p>
                        @endif

                        <div class="space-y-1.5">
                            <label class="label" for="verify_phone">Phone number</label>
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <div class="relative flex-1">
                                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-400">
                                        <x-icon name="phone" class="h-4 w-4" />
                                    </span>
                                    <input type="tel"
                                           id="verify_phone"
                                           x-model="phone"
                                           @input="onPhoneInput($event)"
                                           placeholder="08012345678"
                                           inputmode="numeric"
                                           autocomplete="tel"
                                           class="input pl-10 tabular-nums"
                                           :disabled="sending">
                                </div>
                                <button type="button"
                                        class="btn btn-primary shrink-0"
                                        @click="requestCode()"
                                        :disabled="sending">
                                    <x-icon name="paper-airplane" class="h-4 w-4" />
                                    <span x-show="!sending">Send code</span>
                                    <span x-show="sending" x-cloak>Sending&hellip;</span>
                                </button>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Nigerian format, for example 08012345678. The code is valid for 10 minutes.
                            </p>
                        </div>

                        <div class="space-y-1.5" x-show="stage === 'code'" x-cloak>
                            <label class="label" for="verify_code">6-digit code</label>
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <input type="text"
                                       id="verify_code"
                                       x-ref="codeInput"
                                       x-model="code"
                                       @input="onCodeInput($event)"
                                       placeholder="000000"
                                       inputmode="numeric"
                                       autocomplete="one-time-code"
                                       maxlength="6"
                                       class="input font-mono tracking-[0.3em] tabular-nums sm:max-w-[10rem]"
                                       :disabled="verifying">
                                <button type="button"
                                        class="btn btn-primary shrink-0"
                                        @click="verifyCode()"
                                        :disabled="verifying">
                                    <x-icon name="check" class="h-4 w-4" />
                                    <span x-show="!verifying">Verify number</span>
                                    <span x-show="verifying" x-cloak>Verifying&hellip;</span>
                                </button>
                                <button type="button"
                                        class="btn btn-ghost shrink-0"
                                        @click="requestCode()"
                                        :disabled="sending">
                                    Resend code
                                </button>
                            </div>
                        </div>

                        <div class="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"
                             x-show="error"
                             x-cloak>
                            <x-icon name="exclamation-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
                            <span class="min-w-0">
                                <span x-text="error"></span>
                                <span class="mt-1 block text-xs font-medium" x-show="attemptsRemaining !== null" x-cloak>
                                    <span x-text="attemptsRemaining"></span> attempts remaining before this code is cancelled.
                                </span>
                            </span>
                        </div>

                        <div class="flex items-start gap-2 rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-800"
                             x-show="message"
                             x-cloak>
                            <x-icon name="information-circle" variant="solid" class="mt-0.5 h-4 w-4 shrink-0 text-sky-600" />
                            <span x-text="message"></span>
                        </div>

                        <p class="rounded-lg border border-dashed border-border bg-surface p-3 text-xs text-muted-foreground"
                           x-show="debugCode"
                           x-cloak>
                            Development only &mdash; no SMS gateway is configured, so the code is
                            <span class="font-mono font-semibold text-foreground" x-text="debugCode"></span>.
                        </p>
                    </div>
                </section>

                {{-- Notification preferences --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title flex items-center gap-2">
                            <x-icon name="bell" class="h-4 w-4 text-brand-600" />
                            Notification preferences
                        </h2>
                        <p class="card-description">Choose how we reach you. Changes apply immediately.</p>
                    </div>

                    <form method="POST" action="{{ route('profile.notifications') }}">
                        @csrf
                        @method('PUT')

                        <div class="card-content space-y-6">
                            @foreach($notificationGroups as $group)
                                <fieldset class="space-y-2">
                                    <legend class="flex items-center gap-2 text-sm font-semibold">
                                        <x-icon :name="$group['icon']" class="h-4 w-4 text-ink-400" />
                                        {{ $group['label'] }}
                                    </legend>
                                    <div class="space-y-2 pl-6">
                                        @foreach($group['options'] as $option)
                                            <label class="flex items-center gap-2.5 text-sm text-muted-foreground">
                                                <input type="checkbox"
                                                       name="notifications[{{ $group['key'] }}][{{ $option['key'] }}]"
                                                       value="1"
                                                       {{ $prefs[$group['key']][$option['key']] ?? false ? 'checked' : '' }}
                                                       class="checkbox accent-brand-600">
                                                <span>{{ $option['label'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @endforeach
                        </div>

                        <div class="card-footer">
                            <button type="submit" class="btn btn-outline w-full sm:w-auto">
                                <x-icon name="check" class="h-4 w-4" />
                                Save preferences
                            </button>
                        </div>
                    </form>
                </section>
            </div>

            {{-- Sidebar --}}
            <div class="space-y-6">

                {{-- Profile completion --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title flex items-center gap-2">
                            <x-icon name="chart-bar" class="h-4 w-4 text-brand-600" />
                            Profile completion
                        </h2>
                        <p class="card-description">
                            {{ $completion < 100 ? 'A complete profile speeds up checkout and support.' : 'Your profile is complete.' }}
                        </p>
                    </div>

                    <div class="card-content space-y-4">
                        <div class="flex items-center justify-between gap-3">
                            <span class="stat-label">Complete</span>
                            <span class="stat-value">{{ $completion }}%</span>
                        </div>

                        <div class="h-2 w-full overflow-hidden rounded-full bg-ink-100"
                             role="progressbar"
                             aria-valuenow="{{ $completion }}"
                             aria-valuemin="0"
                             aria-valuemax="100"
                             aria-label="Profile completion">
                            <div class="h-full rounded-full bg-primary transition-all" style="width: {{ $completion }}%"></div>
                        </div>

                        <ul class="space-y-2.5 text-sm">
                            @foreach($checklist as $item)
                                <li class="flex items-center gap-2.5">
                                    @if($item['done'])
                                        <x-icon name="check-circle" variant="solid" class="h-4 w-4 shrink-0 text-brand-600" />
                                    @else
                                        <x-icon name="no-symbol" class="h-4 w-4 shrink-0 text-ink-300" />
                                    @endif
                                    <span class="{{ $item['done'] ? 'text-muted-foreground' : 'font-medium' }}">
                                        {{ $item['label'] }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </section>

                {{-- Quick stats --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title flex items-center gap-2">
                            <x-icon name="arrow-trending-up" class="h-4 w-4 text-brand-600" />
                            Quick stats
                        </h2>
                    </div>
                    <dl class="card-content space-y-4">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="stat-label">Transactions</dt>
                            <dd class="stat-value text-lg">{{ number_format($user->transactions()->count()) }}</dd>
                        </div>
                        <div class="divider"></div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="stat-label">Wallet balance</dt>
                            <dd class="stat-value text-lg">&#8358;{{ number_format((float) $user->wallet_balance, 2) }}</dd>
                        </div>
                        <div class="divider"></div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="stat-label">Member since</dt>
                            <dd class="text-base font-semibold">{{ $user->created_at->format('M Y') }}</dd>
                        </div>
                    </dl>
                </section>

                @php
                    /*
                    | The PIN form is shown **on request**, not by default.
                    |
                    | Three reasons, and the third is the one that matters:
                    |
                    |  1. A four-field security form sitting permanently open on a profile
                    |     page is visual noise, and it is the loudest thing on the page for
                    |     the majority of customers who already have a PIN and have no
                    |     intention of changing it.
                    |  2. It invites the wrong click. "New PIN / Confirm PIN" reads as
                    |     something to fill in, and a customer who types a PIN they did not
                    |     mean to set has changed their own credentials by accident.
                    |  3. The form — including its CSRF token and the email-code field —
                    |     is not in the DOM until it is asked for. Server-side gating rather
                    |     than a CSS-hidden panel, so nothing is merely invisible.
                    |
                    | Re-opened automatically when validation failed, because an error
                    | message rendered inside a collapsed form is an error nobody sees.
                    */
                    $pinErrors = ['pin', 'pin_code', 'current_pin', 'pin_otp'];
                    $pinFormOpen = request()->boolean('pin');

                    foreach ($pinErrors as $pinErrorKey) {
                        if ($errors->has($pinErrorKey)) {
                            $pinFormOpen = true;
                        }
                    }

                    $hasPin = (bool) auth()->user()->transaction_pin;
                @endphp

                <div class="card" id="transaction-pin">
                    <div class="card-header">
                        <h2 class="card-title flex items-center gap-2">
                            <x-icon name="lock-closed" class="h-4 w-4 text-ink-500" />
                            Transaction PIN
                        </h2>
                        <p class="card-description">
                            @if($hasPin)
                                <span class="font-medium text-emerald-700">Set.</span>
                                A 4-digit PIN is required for every purchase.
                            @else
                                <span class="font-medium text-amber-700">Not set — you cannot make purchases until you set one.</span>
                            @endif
                        </p>
                    </div>

                    @unless($pinFormOpen)
                        {{-- Collapsed: the status, and one way to act on it. --}}
                        <div class="card-content">
                            <a href="{{ route('profile.index', ['pin' => 1]) }}#transaction-pin"
                               class="btn btn-outline btn-sm">
                                <x-icon name="lock-closed" class="h-4 w-4" />
                                {{ $hasPin ? 'Change PIN' : 'Set PIN' }}
                            </a>

                            @if(! $hasPin)
                                <p class="mt-2 text-xs text-muted-foreground">
                                    You will need a 6-digit code emailed to you.
                                </p>
                            @endif
                        </div>
                    @else
                        <form method="POST" action="{{ route('profile.pin') }}" class="card-content space-y-4">
                            @csrf
                            @method('PUT')

                            @error('pin')
                                <p class="field-error">{{ $message }}</p>
                            @enderror

                            @if($hasPin)
                                <div>
                                    <label for="current_pin" class="label">Current PIN</label>
                                    <input id="current_pin" name="current_pin" type="password"
                                           inputmode="numeric" maxlength="4" required
                                           autocomplete="off"
                                           @input="$event.target.value = $event.target.value.replace(/\D/g,'').slice(0,4)"
                                           class="input mt-1.5 tracking-[0.5em]">
                                </div>
                            @endif

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="new_pin" class="label">New PIN</label>
                                    <input id="new_pin" name="pin" type="password"
                                           inputmode="numeric" maxlength="4" required
                                           autocomplete="new-password"
                                           @input="$event.target.value = $event.target.value.replace(/\D/g,'').slice(0,4)"
                                           class="input mt-1.5 tracking-[0.5em]">
                                </div>
                                <div>
                                    <label for="new_pin_confirmation" class="label">Confirm PIN</label>
                                    <input id="new_pin_confirmation" name="pin_confirmation" type="password"
                                           inputmode="numeric" maxlength="4" required
                                           autocomplete="new-password"
                                           @input="$event.target.value = $event.target.value.replace(/\D/g,'').slice(0,4)"
                                           class="input mt-1.5 tracking-[0.5em]">
                                </div>
                            </div>

                            <p class="text-xs text-muted-foreground">
                                Avoid 1234 or four identical digits. Five wrong attempts locks
                                purchases for 15 minutes.
                            </p>

                            {{-- Authorisation code. The PIN is what authorises every
                                 purchase, so setting it requires proving control of the
                                 account's email, not just the session. --}}
                            <div class="rounded-lg border border-border bg-surface p-4">
                                <label for="pin_code" class="label">Email authorisation code</label>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    We send a 6-digit code to <strong>{{ auth()->user()->email }}</strong>.
                                    It expires in 10 minutes.
                                </p>

                                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                                    <input id="pin_code" name="pin_code" type="text"
                                           inputmode="numeric" maxlength="6" required
                                           autocomplete="one-time-code"
                                           placeholder="000000"
                                           @input="$event.target.value = $event.target.value.replace(/\D/g,'').slice(0,6)"
                                           class="input font-mono tracking-[0.4em] sm:flex-1 @error('pin_code') input-error @enderror">
                                </div>

                                @error('pin_code')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                                @error('pin_otp')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror

                                {{-- Separate form: requesting a code must not submit the
                                     PIN fields, which would fail validation on the way. --}}
                                <button type="submit"
                                        form="requestPinCodeForm"
                                        class="btn btn-outline btn-sm mt-3">
                                    <x-icon name="envelope" class="h-4 w-4" />
                                    Email me a code
                                </button>
                            </div>

                            <div class="flex flex-col gap-2 sm:flex-row">
                                <button type="submit" class="btn btn-primary btn-sm sm:flex-1">
                                    <x-icon name="lock-closed" class="h-4 w-4" />
                                    {{ $hasPin ? 'Change PIN' : 'Set PIN' }}
                                </button>

                                {{-- Abandoning the form leaves no trace: the PIN is not set
                                     until a code and a new PIN are both submitted. --}}
                                <a href="{{ route('profile.index') }}#transaction-pin"
                                   class="btn btn-outline btn-sm sm:flex-1">
                                    Cancel
                                </a>
                            </div>
                        </form>

                        <form id="requestPinCodeForm" method="POST" action="{{ route('profile.pin.code') }}">
                            @csrf
                        </form>
                    @endunless
                </div>
                {{-- Support --}}
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">Need a hand?</h2>
                        <p class="card-description">Profile changes are logged, so support can trace them.</p>
                    </div>
                    <div class="card-content">
                        <a href="{{ route('contact') }}" class="btn btn-outline btn-sm w-full">
                            <x-icon name="lifebuoy" class="h-4 w-4" />
                            Contact support
                        </a>
                    </div>
                </section>
            </div>
        </div>
    </div>
</main>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('profilePhoneVerification', () => ({
        phone: @json((string) ($user->phone ?? '')),
        code: '',
        stage: @json($phoneVerified ? 'done' : 'idle'),
        sending: false,
        verifying: false,
        error: '',
        message: '',
        debugCode: null,
        attemptsRemaining: null,

        // partials/head.blade.php already renders the meta tag; jQuery is gone.
        get csrfToken() {
            const tag = document.querySelector('meta[name="csrf-token"]');
            return tag ? tag.getAttribute('content') : '';
        },

        async post(url, body) {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify(body),
            });

            let data = {};
            try {
                data = await response.json();
            } catch (e) {
                data = {};
            }

            return { ok: response.ok, data: data };
        },

        // Written back to the element so the bound state and the visible value
        // can never disagree (same pattern as the WAEC quantity field).
        onPhoneInput(event) {
            const clean = String(event.target.value || '').replace(/[^0-9]/g, '').slice(0, 11);
            if (event.target.value !== clean) {
                event.target.value = clean;
            }
            this.phone = clean;
        },

        onCodeInput(event) {
            const clean = String(event.target.value || '').replace(/[^0-9]/g, '').slice(0, 6);
            if (event.target.value !== clean) {
                event.target.value = clean;
            }
            this.code = clean;
        },

        async requestCode() {
            this.error = '';
            this.message = '';
            this.attemptsRemaining = null;
            this.code = '';

            if (!/^0[7-9][0-9]{9}$/.test(this.phone)) {
                this.error = 'Enter a valid Nigerian phone number, for example 08012345678.';
                return;
            }

            this.sending = true;

            try {
                const result = await this.post(@json(route('profile.request-verification')), { phone: this.phone });

                if (result.data && result.data.success) {
                    this.stage = 'code';
                    this.message = result.data.message || 'We sent a 6-digit code to your phone.';
                    this.debugCode = result.data.debug_code || null;
                    this.$nextTick(() => {
                        if (this.$refs.codeInput) {
                            this.$refs.codeInput.focus();
                        }
                    });
                } else {
                    this.error = this.firstError(result.data);
                }
            } catch (e) {
                this.error = 'We could not reach the server. Check your connection and try again.';
            } finally {
                this.sending = false;
            }
        },

        async verifyCode() {
            this.error = '';
            this.attemptsRemaining = null;

            if (!/^[0-9]{6}$/.test(this.code)) {
                this.error = 'Enter the 6-digit code from the message.';
                return;
            }

            this.verifying = true;

            try {
                const result = await this.post(@json(route('profile.verify-phone')), { code: this.code });

                if (result.data && result.data.success) {
                    this.stage = 'done';
                    this.message = result.data.message || 'Phone number verified.';

                    // Survives the reload below, which would otherwise throw the
                    // confirmation away and leave the customer guessing.
                    if (window.ReUpFeedback) {
                        window.ReUpFeedback.afterReload(this.message);
                    }

                    window.location.reload();
                    return;
                }

                this.error = this.firstError(result.data);

                if (result.data && result.data.attempts_remaining !== undefined && result.data.attempts_remaining !== null) {
                    this.attemptsRemaining = result.data.attempts_remaining;
                }
            } catch (e) {
                this.error = 'We could not reach the server. Check your connection and try again.';
            } finally {
                this.verifying = false;
            }
        },

        // A 422 from the form request carries {message, errors}; the JSON
        // payloads from the controller carry {success, message}.
        firstError(data) {
            if (data && data.errors) {
                const keys = Object.keys(data.errors);
                if (keys.length && Array.isArray(data.errors[keys[0]]) && data.errors[keys[0]].length) {
                    return data.errors[keys[0]][0];
                }
            }

            return (data && data.message) ? data.message : 'Something went wrong. Please try again.';
        },
    }));
});
</script>
@endpush
