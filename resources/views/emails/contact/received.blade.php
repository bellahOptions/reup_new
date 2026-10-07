<x-emails.base
    title="New contact message"
    badge="Action required"
    badgeTone="warning"
    :preheader="'From ' . $contact->name . ' — ' . $contact->subject"
>
    <h1 style="margin:16px 0 4px;font-size:21px;font-weight:600;letter-spacing:-0.02em;color:#0d100d;">
        New contact message
    </h1>
    <p style="margin:0 0 22px;font-size:14px;color:#6f7a6f;">
        Submitted through the public contact form.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;border-top:1px solid #dfe3df;">
        @foreach(array_filter([
            'Reference' => '#' . str_pad((string) $contact->id, 6, '0', STR_PAD_LEFT),
            'Name' => $contact->name,
            'Email' => $contact->email,
            'Subject' => $contact->subject,
            'Received' => optional($contact->created_at)->format('F j, Y \a\t g:i A'),
            'Account' => $contact->user?->email,
        ], fn ($v) => filled($v)) as $label => $value)
            <tr>
                <td style="padding:9px 0;border-bottom:1px solid #eef0ee;color:#6f7a6f;width:32%;">{{ $label }}</td>
                <td style="padding:9px 0;border-bottom:1px solid #eef0ee;font-weight:600;color:#1b1f1b;">{{ $value }}</td>
            </tr>
        @endforeach
    </table>

    <p style="margin:22px 0 6px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#98a298;">
        Message
    </p>
    <div style="padding:14px;background-color:#f7f8f7;border:1px solid #dfe3df;border-radius:8px;font-size:14px;color:#1b1f1b;white-space:pre-wrap;">{{ $contact->message }}</div>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 0;">
        <tr>
            <td style="background-color:#2AB70D;border-radius:8px;">
                <a href="{{ route('admin.contact.show', $contact->id) }}"
                   style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
                    Reply in the admin console
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:18px 0 0;font-size:13px;color:#6f7a6f;">
        Replying to this address reaches the customer directly.
    </p>
</x-emails.base>
