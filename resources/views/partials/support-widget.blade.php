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
    <a href="{{ $whatsappUrl }}"
       target="_blank"
       rel="noopener noreferrer"
       class="fixed bottom-5 right-5 z-40 inline-flex items-center gap-2 rounded-full bg-[#25D366] px-4 py-3 text-sm font-semibold text-white shadow-overlay transition-transform hover:scale-[1.03] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#128C7E]"
       aria-label="Chat with ReUp support on WhatsApp (opens in a new tab)">
        <x-icon name="whatsapp" variant="solid" class="h-5 w-5" />
        <span class="hidden sm:inline">Chat on WhatsApp</span>
    </a>
@endif
