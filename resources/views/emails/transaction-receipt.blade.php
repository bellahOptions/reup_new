<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Receipt</title>
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
            background: linear-gradient(135deg, #10b926 0%, #059605 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: bold;
        }
        .status-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 20px;
            margin-top: 10px;
            font-size: 14px;
        }
        .content {
            padding: 30px 20px;
        }
        .success-icon {
            text-align: center;
            font-size: 60px;
            margin-bottom: 20px;
        }
        .receipt-title {
            text-align: center;
            color: #10b981;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .receipt-subtitle {
            text-align: center;
            color: #6b7280;
            margin-bottom: 30px;
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
        .total-row {
            background: #f9fafb;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .total-label {
            font-size: 18px;
            font-weight: 600;
            color: #374151;
        }
        .total-value {
            font-size: 24px;
            font-weight: bold;
            color: #10b981;
        }
        .reference-box {
            background: #f3f4f6;
            border: 2px dashed #d1d5db;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin: 20px 0;
        }
        .reference-label {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 5px;
        }
        .reference-value {
            font-family: 'Courier New', monospace;
            font-size: 16px;
            font-weight: bold;
            color: #111827;
        }
        .footer {
            background: #f9fafb;
            padding: 20px;
            text-align: center;
            color: #6b7280;
            font-size: 12px;
        }
        .button {
            display: inline-block;
            background: #10b981;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin: 20px 0;
        }
        .support-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="header">
            <h1>{{ config('app.name') }}</h1>
            <div class="status-badge">✓ Transaction Successful</div>
        </div>

        <!-- Content -->
        <div class="content">
            <div class="success-icon">🎉</div>
            
            <h2 class="receipt-title">Payment Receipt</h2>
            <p class="receipt-subtitle">Thank you for your purchase!</p>

            <!-- Transaction Details -->
            <div class="detail-row">
                <span class="detail-label">Service Type:</span>
                <span class="detail-value">{{ ucfirst($transaction->service_type) }}</span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Recipient:</span>
                <span class="detail-value">{{ $transaction->recipient }}</span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Network Provider:</span>
                <span class="detail-value">{{ $transaction->provider }}</span>
            </div>

            @if($transaction->plan_name)
            <div class="detail-row">
                <span class="detail-label">Data Plan:</span>
                <span class="detail-value">{{ $transaction->plan_name }}</span>
            </div>
            @endif

            @if($transaction->api_reference)
            <div class="detail-row">
                <span class="detail-label">Order ID:</span>
                <span class="detail-value">{{ $transaction->api_reference }}</span>
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

            <!-- Total -->
            <div class="total-row">
                <span class="total-label">Total Paid:</span>
                <span class="total-value">₦{{ number_format($transaction->total_amount, 2) }}</span>
            </div>

            <!-- Reference Number -->
            <div class="reference-box">
                <div class="reference-label">TRANSACTION REFERENCE:</div>
                <div class="reference-value">{{ $transaction->reference }}</div>
            </div>

            <!-- New Balance -->
            <div class="detail-row" style="border-bottom: none;">
                <span class="detail-label">New Wallet Balance:</span>
                <span class="detail-value" style="color: #10b981;">₦{{ number_format($transaction->balance_after, 2) }}</span>
            </div>

            <!-- Transaction Date -->
            <p style="text-align: center; color: #6b7280; font-size: 14px; margin-top: 20px;">
                {{ $transaction->created_at->format('F d, Y • h:i A') }}
            </p>

            <!-- CTA Button -->
            <div style="text-align: center;">
                <a href="{{ url('/dashboard') }}" class="button">View Dashboard</a>
            </div>

            <!-- Support Box -->
            <div class="support-box">
                <strong>Need Help?</strong><br>
                If you have any questions about this transaction, please contact our support team.
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>This is an automated email from {{ config('app.name') }}.</p>
            <p>© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>