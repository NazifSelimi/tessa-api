@extends('emails.layouts.shop', ['title' => $heading ?: null, 'preheader' => strip_tags($paragraphs[0] ?? ''), 'unsubscribeUrl' => $unsubscribeUrl])

@section('content')
    @if ($heading)
        <h1 style="font-size:24px; line-height:1.25; margin:0 0 14px; color:#242124;">{{ $heading }}</h1>
    @endif
    @foreach ($paragraphs as $paragraph)
        <p style="font-size:15px; line-height:1.65; margin:0 0 14px;">{!! $paragraph !!}</p>
    @endforeach
    @if ($products)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:12px 0 4px;">
            @foreach (array_chunk($products, 2) as $row)
                <tr>
                    @foreach ($row as $product)
                        <td width="50%" valign="top" style="padding:6px;">
                            <a href="{{ $product['url'] }}" style="display:block; text-decoration:none; color:#242124; border:1px solid #f0ebe8; border-radius:12px; padding:12px; text-align:center;">
                                @if ($product['image'])
                                    <img src="{{ $product['image'] }}" alt="" width="160" style="width:100%; max-width:160px; height:auto; border:0; display:block; margin:0 auto 8px;">
                                @endif
                                <span style="display:block; font-size:14px; font-weight:700; line-height:1.3;">{{ $product['name'] }}</span>
                                @if ($product['price'])
                                    <span style="display:block; font-size:14px; font-weight:800; color:{{ app(\App\Services\BusinessProfile::class)->get('brand_color') ?: '#9b5364' }}; margin-top:4px;">{{ $product['price'] }}</span>
                                @endif
                            </a>
                        </td>
                    @endforeach
                    @if (count($row) === 1)<td width="50%"></td>@endif
                </tr>
            @endforeach
        </table>
    @endif
    @if ($buttonText && $buttonUrl)
        @include('emails.partials.button', ['url' => $buttonUrl, 'label' => $buttonText])
    @endif
@endsection
