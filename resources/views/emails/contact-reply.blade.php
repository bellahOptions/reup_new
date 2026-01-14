<!DOCTYPE html>
<html>
<head>
    <title>Reply to Your Inquiry</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4F46E5; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .message-box { background: white; border-left: 4px solid #4F46E5; padding: 15px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Response to Your Inquiry</h1>
        </div>
        
        <div class="content">
            <p>Hello <strong>{{ $name }}</strong>,</p>
            
            <p>Thank you for contacting us. Here is our response to your inquiry:</p>
            
            <div class="message-box">
                {!! nl2br(e($replyMessage)) !!}
            </div>
            
            <p>If you have any further questions, please don't hesitate to contact us again.</p>
            
            <p>Best regards,<br>
            <strong>{{ config('app.name') }} Team</strong></p>
        </div>
        
        <div class="footer">
            <p>This is an automated message. Please do not reply to this email.</p>
            <p>© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>