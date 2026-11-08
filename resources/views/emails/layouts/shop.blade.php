{{--
    Shared layout for every email. Shop name, logo, colour and contacts come
    from the Business profile (Admin → Business), so the same templates serve
    any shop. Inline styles only: Gmail and Outlook ignore most <style> rules.
--}}
@php
    $shop = app(\App\Services\BusinessProfile::class)->all();
    $brand = $shop['brand_color'] ?: '#9b5364';
    $site = rtrim($shop['website'], '/');
    $siteLabel = app(\App\Services\BusinessProfile::class)->websiteLabel();
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>{{ $title ?? $shop['name'] }}</title>
</head>
<body style="margin:0; padding:0; background:#f5f1ee; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#242124;">
@isset($preheader)
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">{{ $preheader }}</div>
@endisset
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f1ee;">
    <tr>
        <td align="center" style="padding:28px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
                <tr>
                    <td align="center" style="padding:0 0 20px;">
                        <a href="{{ $site }}" style="text-decoration:none;">
                            @if ($shop['logo_url'])
                                <img src="{{ $shop['logo_url'] }}" alt="{{ $shop['name'] }}" height="36" style="height:36px; width:auto; border:0; display:block;">
                            @else
                                <span style="font-size:24px; font-weight:800; letter-spacing:2px; color:#242124;">{{ $shop['name'] }}</span>
                            @endif
                        </a>
                    </td>
                </tr>
                <tr>
                    <td style="background:#ffffff; border-radius:14px; padding:32px 28px; border-top:4px solid {{ $brand }};">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 12px 8px; font-size:13px; line-height:1.7; color:#6f6a6a;">
                        <a href="{{ $site }}" style="color:#242124; font-weight:700; text-decoration:none;">{{ $siteLabel ?: $shop['name'] }}</a>
                        @if ($shop['email'])<br><a href="mailto:{{ $shop['email'] }}" style="color:#6f6a6a; text-decoration:none;">{{ $shop['email'] }}</a>@endif
                        @if ($shop['phone'])<br><a href="tel:{{ preg_replace('/[^0-9+]/', '', $shop['phone']) }}" style="color:#6f6a6a; text-decoration:none;">{{ $shop['phone'] }}</a>@endif
                        @if ($shop['phone_2']) &middot; <a href="tel:{{ preg_replace('/[^0-9+]/', '', $shop['phone_2']) }}" style="color:#6f6a6a; text-decoration:none;">{{ $shop['phone_2'] }}</a>@endif
                        @if ($shop['address'] || $shop['city'])<br>{{ trim($shop['address'] . ', ' . $shop['city'], ', ') }}@endif
                        @if ($shop['instagram'] || $shop['facebook'])
                            <br>
                            @if ($shop['instagram'])<a href="{{ str_starts_with($shop['instagram'], 'http') ? $shop['instagram'] : 'https://instagram.com/' . ltrim($shop['instagram'], '@') }}" style="color:{{ $brand }}; text-decoration:none; font-weight:600;">Instagram</a>@endif
                            @if ($shop['instagram'] && $shop['facebook']) &middot; @endif
                            @if ($shop['facebook'])<a href="{{ str_starts_with($shop['facebook'], 'http') ? $shop['facebook'] : 'https://facebook.com/' . $shop['facebook'] }}" style="color:{{ $brand }}; text-decoration:none; font-weight:600;">Facebook</a>@endif
                        @endif
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:4px 12px 24px; font-size:11px; line-height:1.6; color:#9a9393;">
                        {{ __('emails.why_receiving', ['shop' => $shop['name']]) }}
                        @isset($unsubscribeUrl)
                            <br><a href="{{ $unsubscribeUrl }}" style="color:#9a9393;">{{ __('emails.unsubscribe') }}</a>
                        @endisset
                        <br>&copy; {{ date('Y') }} {{ $shop['legal_name'] ?: $shop['name'] }}. {{ __('emails.rights') }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
