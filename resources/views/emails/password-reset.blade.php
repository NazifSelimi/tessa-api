@extends('emails.layouts.shop', ['title' => __('emails.password_reset.title'), 'preheader' => __('emails.password_reset.intro')])

@section('content')
    <h1 style="font-size:24px; margin:0 0 8px; color:#242124;">{{ __('emails.password_reset.title') }}</h1>
    <p style="font-size:15px; line-height:1.6; margin:0 0 4px;">{{ $user->first_name ? __('emails.hi', ['name' => $user->first_name]) : __('emails.hi_plain') }}</p>
    <p style="font-size:15px; line-height:1.6; margin:0;">{{ __('emails.password_reset.intro') }}</p>
    @include('emails.partials.button', ['url' => $resetUrl, 'label' => __('emails.password_reset.button')])
    <p style="font-size:13px; line-height:1.6; margin:20px 0 0; color:#6f6a6a;">{{ __('emails.password_reset.expires') }}</p>
@endsection
