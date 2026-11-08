@php
    $key = strtolower($statusLabel);
    $key = in_array($key, ['submitted', 'approved', 'rejected'], true) ? $key : 'rejected';
    $site = rtrim(app(\App\Services\BusinessProfile::class)->get('website'), '/');
@endphp
@extends('emails.layouts.shop', ['title' => __('emails.stylist.title_' . $key), 'preheader' => __('emails.stylist.' . $key)])

@section('content')
    <h1 style="font-size:24px; margin:0 0 8px; color:#242124;">{{ __('emails.stylist.title_' . $key) }}</h1>
    <p style="font-size:15px; line-height:1.6; margin:0 0 4px;">{{ $user->first_name ? __('emails.hi', ['name' => $user->first_name]) : __('emails.hi_plain') }}</p>
    <p style="font-size:15px; line-height:1.6; margin:0;">{{ __('emails.stylist.' . $key) }}</p>
    @if ($key === 'rejected' && $reason)
        <p style="font-size:14px; line-height:1.6; margin:16px 0 0; padding:12px 14px; background:#faf6f4; border-radius:10px;"><strong>{{ __('emails.stylist.reason') }}:</strong> {{ $reason }}</p>
    @endif
    @if ($request && $key !== 'rejected')
        <h2 style="font-size:13px; letter-spacing:1px; text-transform:uppercase; color:#8a7f7b; margin:24px 0 8px;">{{ __('emails.stylist.salon_details') }}</h2>
        <p style="font-size:14px; line-height:1.7; margin:0;">
            @if ($request->saloon_name)<strong>{{ __('emails.stylist.salon') }}:</strong> {{ $request->saloon_name }}<br>@endif
            @if ($request->saloon_address)<strong>{{ __('emails.stylist.address') }}:</strong> {{ $request->saloon_address }}<br>@endif
            @if ($request->saloon_city)<strong>{{ __('emails.stylist.city') }}:</strong> {{ $request->saloon_city }}<br>@endif
            @if ($request->saloon_phone)<strong>{{ __('emails.phone') }}:</strong> {{ $request->saloon_phone }}@endif
        </p>
    @endif
    @if ($key === 'approved')
        @include('emails.partials.button', ['url' => $site . '/stylist/quick-order', 'label' => __('emails.stylist.button')])
    @endif
@endsection
