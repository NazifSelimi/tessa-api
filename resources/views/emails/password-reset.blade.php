<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f4f4f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        .wrapper { max-width: 600px; margin: 0 auto; padding: 24px 16px; }
        .card { background: #ffffff; border-radius: 8px; padding: 32px; }
        .header { text-align: center; padding-bottom: 24px; border-bottom: 1px solid #eaeaea; margin-bottom: 24px; }
        .header h1 { margin: 0; font-size: 24px; color: #1a1a1a; }
        .btn { display: inline-block; background-color: #1a1a1a; color: #ffffff; padding: 14px 32px; border-radius: 6px; text-decoration: none; font-size: 16px; font-weight: 600; margin: 16px 0; }
        .note { font-size: 13px; color: #888; margin-top: 16px; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="header">
            <h1>Reset Your Password</h1>
        </div>

        <p style="font-size:15px; color:#333;">
            Hi{{ $user->first_name ? ' ' . $user->first_name : '' }},
        </p>

        <p style="font-size:15px; color:#333;">
            We received a request to reset your password. Click the button below to choose a new password:
        </p>

        <div style="text-align:center;">
            <a href="{{ $resetUrl }}" class="btn">Reset Password</a>
        </div>

        <p class="note">
            This link will expire in 60 minutes. If you didn't request a password reset, you can safely ignore this email.
        </p>

        <p class="note">
            If the button doesn't work, copy and paste this URL into your browser:<br>
            <span style="word-break:break-all; color:#555;">{{ $resetUrl }}</span>
        </p>
    </div>

    @include('emails.partials.footer')
</div>
</body>
</html>
