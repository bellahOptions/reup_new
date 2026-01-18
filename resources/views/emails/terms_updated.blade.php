<!DOCTYPE html>
<html>
<head>
    <title>Important Update: {{ $documentType }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f8f9fa; padding: 20px; border-radius: 5px; }
        .content { padding: 20px; }
        .button { display: inline-block; padding: 10px 20px; background-color: #007bff; 
                 color: white; text-decoration: none; border-radius: 5px; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; 
                 font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Important Update: {{ $documentType }}</h2>
        </div>
        
        <div class="content">
            <p>Hello {{ $user->name }},</p>
            
            <p>We're writing to inform you that our <strong>{{ $documentType }}</strong> 
               has been updated with new revisions effective from {{ $effectiveDate }}.</p>
            
            <p><strong>Version Date:</strong> {{ $versionDate }}</p>
            
            @if($updatedBy)
                <p><strong>Updated by:</strong> {{ $updatedBy->name }}</p>
            @endif
            
            <p>To ensure you're familiar with the latest policies and terms governing 
               your use of our services, please review the updated document.</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="{{ $viewUrl }}" class="button">
                    Review Updated {{ $documentType }}
                </a>
            </div>
            
            <p>By continuing to use our services, you acknowledge and agree to the 
               updated {{ $documentType }}.</p>
            
            <p>If you have any questions or concerns, please don't hesitate to 
               contact our support team.</p>
            
            <p>Best regards,<br>The {{ config('app.name') }} Team</p>
        </div>
        
        <div class="footer">
            <p>This is an automated message. Please do not reply to this email.</p>
            <p>If you no longer wish to receive these notifications, you can update 
               your email preferences in your account settings.</p>
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>