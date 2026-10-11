{{--
    Wallet funding receipt.

    Sent by App\Notifications\FundingReceiptNotification, which the
    `payments:send-funding-receipts` sweep dispatches for every funding row that
    has settled and has not been receipted yet.

    Two things it deliberately does NOT do that the payment receipt
    (emails/transaction-receipt.blade.php) does:

      * no "wasn't you?" PIN panel. A card payment is authorised with the
        customer's transaction PIN; money arriving in their wallet is not
        something they authorised at all, and telling them their PIN may have been
        seen would be alarming and false;
      * no service-fee row unless there was a fee. Funding carries no customer
        fee by design — the customer pays exactly the amount they chose — so the
        row would read "Service fee ₦0.00" on every receipt and invite the reader
        to wonder what it used to be.

    Everything else is taken from the transaction row: `balance_before` and
    `balance_after` are written by WalletService under a row lock at the instant
    the money moved, so this is a record rather than a reconstruction.
--}}
<x-emails.base
    :title="'Receipt ' . $transaction->reference"
    badge="Wallet funded"
    badgeTone="brand"
    :preheader="'You added ₦' . number_format((float) $transaction->total_amount, 2) . ' to your wallet — reference ' . $transaction->reference"
>
    @php
        $money = fn ($value) => '₦' . number_format((float) $value, 2);

        $credited = (float) $transaction->total_amount;
        $balanceBefore = is_null($transaction->balance_before) ? null : (float) $transaction->balance_before;
        $balanceAfter = is_null($transaction->balance_after) ? null : (float) $transaction->balance_after;

        $meta = $transaction->meta ?? [];

        /*
         * How the money arrived, in the customer's words rather than ours.
         *
         * The three routes settle through different code paths — a hosted
         * checkout, the gateway's webhook for a transfer into the customer's
         * personal account number, and a second gateway (Bachs) as a fallback —
         * and the customer's own memory of what they did is the thing this line
         * has to match: "I paid with my card", "I transferred from my GTBank
         * app". `payment_method` and the recorded channel between them always
         * name one of those three.
         */
        $source = (function () use ($transaction, $meta) {
            $channel = (string) ($meta['channel'] ?? '');

            if ($transaction->payment_method === 'bank_transfer' || $channel === 'dedicated_nuban') {
                $sender = $meta['sender_bank'] ?? null;

                return $sender ? 'Bank transfer from ' . $sender : 'Bank transfer';
            }

            $gateway = (string) ($meta['gateway'] ?? 'paystack');

            return $gateway === 'paystack' ? 'Card payment' : ucfirst($gateway) . ' payment';
        })();

        /*
         * The payer, for a transfer. Worth showing, because it is the one field a
         * customer can use to recognise *whose* transfer this was — people fund a
         * wallet from a family member's account as often as their own, and a
         * receipt that cannot say who sent it answers the wrong question.
         */
        $narration = $meta['narration'] ?? null;
        $senderName = $meta['sender_name'] ?? null;

        /*
         * How long the funding took, when both ends are known. A transfer is the
         * one funding route with a real wait — the customer leaves the app, opens
         * their bank, and comes back to a wallet that has not moved yet — so "it
         * took 40 seconds" is the reassurance that the system is working.
         */
        $duration = (function () use ($transaction) {
            if (! $transaction->paid_at || ! $transaction->created_at) {
                return null;
            }

            $seconds = $transaction->created_at->diffInSeconds($transaction->paid_at);

            if ($seconds < 1) {
                return 'under a second';
            }

            if ($seconds < 60) {
                return $seconds . ' second' . ($seconds === 1 ? '' : 's');
            }

            $minutes = (int) round($seconds / 60);

            return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        })();

        $rowStyle = 'padding:9px 0;border-bottom:1px solid #eef0ee;';
        $labelStyle = $rowStyle . 'color:#6f7a6f;';
        $valueStyle = $rowStyle . 'text-align:right;font-weight:600;color:#1b1f1b;word-break:break-word;';

        $detailRows = array_filter([
            'How you paid' => $source,
            'Sent by' => $senderName,
            'Note from sender' => $narration,
            /*
             * The receiving account, for a transfer — `recipient` is written as
             * the account number the money landed in by the webhook that credits
             * a dedicated-account deposit. It is the field a customer needs when
             * their bank asks which account they paid into, and it is the one
             * thing that proves the transfer went to *their* number rather than
             * someone else's.
             *
             * Keyed on the channel rather than on `payment_method`: a
             * dedicated-account deposit is recorded with `payment_method =
             * 'paystack'` (the `payment_method` enum has no `dedicated_nuban`
             * member, and an unlisted value would be truncated silently), so the
             * channel is the only reliable marker of this route.
             */
            'Paid into' => ($meta['channel'] ?? null) === 'dedicated_nuban' || $transaction->payment_method === 'bank_transfer'
                ? $transaction->recipient
                : null,
            'Took' => $duration,
            'Status' => 'Successful',
        ], fn ($value) => filled($value));
    @endphp

    <h1 style="margin:16px 0 4px;font-size:22px;font-weight:600;letter-spacing:-0.02em;color:#0d100d;">
        Your wallet has been funded
    </h1>
    <p style="margin:0 0 20px;font-size:14px;color:#6f7a6f;">
        {{ $transaction->description }}
    </p>

    {{-- Headline amount: the number the customer came for, and the only one they
         will read if they read nothing else. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;background-color:#f1fce9;border:1px solid #c1f0a1;border-radius:10px;">
        <tr>
            <td align="center" style="padding:20px 16px;">
                <p style="margin:0 0 6px;font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#1b720c;">
                    Added to your wallet
                </p>
                <p style="margin:0;font-size:32px;font-weight:700;letter-spacing:-0.02em;color:#1b720c;">
                    {{ $money($credited) }}
                </p>
                @if((float) $transaction->service_fee > 0)
                    <p style="margin:6px 0 0;font-size:13px;color:#1b720c;">
                        {{ $money($transaction->total_amount - $transaction->service_fee) }} plus
                        {{ $money($transaction->service_fee) }} service fee
                    </p>
                @endif
            </td>
        </tr>
    </table>

    {{-- How the money arrived. --}}
    <h2 style="margin:0 0 4px;font-size:13px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#98a298;">
        How it arrived
    </h2>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;border-top:1px solid #dfe3df;">
        @foreach($detailRows as $label => $value)
            <tr>
                <td style="{{ $labelStyle }}">{{ $label }}</td>
                <td style="{{ $valueStyle }}">{{ $value }}</td>
            </tr>
        @endforeach
        <tr>
            <td style="{{ $labelStyle }}">Amount</td>
            <td style="{{ $valueStyle }}">{{ $money($transaction->amount) }}</td>
        </tr>
        <tr>
            <td style="padding:12px 0;font-size:15px;font-weight:700;color:#1b1f1b;">Total credited</td>
            <td style="padding:12px 0;text-align:right;font-size:17px;font-weight:700;color:#1b720c;">{{ $money($credited) }}</td>
        </tr>
    </table>

    {{-- Before → after. The part that makes the receipt checkable against the
         customer's own memory of the wallet. --}}
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
            Wallet balance after this funding:
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

                @if($transaction->payment_reference)
                    <p style="margin:0 0 4px;font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#98a298;">
                        Provider reference
                    </p>
                    <p style="margin:0 0 10px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:#6f7a6f;word-break:break-all;">
                        {{ $transaction->payment_reference }}
                    </p>
                @endif

                <p style="margin:0 0 4px;font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#98a298;">
                    Date and time
                </p>
                <p style="margin:0;font-size:13px;color:#464e46;">
                    {{ optional($transaction->created_at)->format('D, j M Y') }}
                    &middot;
                    {{ optional($transaction->created_at)->format('g:i:s A') }} (WAT)
                </p>
            </td>
        </tr>
    </table>

    {{-- What the customer can do with the money. A funding receipt that stops at
         "you have more money now" leaves the next step to be guessed at. --}}
    <h2 style="margin:24px 0 4px;font-size:13px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#98a298;">
        What you can do now
    </h2>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;border-top:1px solid #dfe3df;">
        <tr>
            <td style="padding:9px 0;color:#464e46;line-height:1.65;">
                Buy airtime, data, cable TV and electricity tokens, or generate WAEC and JAMB exam PINs.
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

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0 0;background-color:#f7f8f7;border-radius:10px;">
        <tr>
            <td style="padding:14px;">
                <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#464e46;">
                    ReUp will never ask you for:
                </p>
                <p style="margin:0;font-size:12px;line-height:1.7;color:#6f7a6f;">
                    your password &middot; your transaction PIN &middot; a code sent to your phone
                    or email &middot; your card number or CVV &middot; a transfer to "verify" your
                    account. Anyone who asks for any of these is attempting fraud, no matter how
                    official they sound.
                </p>
            </td>
        </tr>
    </table>

    <p style="margin:16px 0 0;font-size:12px;color:#98a298;line-height:1.6;">
        Keep this email. It is your proof of funding and contains the reference we will
        ask for if you need to raise a dispute.
    </p>
</x-emails.base>
