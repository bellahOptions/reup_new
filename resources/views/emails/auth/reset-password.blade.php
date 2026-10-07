<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Reset your password</title>
    {{-- Inline styles; the site's design tokens flattened to hex. See
         emails/auth/verify-email.blade.php for the reasoning. --}}
</head>
<body style="margin:0;padding:0;background-color:#f7f8f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;line-height:1.6;color:#1b1f1b;-webkit-text-size-adjust:100%;">

<div style="display:none;max-height:0;overflow:hidden;opacity:0;">
    Choose a new password for your ReUp account.
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f7f8f7;">
    <tr>
        <td align="center" style="padding:32px 12px;">

            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">

                <tr>
                    <td style="padding:0 4px 18px;">
                        <span style="font-size:20px;font-weight:700;letter-spacing:-0.03em;color:#2AB70D;">ReUp</span>
                        <span style="font-size:12px;color:#98a298;">&nbsp;·&nbsp;Digital Services &amp; Payments</span>
                    </td>
                </tr>

                <tr>
                    <td style="background-color:#ffffff;border:1px solid #dfe3df;border-radius:12px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="height:4px;background-color:#2AB70D;line-height:4px;font-size:0;border-radius:12px 12px 0 0;">&nbsp;</td>
                            </tr>

                            <tr>
                                <td style="padding:32px 32px 8px;">

                                    <h1 style="margin:0 0 6px;font-size:23px;line-height:1.25;font-weight:600;letter-spacing:-0.02em;color:#0d100d;">
                                        Reset your password
                                    </h1>

                                    <p style="margin:0 0 22px;font-size:15px;color:#6f7a6f;">
                                        We received a request to change your password.
                                    </p>

                                    <p style="margin:0 0 16px;font-size:15px;color:#1b1f1b;">
                                        Hi {{ $user->name ?? 'there' }},
                                    </p>

                                    <p style="margin:0 0 24px;font-size:15px;line-height:1.65;color:#1b1f1b;">
                                        Choose a new password for your ReUp account using the button
                                        below. For your security this link can only be used once.
                                    </p>

                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">
                                        <tr>
                                            <td style="background-color:#2AB70D;border-radius:10px;">
                                                <a href="{{ $resetUrl }}"
                                                   style="display:inline-block;padding:15px 34px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
                                                    Choose a new password
                                                </a>
                                            </td>
                                        </tr>
                                    </table>

                                    <p style="margin:0 0 26px;font-size:13px;color:#98a298;">
                                        This link expires in {{ $expireTime ?? 60 }} minutes.
                                    </p>

                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px;background-color:#f7f8f7;border:1px solid #dfe3df;border-radius:10px;">
                                        <tr>
                                            <td style="padding:16px;">
                                                <p style="margin:0 0 6px;font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#98a298;">
                                                    Button not working?
                                                </p>
                                                <p style="margin:0;font-size:12px;line-height:1.5;word-break:break-all;">
                                                    <a href="{{ $resetUrl }}" style="color:#1b720c;text-decoration:underline;">{{ $resetUrl }}</a>
                                                </p>
                                            </td>
                                        </tr>
                                    </table>

                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;background-color:#f1fce9;border:1px solid #c1f0a1;border-radius:10px;">
                                        <tr>
                                            <td style="padding:16px;">
                                                <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#1b720c;">
                                                    Did not request this?
                                                </p>
                                                <p style="margin:0;font-size:13px;line-height:1.6;color:#1b1f1b;">
                                                    You can ignore this email — your password will not change
                                                    until the link above is used. If you are concerned about
                                                    your account, contact support.
                                                </p>
                                            </td>
                                        </tr>
                                    </table>

                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

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
