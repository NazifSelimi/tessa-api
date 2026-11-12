@php($shop = app(\App\Services\BusinessProfile::class)->all())
{!! $heading !!}

@foreach ($paragraphs as $paragraph)
{!! html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $paragraph))) !!}

@endforeach
@foreach ($products as $product)
- {!! $product['name'] !!}{!! $product['price'] ? ' — ' . $product['price'] : '' !!}: {{ $product['url'] }}
@endforeach
@if ($buttonText && $buttonUrl)

{!! $buttonText !!}: {{ $buttonUrl }}
@endif

--
{!! $shop['name'] !!} · {{ $shop['website'] }}{!! $shop['phone'] ? ' · ' . $shop['phone'] : '' !!}
@if ($unsubscribeUrl)
{{ __('emails.unsubscribe') }}: {{ $unsubscribeUrl }}
@endif
