{{--
    Transaction PIN entry.

    Required on every purchase. The PIN is verified against a bcrypt hash
    server-side before the debit; this component only collects it.

    `idempotency_key` accompanies it so a double submit — double click, refresh
    of a POST, flaky network retry — returns the original result instead of
    charging twice. It is generated per page load.
--}}
@props([
    'id' => 'pin',
    'label' => 'Transaction PIN',
    'model' => null,
])

@php
    $modelVar = $model ?: $id;
@endphp

<div class="space-y-1.5">
    <label for="{{ $id }}" class="label">{{ $label }}</label>

    <div class="relative" x-data="{ show: false }">
        <input
            id="{{ $id }}"
            name="pin"
            :type="show ? 'text' : 'password'"
            inputmode="numeric"
            autocomplete="off"
            pattern="[0-9]{4}"
            maxlength="4"
            required
            @if($model) x-model="{{ $modelVar }}" @endif
            @input="$event.target.value = $event.target.value.replace(/\D/g, '').slice(0, 4)"
            placeholder="4 digits"
            class="input pr-11 tracking-[0.5em] @error('pin') input-error @enderror">

        <button type="button"
                @click="show = !show"
                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-ink-400 hover:text-ink-700"
                :aria-label="show ? 'Hide PIN' : 'Show PIN'">
            <x-icon name="eye" class="h-4 w-4" x-show="!show" />
            <x-icon name="eye-slash" class="h-4 w-4" x-show="show" x-cloak />
        </button>
    </div>

    @error('pin')
        <p class="field-error">{{ $message }}</p>
    @else
        <p class="text-xs text-muted-foreground">
            Set or change your PIN in <a href="{{ route('profile.index') }}" class="link">profile settings</a>.
        </p>
    @enderror
</div>
