@php($site = rtrim(app(\App\Services\BusinessProfile::class)->get('website'), '/'))
@extends('emails.layouts.shop', ['title' => 'New business account application', 'preheader' => trim($user->first_name . ' ' . $user->last_name) . ' applied for a business account'])

@section('content')
    <p style="margin:0 0 12px;"><span style="display:inline-block; padding:5px 14px; border-radius:999px; font-size:13px; font-weight:700; background:#fff4e0; color:#a35c00;">Waiting for approval</span></p>
    <h1 style="font-size:22px; margin:0 0 8px;">New business account application</h1>
    <p style="font-size:14px; line-height:1.7; margin:0;">
        <strong>{{ trim($user->first_name . ' ' . $user->last_name) }}</strong><br>
        @if ($user->email)<a href="mailto:{{ $user->email }}" style="color:#242124;">{{ $user->email }}</a><br>@endif
        @if ($user->phone){{ $user->phone }}@endif
    </p>
    @if ($request)
        <p style="font-size:14px; line-height:1.7; margin:16px 0 0;">
            @if ($request->saloon_name)<strong>Business:</strong> {{ $request->saloon_name }}<br>@endif
            @if ($request->saloon_address)<strong>Address:</strong> {{ $request->saloon_address }}<br>@endif
            @if ($request->saloon_city)<strong>City:</strong> {{ $request->saloon_city }}<br>@endif
            @if ($request->saloon_phone)<strong>Phone:</strong> {{ $request->saloon_phone }}@endif
        </p>
    @endif
    @include('emails.partials.button', ['url' => $site . '/admin/stylist-requests', 'label' => 'Review application'])
@endsection
