<x-emails.base
    title="We received your message"
    badge="Message received"
    badgeTone="brand"
    :preheader="'Your reference is #' . str_pad((string) $contact->id, 6, '0', STR_PAD_LEFT)"
>
    <h1 style="margin:16px 0 4px;font-size:21px;font-weight:600;letter-spacing:-0.02em;color:#0d100d;">
        Thanks — we have your message
    </h1>
    <p style="margin:0 0 22px;font-size:14px;color:#6f7a6f;">
        A member of the support team will reply to {{ $contact->email }}.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;border-top:1px solid #dfe3df;">
        @foreach([
            'Reference' => '#' . str_pad((string) $contact->id, 6, '0', STR_PAD_LEFT),
            'Subject' => $contact->subject,
            'Submitted' => optional($contact->created_at)->format('F j, Y \a\t g:i A'),
        ] as $label => $value)
            <tr>
                <td style="padding:9px 0;border-bottom:1px solid #eef0ee;color:#6f7a6f;width:32%;">{{ $label }}</td>
                <td style="padding:9px 0;border-bottom:1px solid #eef0ee;font-weight:600;color:#1b1f1b;">{{ $value }}</td>
            </tr>
        @endforeach
    </table>

    <p style="margin:22px 0 6px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#98a298;">
        Your message
    </p>
    <div style="padding:14px;background-color:#f7f8f7;border:1px solid #dfe3df;border-radius:8px;font-size:14px;color:#1b1f1b;white-space:pre-wrap;">{{ $contact->message }}</div>

    <p style="margin:22px 0 0;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#98a298;">
        What happens next
    </p>
    <ol style="margin:10px 0 0;padding-left:20px;font-size:14px;color:#464e46;">
        <li style="margin-bottom:6px;">We review and route your message to the right team.</li>
        <li style="margin-bottom:6px;">An agent replies to <strong>{{ $contact->email }}</strong>.</li>
        <li>Most enquiries are answered within one business day.</li>
    </ol>

    <p style="margin:22px 0 0;padding:14px;background-color:#f7f8f7;border-radius:8px;font-size:13px;color:#6f7a6f;">
        Keep this reference for your records: <strong>#{{ str_pad((string) $contact->id, 6, '0', STR_PAD_LEFT) }}</strong>.
        Reply to this email to add anything you forgot.
    </p>
</x-emails.base>
