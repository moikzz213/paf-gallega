<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #047857; color: #fff; padding: 20px; border-radius: 4px 4px 0 0; }
        .body { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .footer { background-color: #eee; padding: 15px; border-radius: 0 0 4px 4px; font-size: 12px; color: #666; }
        .btn { display: inline-block; padding: 12px 24px; background-color: #047857; color: #fff; text-decoration: none; border-radius: 4px; margin-top: 15px; }
        .fallback { margin-top: 20px; font-size: 12px; color: #666; word-break: break-all; }
        .notice { margin-top: 20px; padding: 12px; border-left: 3px solid #047857; background-color: #f0f0f0; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin:0;">Reset your password</h2>
        </div>
        <div class="body">
            <p>Hello {{ $user->name }},</p>

            <p>We received a request to reset the password for your Invoice Payment Approval
               Platform account. Click the button below to choose a new one.</p>

            <a href="{{ $resetUrl }}" class="btn">Reset Password</a>

            <div class="notice">
                This link expires in {{ $expiresInMinutes }} minutes and can only be used once.
                If you did not request a password reset, you can safely ignore this email —
                your password will stay as it is.
            </div>

            <div class="fallback">
                If the button does not work, copy this address into your browser:<br>
                {{ $resetUrl }}
            </div>
        </div>
        <div class="footer">
            This is an automated notification from the Invoice Payment Approval Platform.
        </div>
    </div>
</body>
</html>
