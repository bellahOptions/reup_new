<x-emails.base
    :title="'New transaction — ' . $transaction->reference"
    badge="Transaction alert"
    badgeTone="warning"
    :preheader="'A ' . $transaction->service_type . ' transaction just settled for ' . ($user->name ?? 'a customer')"
>
    <h1 style="margin:16px 0 4px;font-size:21px;font-weight:600;letter-spacing:-0.02em;color:#0d100d;">
        Transaction settled
    </h1>
    <p style="margin:0 0 22px;font-size:14px;color:#6f7a6f;">
        {{ $transaction->description }}
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;border-top:1px solid #dfe3df;">
        @php
            $rows = array_filter([
                'Customer' => $user->name ?? null,
                'Email' => $user->email ?? null,
                'Phone' => $user->phone ?? null,
                'Service' => ucfirst(str_replace('-', ' ', (string) $transaction->service_type)),
                'Recipient' => $transaction->recipient,
                'Provider' => $transaction->provider,
                'Plan' => $transaction->plan_name,
                'Amount' => '₦' . number_format((float) $transaction->amount, 2),
                'Service fee' => '₦' . number_format((float) $transaction->service_fee, 2),
                'Provider reference' => $transaction->api_reference,
            ], fn ($value) => filled($value));
        @endphp

        @foreach($rows as $label => $value)
            <tr>
                <td style="padding:9px 0;border-bottom:1px solid #eef0ee;color:#6f7a6f;width:44%;">{{ $label }}</td>
                <td style="padding:9px 0;border-bottom:1px solid #eef0ee;font-weight:600;color:#1b1f1b;">{{ $value }}</td>
            </tr>
        @endforeach

        <tr>
            <td style="padding:13px 0;font-weight:600;color:#1b1f1b;">Total charged</td>
            <td style="padding:13px 0;font-size:18px;font-weight:700;color:#0d100d;">
                ₦{{ number_format((float) $transaction->total_amount, 2) }}
            </td>
        </tr>
    </table>

    <p style="margin:14px 0 0;font-size:13px;color:#6f7a6f;">
        Status:
        <strong style="color:{{ $transaction->status === 'success' ? '#1b720c' : '#b45309' }};">
            {{ ucfirst((string) $transaction->status) }}
        </strong>
        @if($transaction->status_message)
            &middot; {{ $transaction->status_message }}
        @endif
    </p>

    <p style="margin:6px 0 0;font-size:13px;color:#98a298;">
        {{ optional($transaction->created_at)->format('F j, Y \a\t g:i A') }}
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 0;">
        <tr>
            <td style="background-color:#2AB70D;border-radius:8px;">
                <a href="{{ route('admin.transactions.index') }}"
                   style="display:inline-block;padding:12px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
                    Open in the admin console
                </a>
            </td>
        </tr>
    </table>
</x-emails.base>
