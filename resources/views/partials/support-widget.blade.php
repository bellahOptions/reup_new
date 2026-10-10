@php
    /*
    |--------------------------------------------------------------------------
    | Support entry point
    |--------------------------------------------------------------------------
    | A WhatsApp click-to-chat bubble. This replaced an in-app live chat whose
    | entry point was a first-party chat page: the bubble is what customers
    | actually use, and WhatsApp reaches support on the device they already have
    | open rather than asking them to wait in a tab.
    |
    | The URL is built once in AppServiceProvider from
    | `config('services.support.whatsapp')` — see the note there on the wa.me
    | number format. When no number is configured the bubble is omitted entirely
    | rather than rendered as a dead link.
    |
    | `target="_blank"` with `rel="noopener noreferrer"`: noopener is required
    | so the opened page cannot reach back through `window.opener`, and
    | noreferrer additionally keeps the current URL out of WhatsApp's referrer.
    */
    $whatsappUrl = config('services.support.whatsapp_url');
@endphp

@if($whatsappUrl)
    {{--
        `text-[#0d100d]`, not `text-white` and not `text-ink-950`.

        WhatsApp's brand green is a fixed, light, saturated fill — it does not
        change with the theme. So its label needs a *fixed* dark colour:

          * `text-white` is 1.98:1 on it;
          * `text-ink-950` looks right but is themeable, and in dark mode it
            resolves to `#f7f8f7` — near-white again, for the same 1.98:1. The
            ink ramp inverts because it tracks the page, and this fill does not.

        `#0d100d` is the literal the other brand fills use (see
        `--color-primary-foreground` in the dark block, which is the same
        problem: a light brand fill with white text). About 9:1 here.

        The brand fill and the `#128C7E` focus ring stay as they are — they are
        WhatsApp's, not ours, and the ring only has to be distinguishable
        against the page, which it is.
    --}}
    <a href="{{ $whatsappUrl }}"
       target="_blank"
       rel="noopener noreferrer"
       class="fixed bottom-5 right-5 z-40 inline-flex items-center gap-2 rounded-full bg-[#25D366] px-4 py-3 text-sm font-semibold text-[#0d100d] shadow-overlay transition-transform hover:scale-[1.03] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#128C7E]"
       aria-label="Chat with ReUp support on WhatsApp (opens in a new tab)">
        <x-icon name="whatsapp" variant="solid" class="h-5 w-5" />
        <span class="hidden sm:inline">Chat on WhatsApp</span>
    </a>
@endif
