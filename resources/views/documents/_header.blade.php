<table class="head">
    <tr>
        <td style="width: 55%;">
            @if ($shop->logoDataUri())
                <img src="{{ $shop->logoDataUri() }}" style="max-height: 46px; max-width: 200px;" alt="">
            @else
                <div class="shop-name">{{ $shop->get('name') }}</div>
            @endif
            <div class="small muted" style="margin-top: 4px;">
                @if ($shop->get('legal_name')){{ $shop->get('legal_name') }}<br>@endif
                @if ($shop->addressLine()){{ $shop->addressLine() }}<br>@endif
                @if ($shop->phones()){{ __('documents.phone', [], $locale) }}: {{ $shop->phones() }}<br>@endif
                @if ($shop->get('email')){{ $shop->get('email') }}@endif
                @if ($shop->get('website_label')) · {{ $shop->get('website_label') }}@endif
            </div>
        </td>
        <td class="r">
            <h1>{{ $title }}</h1>
            @isset($subtitle)<div class="muted">{{ $subtitle }}</div>@endisset
            {!! $meta ?? '' !!}
        </td>
    </tr>
</table>
