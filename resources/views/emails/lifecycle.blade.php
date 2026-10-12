{{--
    The lifecycle email shell.

    Wraps an administrator-authored body in the same chrome a receipt uses, so a
    customer receiving both cannot tell they came from two different parts of the
    codebase. The body arrives already sanitised and already substituted — see
    App\Lifecycle\HtmlSanitizer and App\Lifecycle\LifecycleVariables — so this view
    renders it unescaped on purpose, which is the one place in this application where
    that is correct and is why the sanitising happens first, in PHP, rather than here.
--}}
<x-emails.base :title="$subject" :preheader="$subject">
    {!! $body !!}
</x-emails.base>
