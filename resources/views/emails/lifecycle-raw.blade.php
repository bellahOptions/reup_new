{{--
    The rendered lifecycle body, on its own.

    The body was wrapped in `emails.lifecycle` at schedule time and the finished
    document is what `LifecycleMessage::html_body` holds, so this view exists only to
    hand an already-complete HTML string to the mailer. Nothing is escaped and nothing
    is added: the escaping and the sanitising happened in PHP before the string was
    stored, and repeating either here would double-encode entities in a body that is
    already correct.
--}}
{!! $html !!}
