<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Stylist Application</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f4f4f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        .wrapper { max-width: 600px; margin: 0 auto; padding: 24px 16px; }
        .card { background: #ffffff; border-radius: 8px; padding: 32px; }
        .header { padding-bottom: 24px; border-bottom: 1px solid #eaeaea; margin-bottom: 24px; }
        .header h1 { margin: 0; font-size: 22px; color: #1a1a1a; }
        .badge { display: inline-block; background: #dbeafe; color: #1d4ed8; padding: 4px 12px; border-radius: 4px; font-size: 13px; font-weight: 600; }
        .section { margin-bottom: 24px; }
        .section h2 { font-size: 14px; color: #555; margin: 0 0 12px 0; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-grid { font-size: 14px; color: #333; line-height: 1.6; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="header">
            <h1>New Stylist Application</h1>
            <p style="margin: 8px 0 0;">
                <span class="badge">Pending Review</span>
            </p>
        </div>

        <div class="section">
            <h2>Applicant</h2>
            <div class="info-grid">
                {{ $user->first_name }} {{ $user->last_name }}<br>
                {{ $user->email }}<br>
                @if ($user->phone)
                    {{ $user->phone }}
                @endif
            </div>
        </div>

        <div class="section">
            <h2>Salon Details</h2>
            <div class="info-grid">
                <strong>Salon Name:</strong> {{ $request->saloon_name }}<br>
                <strong>Address:</strong> {{ $request->saloon_address }}<br>
                <strong>City:</strong> {{ $request->saloon_city }}<br>
                <strong>Phone:</strong> {{ $request->saloon_phone }}
            </div>
        </div>

        @if ($request->message)
            <div class="section">
                <h2>Application Message</h2>
                <p style="font-size:14px; color:#333;">{{ $request->message }}</p>
            </div>
        @endif
    </div>

    @include('emails.partials.footer')
</div>
</body>
</html>

