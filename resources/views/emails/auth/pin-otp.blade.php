@php
    /*
    |--------------------------------------------------------------------------
    | Transaction PIN authorisation code
    |--------------------------------------------------------------------------
    | Sent when a signed-in user sets or changes their transaction PIN. Worded
    | distinctly from the sign-in code on purpose: a customer who did not request
    | a PIN change must be able to tell the two apart at a glance, and the subject
    | line states what the code is for.
    |
    | Same construction as emails/auth/verify-email: a full HTML document, table
    | layout for Outlook, every style inlined because most clients strip <style>
    | blocks and none can load the Vite bundle. Tokens are flattened to hex —
    | brand #2AB70D, ink-950 #0d100d, ink-500 #6f7a6f, ink-200 #dfe3df,
    | ink-50 #f7f8f7.
    |
    | The code is the entire purpose of this message, so it is set large, in a
    | monospace stack, with letter-spacing so adjacent digits stay readable.
    */
    $digits = implode(' ', str_split($code));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Your PIN authorisation code</title>
</head>
<body style="margin:0;padding:0;background-color:#f7f8f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;line-height:1.6;color:#1b1f1b;-webkit-text-size-adjust:100%;">

{{-- Preheader: shown in the inbox list, hidden in the body. Deliberately does
     NOT contain the code, so the code is not exposed on a locked phone's
     notification preview. --}}
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">
    The code to authorise your transaction PIN is inside. It expires in {{ $expireTime }} minutes.
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f7f8f7;">
    <tr>
        <td align="center" style="padding:32px 12px;">

            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">

                {{-- Wordmark, set as text so it never depends on a remote image. --}}
                <tr>
                    <td style="padding:0 4px 18px;">
                        <span style="font-size:20px;font-weight:700;letter-spacing:-0.03em;color:#2AB70D;">ReUp</span>
                        <span style="font-size:12px;color:#98a298;">&nbsp;·&nbsp;Digital Services &amp; Payments</span>
                    </td>
                </tr>

                {{-- Card --}}
                <tr>
                    <td style="background-color:#ffffff;border:1px solid #dfe3df;border-radius:12px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="height:4px;background-color:#2AB70D;line-height:4px;font-size:0;border-radius:12px 12px 0 0;">&nbsp;</td>
                            </tr>

                            <tr>
                                <td style="padding:32px 32px 8px;">

                                    <h1 style="margin:0 0 6px;font-size:23px;line-height:1.25;font-weight:600;letter-spacing:-0.02em;color:#0d100d;">
                                        Your PIN authorisation code
                                    </h1>

                                    <p style="margin:0 0 22px;font-size:15px;color:#6f7a6f;">
                                        Enter it in your ReUp profile to set your transaction PIN.
                                    </p>

                                    <p style="margin:0 0 16px;font-size:15px;color:#1b1f1b;">
                                        Hi {{ $user->name }},
                                    </p>

                                    <p style="margin:0 0 24px;font-size:15px;line-height:1.65;color:#1b1f1b;">
                                        Use the code below to authorise a change to your transaction PIN. No PIN
                                        change happens without it.
                                    </p>

                                    {{-- The code. --}}
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;background-color:#f7f8f7;border:1px solid #dfe3df;border-radius:10px;">
                                        <tr>
                                            <td align="center" style="padding:22px 16px;">
                                                <p style="margin:0 0 10px;font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#98a298;">
                                                    Authorisation code
                                                </p>
                                                <p style="margin:0;font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;font-size:34px;font-weight:700;letter-spacing:0.14em;color:#0d100d;">
                                                    {{ $digits }}
                                                </p>
                                            </td>
                                        </tr>
                                    </table>

                                    <p style="margin:0 0 26px;font-size:13px;color:#98a298;">
                                        This code expires in {{ $expireTime }} minutes and can be used once. Until it is used, your current PIN remains unchanged.
                                    </p>

                                    {{-- Security notice: mirrors the card treatment on the
                                         verify-email template so both read as one product. --}}
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;background-color:#f1fce9;border:1px solid #c1f0a1;border-radius:10px;">
                                        <tr>
                                            <td style="padding:16px;">
                                                <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#1b720c;">
                                                    Security notice
                                                </p>
                                                <p style="margin:0;font-size:13px;line-height:1.6;color:#1b1f1b;">
                                                    ReUp will never ask you for this code, your password,
                                                    your transaction PIN or your card details. <strong>If you did
                                                    not try to change your PIN</strong>, someone may be using your
                                                    ReUp session: ignore this code, change your password
                                                    immediately, and contact support.
                                                </p>
                                            </td>
                                        </tr>
                                    </table>

                                    @if($requestedIp)
                                        <p style="margin:14px 0 0;font-size:12px;color:#98a298;">
                                            Requested from IP address {{ $requestedIp }}.
                                        </p>
                                    @endif

                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="padding:22px 4px 0;font-size:12px;color:#6f7a6f;">
                        <p style="margin:0 0 6px;">
                            ReUp is operated by Bellah Options (BN3668420).
                        </p>
                        <p style="margin:0 0 6px;">
                            Questions?
                            <a href="mailto:{{ config('services.support.email') }}" style="color:#1b720c;text-decoration:underline;">{{ config('services.support.email') }}</a>
                            @if(config('services.support.phone'))
                                &middot; {{ config('services.support.phone') }}
                            @endif
                        </p>
                        <p style="margin:0;color:#98a298;">
                            This is an automated message. &copy; {{ date('Y') }} ReUp. All rights reserved.
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
