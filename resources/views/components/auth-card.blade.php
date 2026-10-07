{{--
    Legacy Breeze auth card.

    Only `auth/confirm-password` still references it. It no longer renders its
    own full-height grey canvas (the guest layout already provides the shell);
    it is now simply a card.
--}}
<div class="card">
    <div class="card-content">
        {{ $slot }}
    </div>
</div>
