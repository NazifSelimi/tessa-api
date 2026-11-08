@php
    $info = $order->info;
    $buyer = trim(($info?->first_name ?? $order->user?->first_name ?? '') . ' ' . ($info?->last_name ?? $order->user?->last_name ?? ''));
    $email = $info?->email ?: $order->user?->email;
    $phone = $info?->phone ?: $order->user?->phone;
    $site = rtrim(app(\App\Services\BusinessProfile::class)->get('website'), '/');
@endphp
@extends('emails.layouts.shop', ['title' => 'New order #' . $order->id, 'preheader' => 'New order #' . $order->id . ' from ' . ($buyer ?: 'a guest')])

@section('content')
    <p style="margin:0 0 12px;"><span style="display:inline-block; padding:5px 14px; border-radius:999px; font-size:13px; font-weight:700; background:#fff4e0; color:#a35c00;">New order &middot; waiting for confirmation</span></p>
    <h1 style="font-size:24px; margin:0 0 6px;">Order #{{ $order->id }}</h1>
    <p style="font-size:14px; color:#6f6a6a; margin:0;">{{ $order->created_at?->format('j M Y, H:i') }}</p>
    <h2 style="font-size:13px; letter-spacing:1px; text-transform:uppercase; color:#8a7f7b; margin:24px 0 8px;">Customer</h2>
    <p style="font-size:14px; line-height:1.7; margin:0;">
        <strong>{{ $buyer ?: 'Guest' }}</strong>{{ $order->user_id ? '' : ' (guest checkout)' }}<br>
        @if ($email)<a href="mailto:{{ $email }}" style="color:#242124;">{{ $email }}</a><br>@endif
        @if ($phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" style="color:#242124;">{{ $phone }}</a>@endif
    </p>
    @if ($order->referral_stylist_id)
        <p style="font-size:13px; margin:12px 0 0; color:#9b5364;">Referral code {{ $order->coupon_code }} used.</p>
    @endif
    @include('emails.partials.order-summary', ['order' => $order])
    @include('emails.partials.button', ['url' => $site . '/admin/orders?status=pending', 'label' => 'Open orders'])
@endsection
