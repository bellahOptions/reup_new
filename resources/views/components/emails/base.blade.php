{{--
    Base email shell.

    Email constraints, which is why this looks unlike the web components:
      - most clients strip <style>, so everything is inline;
      - no webfonts and no Vite bundle, so system font stacks are used;
      - layout is table-based for Outlook;
      - no emoji anywhere: clients render them as tofu boxes or full-colour
        glyphs, which reads as broken in a financial receipt.

    Tokens are the web palette flattened to hex:
    brand-500 #2AB70D · brand-50 #f1fce9 · ink-950 #0d100d · ink-500 #6f7a6f
    ink-200 #dfe3df · ink-50 #f7f8f7
--}}
@props([
    'title' => null,
    'badge' => null,
    'badgeTone' => 'brand',
    'preheader' => null,
])

@php
    $tones = [
        'brand' => ['bg' => '#f1fce9', 'fg' => '#1b720c', 'border' => '#c1f0a1'],
        'danger' => ['bg' => '#fef2f2', 'fg' => '#b91c1c', 'border' => '#fecaca'],
        'warning' => ['bg' => '#fffbeb', 'fg' => '#b45309', 'border' => '#fde68a'],
        'neutral' => ['bg' => '#f7f8f7', 'fg' => '#464e46', 'border' => '#dfe3df'],
    ];
    $tone = $tones[$badgeTone] ?? $tones['brand'];
    $appName = config('app.name', 'ReUp');
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $title ?? $appName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f7f8f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;line-height:1.6;color:#1b1f1b;-webkit-text-size-adjust:100%;">

@if($preheader)
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preheader }}</div>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f7f8f7;">
    <tr>
        <td align="center" style="padding:28px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">

                <tr>
                    <td style="padding:0 4px 16px;">
                        <span style="font-size:17px;font-weight:700;letter-spacing:-0.02em;color:#0d100d;">{{ $appName }}</span>
                    </td>
                </tr>

                <tr>
                    <td style="background-color:#ffffff;border:1px solid #dfe3df;border-radius:12px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="height:4px;background-color:#2AB70D;line-height:4px;font-size:0;border-radius:12px 12px 0 0;">&nbsp;</td>
                            </tr>
                            <tr>
                                <td style="padding:28px 28px 8px;">
                                    @if($badge)
                                        <span style="display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;background-color:{{ $tone['bg'] }};color:{{ $tone['fg'] }};border:1px solid {{ $tone['border'] }};">
                                            {{ $badge }}
                                        </span>
                                    @endif

                                    {{ $slot }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 4px 0;font-size:12px;color:#6f7a6f;">
                        <p style="margin:0 0 6px;">
                            {{ $appName }} is operated by Bellah Options (BN3668420).
                        </p>
                        <p style="margin:0 0 6px;">
                            Questions?
                            <a href="mailto:{{ config('services.support.email') }}" style="color:#1b720c;text-decoration:underline;">{{ config('services.support.email') }}</a>
                            @if(config('services.support.phone'))
                                &middot; {{ config('services.support.phone') }}
                            @endif
                        </p>
                        <p style="margin:0;color:#98a298;">
                            This is an automated message. &copy; {{ date('Y') }} {{ $appName }}.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
