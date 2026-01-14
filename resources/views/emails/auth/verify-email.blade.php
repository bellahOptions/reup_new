<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Your Email – ReUp</title>
</head>

<body style="margin:0; padding:0; background-color:#F3F4F6; font-family:Segoe UI, Tahoma, Geneva, Verdana, sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td align="center" style="padding:40px 15px;">

            <!-- Main Container -->
            <table width="600" cellpadding="0" cellspacing="0"
                   style="background:#ffffff; border-radius:14px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.08);">

                <!-- Header -->
                <tr>
                    <td align="center" style="padding:35px 30px 25px;">
                        <img src="https://i.postimg.cc/VNMvqyqc/12.png"
                             alt="ReUp Logo"
                             style="height:52px; width:auto; display:block;">

                        <h2 style="margin:12px 0 4px; color:#10B981; font-weight:700;">
                            ReUp
                        </h2>

                        <p style="margin:0; font-size:12px; color:#6B7280;">
                            Digital Services & Payments
                        </p>
                    </td>
                </tr>

                <!-- Body -->
                <tr>
                    <td style="padding:0 30px 25px;">
                        <h3 style="margin:0; color:#111827; font-size:20px;">
                            Verify your email address
                        </h3>

                        <p style="margin-top:12px; font-size:15px; color:#4B5563; line-height:1.7;">
                            Hi {{ $user->name }},<br><br>
                            Welcome to ReUp! To complete your registration and secure your account,
                            please confirm that this email address belongs to you.
                        </p>
                    </td>
                </tr>

                <!-- CTA Button -->
                <tr>
                    <td align="center" style="padding:10px 30px 30px;">
                        <a href="{{ $verificationUrl }}"
                           style="background:#10B981; color:#ffffff; text-decoration:none;
                                  padding:14px 36px; border-radius:10px;
                                  font-size:15px; font-weight:600; display:inline-block;">
                            Verify Email Address
                        </a>

                        <p style="margin-top:14px; font-size:12px; color:#9CA3AF;">
                            This link expires in {{ $expireTime }} minutes.
                        </p>

                        <p style="margin-top:8px; font-size:12px;">
                            <a href="{{ $verificationUrl }}"
                               style="color:#10B981; word-break:break-all;">
                                {{ $verificationUrl }}
                            </a>
                        </p>
                    </td>
                </tr>

                <!-- Security Notice -->
                <tr>
                    <td style="padding:0 30px 30px;">
                        <div style="background:#ECFDF5; border:1px solid #A7F3D0;
                                    border-radius:10px; padding:18px;">
                            <strong style="color:#065F46;">Security Notice</strong>

                            <p style="margin-top:8px; font-size:13px; color:#065F46; line-height:1.6;">
                                • Do not share this verification link with anyone<br>
                                • If you didn’t create an account, you can safely ignore this email
                            </p>
                        </div>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td align="center" style="background:#F9FAFB; padding:25px;">
                        <p style="margin:0; font-size:12px; color:#6B7280;">
                            ReUp Digital Services
                        </p>

                        <p style="margin:6px 0 0; font-size:11px; color:#9CA3AF;">
                            reup.bellahoptions@gmail.com
                        </p>

                        <p style="margin-top:12px; font-size:10px; color:#9CA3AF;">
                            © {{ date('Y') }} ReUp. All rights reserved.
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
