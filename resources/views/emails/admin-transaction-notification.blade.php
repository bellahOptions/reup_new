<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Transaction Alert</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .email-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #3b82f6 0%, #019e1c 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
        }
        .content {
            padding: 30px 20px;
        }
        .alert-badge {
            background: #fef3c7;
            color: #92400e;
            padding: 10px 20px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        .detail-label {
            color: #6b7280;
            font-weight: 500;
        }
        .detail-value {
            color: #111827;
            font-weight: 600;
            text-align: right;
        }
        .highlight-box {
            background: #eff6ff;
            border-left: 4px solid #019e1c;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .footer {
            background: #f9fafb;
            padding: 20px;
            text-align: center;
            color: #6b7280;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>🔔 New Transaction Alert</h1>
        </div>

        <div class="content">
            <div class="alert-badge">
                ⚡ A new {{ $transaction->service_type }} transaction has been completed
            </div>

            <h3>Customer Information</h3>
            <div class="detail-row">
                <span class="detail-label">Customer Name:</span>
                <span class="detail-value">{{ $user->name }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Customer Email:</span>
                <span class="detail-value">{{ $user->email }}</span>
            </div>

            <h3 style="margin-top: 30px;">Transaction Details</h3>
            <div class="detail-row">
                <span class="detail-label">Reference:</span>
                <span class="detail-value">{{ $transaction->reference }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Service Type:</span>
                <span class="detail-value">{{ ucfirst($transaction->service_type) }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Recipient:</span>
                <span class="detail-value">{{ $transaction->recipient }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Network:</span>
                <span class="detail-value">{{ $transaction->provider }}</span>
            </div>
            @if($transaction->plan_name)
            <div class="detail-row">
                <span class="detail-label">Plan:</span>
                <span class="detail-value">{{ $transaction->plan_name }}</span>
            </div>
            @endif
            <div class="detail-row">
                <span class="detail-label">Amount:</span>
                <span class="detail-value">₦{{ number_format($transaction->amount, 2) }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Service Fee:</span>
                <span class="detail-value">₦{{ number_format($transaction->service_fee, 2) }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Total:</span>
                <span class="detail-value" style="color:#10b981; font-size: 18px;">₦{{ number_format($transaction->total_amount, 2) }}</span>
</div>
<div class="highlight-box">
            <strong>Order ID:</strong> {{ $transaction->api_reference }}<br>
            <strong>Status:</strong> {{ ucfirst($transaction->status) }}<br>
            <strong>Date:</strong> {{ $transaction->created_at->format('F d, Y • h:i A') }}
        </div>
    </div>

    <div class="footer">
        <p>This is an automated notification from {{ config('app.name') }}</p>
        <p>© {{ date('Y') }} {{ config('app.name') }}</p>
    </div>
</div>
</body>
</html>