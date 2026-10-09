<x-emails.base
    :title="'Receipt ' . $transaction->reference"
    badge="Payment successful"
    badgeTone="brand"
    :preheader="'You paid ₦' . number_format((float) $transaction->total_amount, 2) . ' — reference ' . $transaction->reference"
>
    @php
        /*
         * Everything below is built from the transaction row. Nothing is
         * recomputed: `balance_before` and `balance_after` are written by
         * WalletService at the instant the money moved, under a row lock, so
         * they are a genuine audit trail rather than a number assembled for the
         * email.
         *
         * Money is the one thing customers re-read weeks later — usually while
         * arguing with support — so the receipt is deliberately dense: full
         * identifiers, both balances, the exact moment, and where it came from.
         */
        $money = fn ($value) => '₦' . number_format((float) $value, 2);

        $charged = (float) $transaction->total_amount;
        $balanceBefore = is_null($transaction->balance_before) ? null : (float) $transaction->balance_before;
        $balanceAfter = is_null($transaction->balance_after) ? null : (float) $transaction->balance_after;

        $meta = $transaction->meta ?? [];
        $requestIp = $meta['request_ip'] ?? null;
        $userAgent = $meta['request_user_agent'] ?? null;

        // "Chrome on Windows" reads far better in a security block than the raw
        // UA string, which is mostly noise. The full string is not discarded —
        // it remains available to support from the admin console.
        $device = (function () use ($userAgent) {
            if (! $userAgent) {
                return null;
            }

            $os = match (true) {
                stripos($userAgent, 'windows') !== false => 'Windows',
                stripos($userAgent, 'android') !== false => 'Android',
                stripos($userAgent, 'iphone') !== false => 'iOS',
                stripos($userAgent, 'ipad') !== false => 'iOS',
                stripos($userAgent, 'mac os') !== false => 'macOS',
                stripos($userAgent, 'linux') !== false => 'Linux',
                default => null,
            };

            $browser = match (true) {
                stripos($userAgent, 'edg/') !== false => 'Edge',
                stripos($userAgent, 'opr/') !== false => 'Opera',
                stripos($userAgent, 'opera') !== false => 'Opera',
                stripos($userAgent, 'chrome') !== false => 'Chrome',
                stripos($userAgent, 'safari') !== false => 'Safari',
                stripos($userAgent, 'firefox') !== false => 'Firefox',
                default => null,
            };

            if ($browser && $os) {
                return $browser . ' on ' . $os;
            }

            return $browser ?? $os;
        })();

        $rowStyle = 'padding:9px 0;border-bottom:1px solid #eef0ee;';
        $labelStyle = $rowStyle . 'color:#6f7a6f;';
        $valueStyle = $rowStyle . 'text-align:right;font-weight:600;color:#1b1f1b;word-break:break-word;';

        /*
         * The customer's mobile network — MTN, Airtel, Glo or 9mobile — never the
         * upstream provider.
         *
         * This row previously read "Network / provider" and printed
         * `$transaction->provider`, which the payment pipeline overwrites with the
         * adapter's label. So a customer's receipt said "Network: ClubKonnect":
         * the API ReUp buys from, presented as their mobile operator. The two are
         * now separate, and only the network is shown here.
         *
         * `hasResolvedNetwork()` guards the row so a product with no network at all
         * (a wallet top-up, a reversal) omits it rather than printing a placeholder.
         */
        $lineItems = array_filter([
            'Service' => ucfirst(str_replace('-', ' ', (string) $transaction->service_type)),
            'Recipient' => $transaction->recipient,
            'Network' => $transaction->hasResolvedNetwork() ? $transaction->network_display : null,
            'Plan' => $transaction->plan_name,
            'Plan type' => $transaction->plan_type,
        ], fn ($value) => filled($value));

        $auditRows = array_filter([
            'Date' => optional($transaction->created_at)->format('D, j M Y'),
            'Time' => optional($transaction->created_at)->format('g:i:s A') . ' (WAT)',
            'Payment method' => ucwords(str_replace('_', ' ', (string) $transaction->payment_method)),
            'Channel' => filled($meta['channel'] ?? null) ? ucwords(str_replace('_', ' ', (string) $meta['channel'])) : null,
            'Initiated from' => $requestIp,
            'Device' => $device,
            'Status' => ucfirst((string) $transaction->status),
        ], fn ($value) => filled($value));
    @endphp

    <h1 style="margin:16px 0 4px;font-size:22px;font-weight:600;letter-spacing:-0.02em;color:#0d100d;">
        Your receipt
    </h1>
    <p style="margin:0 0 20px;font-size:14px;color:#6f7a6f;">
        {{ $transaction->description }}
    </p>

    {{-- Headline amount: the number the customer came for. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;background-color:#f1fce9;border:1px solid #c1f0a1;border-radius:10px;">
        <tr>
            <td align="center" style="padding:20px 16px;">
                <p style="margin:0 0 6px;font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#1b720c;">
                    Total paid
                </p>
                <p style="margin:0;font-size:32px;font-weight:700;letter-spacing:-0.02em;color:#1b720c;">
                    {{ $money($charged) }}
                </p>
                @if((float) $transaction->service_fee > 0)
                    <p style="margin:6px 0 0;font-size:13px;color:#1b720c;">
                        {{ $money($transaction->amount) }} + {{ $money($transaction->service_fee) }} service fee
                    </p>
                @endif
            </td>
        </tr>
    </table>

    {{-- What was bought --}}
    <h2 style="margin:0 0 4px;font-size:13px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#98a298;">
        What you paid for
    </h2>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;border-top:1px solid #dfe3df;">
        @foreach($lineItems as $label => $value)
            <tr>
                <td style="{{ $labelStyle }}">{{ $label }}</td>
                <td style="{{ $valueStyle }}">{{ $value }}</td>
            </tr>
        @endforeach
        <tr>
            <td style="{{ $labelStyle }}">Amount</td>
            <td style="{{ $valueStyle }}">{{ $money($transaction->amount) }}</td>
        </tr>
        {{--
            The fee row is omitted when there is no fee, rather than printing
            "Service fee ₦0.00". Airtime now carries no customer fee at all, so the
            row would appear on every airtime receipt asserting a charge of nothing —
            which invites the reader to wonder what it used to be. It still renders
            whenever a Super Admin has deliberately enabled a fee on the rule.
        --}}
        @if((float) $transaction->service_fee > 0)
            <tr>
                <td style="{{ $labelStyle }}">Service fee</td>
                <td style="{{ $valueStyle }}">{{ $money($transaction->service_fee) }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding:12px 0;font-size:15px;font-weight:700;color:#1b1f1b;">Total charged</td>
            <td style="padding:12px 0;text-align:right;font-size:17px;font-weight:700;color:#1b720c;">{{ $money($charged) }}</td>
        </tr>
    </table>

    {{--
        Before → after. This is the part that makes the receipt verifiable: the
        customer can check both figures against their own memory of the wallet,
        so any discrepancy is immediately visible rather than something they
        would have to work out.
    --}}
    @if(! is_null($balanceBefore) && ! is_null($balanceAfter))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0 0;background-color:#f7f8f7;border:1px solid #dfe3df;border-radius:10px;">
            <tr>
                <td align="center" style="padding:16px;">
                    <p style="margin:0 0 10px;font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#98a298;">
                        Wallet balance
                    </p>
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
                        <tr>
                            <td style="padding:0 10px;text-align:center;">
                                <p style="margin:0;font-size:11px;color:#98a298;">Before</p>
                                <p style="margin:2px 0 0;font-size:16px;font-weight:600;color:#6f7a6f;">{{ $money($balanceBefore) }}</p>
                            </td>
                            <td style="padding:0 6px;font-size:18px;color:#98a298;">&rarr;</td>
                            <td style="padding:0 10px;text-align:center;">
                                <p style="margin:0;font-size:11px;color:#98a298;">After</p>
                                <p style="margin:2px 0 0;font-size:18px;font-weight:700;color:#1b1f1b;">{{ $money($balanceAfter) }}</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    @elseif(! is_null($balanceAfter))
        <p style="margin:14px 0 0;font-size:14px;color:#6f7a6f;">
            Wallet balance after this transaction:
            <strong style="color:#1b1f1b;">{{ $money($balanceAfter) }}</strong>
        </p>
    @endif

    {{-- Identifiers, for support and for the customer's own records. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0;background-color:#f7f8f7;border:1px dashed #dfe3df;border-radius:8px;">
        <tr>
            <td style="padding:14px;">
                <p style="margin:0 0 4px;font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#98a298;">
                    Your reference
                </p>
                <p style="margin:0 0 10px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:15px;font-weight:700;color:#1b1f1b;word-break:break-all;">
                    {{ $transaction->reference }}
                </p>
                @if($transaction->uuid)
                    <p style="margin:0 0 4px;font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#98a298;">
                        Gateway reference
                    </p>
                    <p style="margin:0;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:#6f7a6f;word-break:break-all;">
                        {{ $transaction->uuid }}
                    </p>
                @endif
            </td>
        </tr>
    </table>

    {{-- Security block --}}
    <h2 style="margin:24px 0 4px;font-size:13px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#98a298;">
        Security details
    </h2>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;border-top:1px solid #dfe3df;">
        @foreach($auditRows as $label => $value)
            <tr>
                <td style="{{ $labelStyle }}">{{ $label }}</td>
                <td style="{{ $valueStyle }}">{{ $value }}</td>
            </tr>
        @endforeach
    </table>

    {{-- The "was this you?" panel. This is the single most useful thing a
         transaction email can do: it is read at the moment of doubt. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0;background-color:#fffbeb;border:1px solid #fde68a;border-radius:10px;">
        <tr>
            <td style="padding:16px;">
                <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#b45309;">
                    Wasn't you? Act now.
                </p>
                <p style="margin:0 0 10px;font-size:13px;line-height:1.65;color:#1b1f1b;">
                    This transaction was authorised with your ReUp transaction PIN@if($device), from the
                    {{ $device }} shown above@endif. If you did not make it, your PIN may
                    have been seen by someone else. Do all three of these immediately:
                </p>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:13px;color:#1b1f1b;">
                    <tr>
                        <td style="padding:3px 0;vertical-align:top;width:18px;font-weight:700;color:#b45309;">1.</td>
                        <td style="padding:3px 0;line-height:1.6;">Change your ReUp password.</td>
                    </tr>
                    <tr>
                        <td style="padding:3px 0;vertical-align:top;width:18px;font-weight:700;color:#b45309;">2.</td>
                        <td style="padding:3px 0;line-height:1.6;">Change your transaction PIN in Profile &rarr; Security.</td>
                    </tr>
                    <tr>
                        <td style="padding:3px 0;vertical-align:top;width:18px;font-weight:700;color:#b45309;">3.</td>
                        <td style="padding:3px 0;line-height:1.6;">
                            Contact us with reference
                            <strong style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;">{{ $transaction->reference }}</strong>.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0 0;background-color:#f7f8f7;border-radius:10px;">
        <tr>
            <td style="padding:14px;">
                <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#464e46;">
                    ReUp will never ask you for:
                </p>
                <p style="margin:0;font-size:12px;line-height:1.7;color:#6f7a6f;">
                    your password &middot; your transaction PIN &middot; a code sent to your phone
                    or email &middot; your card number or CVV &middot; a transfer to "verify" your
                    account. Anyone who asks for any of these is attempting fraud, no matter how
                    official they sound. We do not phone customers to request them, and we never
                    ask you to install an app to receive a refund.
                </p>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0 0;">
        <tr>
            <td style="background-color:#2AB70D;border-radius:8px;">
                <a href="{{ route('wallet.history') }}"
                   style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
                    View in your dashboard
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:16px 0 0;font-size:12px;color:#98a298;line-height:1.6;">
        Keep this email. It is your proof of purchase and contains the reference we will
        ask for if you need to raise a dispute. Please do not forward it — it contains
        details about your account.
    </p>
</x-emails.base>
