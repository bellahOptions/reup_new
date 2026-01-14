<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New Contact Message</title>
    <style>
        body {
            font-family: 'DM Sans', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 30px;
        }
        .content {
            background: #ffffff;
            padding: 25px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .info-box {
            background: #f9fafb;
            padding: 15px;
            border-radius: 6px;
            border-left: 4px solid #10b981;
            margin: 20px 0;
        }
        .message-box {
            background: #f0f9ff;
            padding: 20px;
            border-radius: 6px;
            border: 1px solid #e0f2fe;
            white-space: pre-wrap;
            line-height: 1.8;
        }
        .btn {
            display: inline-block;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin-top: 20px;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin: 0; font-size: 24px;">📨 New Contact Message</h1>
        <p style="margin: 10px 0 0; opacity: 0.9;">A user ( {{ $contact->name }} ) has submitted a contact form</p>
    </div>

    <div class="content">
        <div class="info-box">
            <h3 style="margin: 0 0 10px; color: #111827;">Sender Information</h3>
            <p style="margin: 5px 0;"><strong>👤 Name:</strong> {{ $contact->name }}</p>
            <p style="margin: 5px 0;"><strong>📧 Email:</strong> {{ $contact->email }}</p>
            <p style="margin: 5px 0;"><strong>📋 Subject:</strong> {{ $contact->subject }}</p>
            @if($contact->user)
                <p style="margin: 5px 0;"><strong>👤 User ID:</strong> #{{ str_pad($contact->user_id, 6, '0', STR_PAD_LEFT) }}</p>
            @endif
            <p style="margin: 5px 0;"><strong>🕒 Time:</strong> {{ $contact->created_at->format('F j, Y \a\t g:i A') }}</p>
        </div>

        <h3 style="color: #111827; margin-top: 25px;">Message Content</h3>
        <div class="message-box">
            {{ $contact
            ->message }}
        </div>

        <div style="margin-top: 30px; text-align: center;">
            <a href="{{ url('/admin/contact/' . $contact->id) }}" class="btn">
                View in Admin Panel →
            </a>
        </div>
    </div>

    <div class="footer">
        <p style="margin: 5px 0;">This is an automated notification from {{ config('app.name') }}</p>
        <p style="margin: 5px 0; font-size: 12px;">Message ID: #{{ str_pad($message->id, 6, '0', STR_PAD_LEFT) }}</p>
    </div>
</body>
</html>