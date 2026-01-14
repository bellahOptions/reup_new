<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Password Reset – ReUp</title>
</head>
<body style="margin:0; padding:0; background-color:#F3F4F6; font-family:Arial, Helvetica, sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#F3F4F6; padding:40px 0;">
        <tr>
            <td align="center">

                <!-- Email Container -->
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background:#ffffff; border-radius:14px; box-shadow:0 8px 20px rgba(0,0,0,0.08); overflow:hidden;">

                    <!-- Header -->
                    <tr>
                        <td style="padding:30px; text-align:center;">
                            <img src="https://i.postimg.cc/VNMvqyqc/12.png"
                                 alt="ReUp"
                                 style="height:50px; width:auto; display:block; margin:0 auto 10px;">

                            <h1 style="margin:0; font-size:24px; color:#2AB70D; font-weight:700;">
                                ReUp
                            </h1>

                            <p style="margin:4px 0 0; font-size:12px; color:#6B7280;">
                                Digital Services & Payments
                            </p>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:40px; color:#1F2937;">

                            <h2 style="margin-top:0; font-size:20px; font-weight:600;">
                                Password Reset Request
                            </h2>

                            <p style="font-size:15px; line-height:1.7; color:#4B5563;">
                                Hello <strong>{{ $user->name }}</strong>,
                                <br><br>
                                We received a request to reset the password for your ReUp account.
                                If this was you, click the button below to set a new password.
                            </p>

                            <!-- Button -->
                            <div style="text-align:center; margin:35px 0;">
                                <a href="{{ $resetUrl }}"
                                   style="
                                       background-color:#2AB70D;
                                       color:#ffffff;
                                       text-decoration:none;
                                       padding:14px 40px;
                                       font-size:15px;
                                       font-weight:600;
                                       border-radius:10px;
                                       display:inline-block;
                                   ">
                                    Reset Password
                                </a>

                                <p style="margin-top:14px; font-size:12px; color:#9CA3AF;">
                                    This link will expire in {{ $expireTime }} minutes.
                                </p>

                                <p style="font-size:12px; word-break:break-all;">
                                    <a href="{{ $resetUrl }}" style="color:#2AB70D;">
                                        {{ $resetUrl }}
                                    </a>
                                </p>
                            </div>

                            <!-- Security Notice -->
                            <div style="
                                background-color:#ECFDF5;
                                border:1px solid #A7F3D0;
                                border-radius:10px;
                                padding:18px;
                            ">
                                <strong style="color:#065F46;">
                                    Security Notice
                                </strong>

                                <p style="margin:10px 0 0; font-size:13px; color:#065F46; line-height:1.6;">
                                    • If you did not request this password reset, you can safely ignore this email.<br>
                                    • Never share your reset link with anyone.<br>
                                    • This link automatically expires to protect your account.
                                </p>
                            </div>

                            <!-- Support -->
                            <div style="
                                margin-top:20px;
                                background:#F9FAFB;
                                border:1px solid #E5E7EB;
                                border-radius:10px;
                                padding:18px;
                            ">
                                <p style="margin:0; font-size:13px; color:#374151; line-height:1.6;">
                                    Need help? Contact us at:<br>
                                    <a href="mailto:reup.bellahoptions@gmail.com" style="color:#2AB70D; text-decoration:none;">
                                        reup.bellahoptions@gmail.com
                                    </a>
                                </p>
                            </div>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding:25px; text-align:center; background:#F9FAFB;">
                            <p style="margin:0; font-size:11px; color:#9CA3AF;">
                                © {{ date('Y') }} ReUp. All rights reserved.
                            </p>
                            <p style="margin:6px 0 0; font-size:11px; color:#9CA3AF;">
                                This is an automated message. Please do not reply.
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>
