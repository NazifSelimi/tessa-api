<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stylist Application {{ $statusLabel }}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f4f4f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        .wrapper { max-width: 600px; margin: 0 auto; padding: 24px 16px; }
        .card { background: #ffffff; border-radius: 8px; padding: 32px; }
        .header { text-align: center; padding-bottom: 24px; border-bottom: 1px solid #eaeaea; margin-bottom: 24px; }
        .header h1 { margin: 0; font-size: 24px; color: #1a1a1a; }
        .btn { display: inline-block; background-color: #1a1a1a; color: #ffffff; padding: 12px 28px; border-radius: 6px; text-decoration: none; font-size: 15px; font-weight: 600; margin: 16px 0; }
        .note { font-size: 13px; color: #888; margin-top: 16px; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="header">
            @if (strtolower($statusLabel) === 'approved')
                <h1>Your Stylist Application Is Approved</h1>
            @else
                <h1>Update on Your Stylist Application</h1>
            @endif
        </div>

        <p style="font-size:15px; color:#333;">
            Hi{{ $user->first_name ? ' ' . $user->first_name : '' }},
        </p>

        @if (strtolower($statusLabel) === 'approved')
            <p style="font-size:15px; color:#333;">
                Great news — your application to become a Tessa stylist has been approved. You now have access to stylist pricing and professional-only features on our store.
            </p>

            @if ($request)
                <p style="font-size:15px; color:#333;">
                    We have set up your stylist profile using the following salon details:
                </p>
                <p style="font-size:14px; color:#333; line-height:1.6;">
                    <strong>Salon Name:</strong> {{ $request->saloon_name }}<br>
                    <strong>Address:</strong> {{ $request->saloon_address }}<br>
                    <strong>City:</strong> {{ $request->saloon_city }}<br>
                    <strong>Phone:</strong> {{ $request->saloon_phone }}
                </p>
            @endif

            <div style="text-align:center;">
                <a href="{{ rtrim($frontendUrl, '/') }}/stylist/quick-order" class="btn">
                    Start Shopping with Stylist Pricing
                </a>
            </div>

            <p class="note">
                If the button doesn't work, copy and paste this URL into your browser:<br>
                <span style="word-break:break-all; color:#555;">{{ rtrim($frontendUrl, '/') }}/stylist/quick-order</span>
            </p>
        @else
            <p style="font-size:15px; color:#333;">
                Thank you for your interest in becoming a Tessa stylist and for taking the time to apply.
            </p>

            <p style="font-size:15px; color:#333;">
                After reviewing your application, we’re not able to approve it at this time.
            </p>

            @if ($reason)
                <p style="font-size:15px; color:#333;">
                    <strong>Reason provided:</strong><br>
                    {{ $reason }}
                </p>
            @endif

            <p style="font-size:15px; color:#333;">
                You are still welcome to shop as a regular customer, and you’re always free to apply again in the future if your circumstances change.
            </p>

            <p class="note">
                You can continue shopping at:<br>
                <span style="word-break:break-all; color:#555;">{{ rtrim($frontendUrl, '/') }}</span>
            </p>
        @endif
    </div>

    @include('emails.partials.footer')
</div>
</body>
</html>

